<?php
/**
 * Authoring API — the curriculum as seen by whoever is building it.
 *
 * The participant routes in rest.php answer "what may I study": published
 * content only, shaped for reading, gated on enrolment. An author needs the
 * opposite of most of that — drafts included, structure over prose, gated on
 * being allowed to edit rather than to attend.
 *
 * Rather than have the builder drive /wp/v2/pcle_* directly:
 *
 *   - the whole tree is one request instead of seven collection fetches and a
 *     client-side join on meta;
 *   - a reorder or a duplicate can be one atomic call rather than N
 *     untransacted writes that can half-apply;
 *   - and per-programme permission is decided in one guard instead of once per
 *     generic route. This project already learned that lesson the expensive
 *     way: a guard written for one route is not a guard on another.
 *
 * This file is the read half. Writes follow, behind the same guards.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which child types a node of this type may contain, in display order.
 *
 * Derived from pcle_relationship_map() rather than restated, so the builder's
 * "add" menu and its drop rules stay in step with what the server will
 * actually accept.
 *
 * @param string $post_type Parent post type.
 * @return string[]
 */
function pcle_allowed_child_types( $post_type ) {
	$children = array();

	foreach ( pcle_relationship_map() as $child => $rel ) {
		if ( $rel['parent'] === $post_type ) {
			$children[] = $child;
		}
	}

	return $children;
}

/**
 * May this user edit this programme?
 *
 * Today: any member of teaching staff. Per-programme assignment is a later,
 * separate change — it is the one most likely to lock a real person out
 * mid-cohort, so it ships on its own with a migration behind it.
 *
 * Everything in this file already asks through this function, so that change
 * lands in one place.
 *
 * @param int $program_id Programme ID.
 * @param int $user_id    User (defaults to the current one).
 * @return bool
 */
function pcle_user_can_edit_program( $program_id, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id || 'pcle_program' !== get_post_type( $program_id ) ) {
		return false;
	}

	return pcle_user_is_staff( $user_id );
}

/**
 * Permission callback for routes that name an existing node.
 *
 * Mirrors pcle_rest_guard_item(): anonymous 401, unknown or wrong-typed 404,
 * not permitted 403. Unlike that guard it accepts any post status — an author
 * works on drafts by definition.
 *
 * $expected_type is not optional decoration. A route that wants a programme
 * must say so, or an id of any other authorable type sails through the
 * permission check and gets shaped by a callback that assumed otherwise.
 *
 * @param WP_REST_Request $request       Request.
 * @param string          $expected_type Post type the route serves, or '' for any authorable type.
 * @return true|WP_Error
 */
function pcle_authoring_guard_node( $request, $expected_type = '' ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
	}

	$id   = (int) $request['id'];
	$type = get_post_type( $id );

	if ( ! $type || ! in_array( $type, pcle_authorable_post_types(), true ) ) {
		return new WP_Error( 'pcle_not_found', __( 'Not found.', 'platform-cle' ), array( 'status' => 404 ) );
	}

	if ( '' !== $expected_type && $type !== $expected_type ) {
		return new WP_Error( 'pcle_not_found', __( 'Not found.', 'platform-cle' ), array( 'status' => 404 ) );
	}

	$program_id = pcle_get_program_for_post( $id );

	if ( ! $program_id || ! pcle_user_can_edit_program( $program_id ) ) {
		return new WP_Error( 'pcle_cannot_edit', __( 'You may not edit this programme.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * The post types the builder manages.
 *
 * Case updates are excluded on purpose: they hang off no programme, so
 * per-programme permission has nothing to say about them and they keep their
 * own capability group.
 *
 * @return string[]
 */
function pcle_authorable_post_types() {
	return array( 'pcle_program', 'pcle_week', 'pcle_module', 'pcle_scenario', 'pcle_template', 'pcle_event' );
}

/* =========================================================================
 * Shaping
 * ========================================================================= */

/**
 * Shapes one node for the tree.
 *
 * Structure, not prose: enough to render a row and decide what may be dropped
 * on it. The body is fetched per node when the author opens it, so loading a
 * programme does not ship every module's content.
 *
 * @param WP_Post $post Post.
 * @return array
 */
function pcle_authoring_shape_node( $post ) {
	$node = array(
		'id'              => (int) $post->ID,
		'type'            => $post->post_type,
		'title'           => $post->post_title,
		'status'          => $post->post_status,
		'menu_order'      => (int) $post->menu_order,
		'allowed_children' => pcle_allowed_child_types( $post->post_type ),
		'children'        => array(),
	);

	// Type-specific facts the tree needs to show a row honestly.
	if ( 'pcle_event' === $post->post_type ) {
		$node['starts_at'] = pcle_get_event_datetime( $post->ID );
	}

	if ( 'pcle_scenario' === $post->post_type ) {
		$node['has_model_answer'] = false !== strpos( $post->post_content, '[pcle_model_answer' );
	}

	if ( 'pcle_program' === $post->post_type ) {
		$node['credits'] = pcle_rest_shape_credit_hours( $post->ID );
	}

	return $node;
}

/**
 * Every child of a node, any status, in curriculum order.
 *
 * pcle_get_children() defaults to published only — right for participants,
 * wrong here, since a draft is precisely what an author is working on.
 *
 * @param int    $parent_id  Parent post.
 * @param string $child_type Child post type.
 * @return WP_Post[]
 */
function pcle_authoring_get_children( $parent_id, $child_type ) {
	$map = pcle_relationship_map();

	if ( ! isset( $map[ $child_type ] ) ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'      => $child_type,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'meta_key'       => $map[ $child_type ]['meta_key'],
			'meta_value'     => (int) $parent_id,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);
}

/**
 * Builds the tree under a node, recursively.
 *
 * @param WP_Post $post Root post.
 * @return array
 */
function pcle_authoring_build_tree( $post ) {
	$node = pcle_authoring_shape_node( $post );

	foreach ( pcle_allowed_child_types( $post->post_type ) as $child_type ) {
		foreach ( pcle_authoring_get_children( $post->ID, $child_type ) as $child ) {
			$node['children'][] = pcle_authoring_build_tree( $child );
		}
	}

	return $node;
}

/* =========================================================================
 * Routes
 * ========================================================================= */

/**
 * Registers the authoring routes.
 */
function pcle_register_authoring_routes() {
	register_rest_route(
		'platform-cle/v1',
		'/me',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_get_me',
			'permission_callback' => 'is_user_logged_in',
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/authoring/programs',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_authoring_list_programs',
			'permission_callback' => function () {
				return is_user_logged_in() && pcle_user_is_staff();
			},
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/authoring/programs/(?P<id>\d+)/tree',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_authoring_get_tree',
			'permission_callback' => function ( $request ) {
				return pcle_authoring_guard_node( $request, 'pcle_program' );
			},
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_authoring_routes' );

/**
 * GET /me — who am I and what may I do.
 *
 * The app has no notion of an instructor today; every screen assumes a
 * participant. This is what lets it show a way into the builder to the people
 * who have one, and nobody else.
 *
 * @return WP_REST_Response
 */
function pcle_rest_get_me() {
	$user = wp_get_current_user();

	return rest_ensure_response(
		array(
			'id'           => (int) $user->ID,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
			'can_author'   => pcle_user_is_staff( $user->ID ),
			'is_admin'     => user_can( $user->ID, 'manage_options' ),
		)
	);
}

/**
 * GET /authoring/programs — the programmes this author may work on.
 *
 * @return WP_REST_Response
 */
function pcle_authoring_list_programs() {
	$programs = get_posts(
		array(
			'post_type'      => 'pcle_program',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);

	$out = array();

	foreach ( $programs as $program ) {
		if ( ! pcle_user_can_edit_program( $program->ID ) ) {
			continue;
		}

		$out[] = array(
			'id'         => (int) $program->ID,
			'title'      => $program->post_title,
			'status'     => $program->post_status,
			'credits'    => pcle_rest_shape_credit_hours( $program->ID ),
			'weeks'      => count( pcle_authoring_get_children( $program->ID, 'pcle_week' ) ),
			'modules'    => count( pcle_get_program_module_ids( $program->ID ) ),
			'enrollees'  => count( pcle_get_program_enrollee_ids( $program->ID ) ),
		);
	}

	return rest_ensure_response( array( 'programs' => $out ) );
}

/**
 * GET /authoring/programs/<id>/tree — the whole programme in one request.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_authoring_get_tree( $request ) {
	$program = get_post( (int) $request['id'] );

	return rest_ensure_response( pcle_authoring_build_tree( $program ) );
}
