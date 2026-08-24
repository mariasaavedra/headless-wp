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
	return array( 'pcle_program', 'pcle_unit', 'pcle_module', 'pcle_scenario', 'pcle_template', 'pcle_event' );
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

	register_rest_route(
		'platform-cle/v1',
		'/authoring/nodes',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_authoring_create_node',
			'permission_callback' => 'pcle_authoring_guard_create',
			'args'                => array(
				'type'      => array(
					'required' => true,
					'type'     => 'string',
				),
				'parent_id' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'title'     => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/authoring/nodes/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'pcle_authoring_get_node',
				'permission_callback' => 'pcle_authoring_guard_node',
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => 'pcle_authoring_update_node',
				'permission_callback' => 'pcle_authoring_guard_node',
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'pcle_authoring_delete_node',
				'permission_callback' => 'pcle_authoring_guard_node',
				'args'                => array(
					'cascade' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/authoring/reorder',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_authoring_reorder',
			'permission_callback' => 'pcle_authoring_guard_reorder',
			'args'                => array(
				'parent_id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'child_type' => array(
					'required' => true,
					'type'     => 'string',
				),
				'ids'        => array(
					'required' => true,
					'type'     => 'array',
				),
			),
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/authoring/move',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_authoring_move',
			'permission_callback' => 'pcle_authoring_guard_move',
			'args'                => array(
				'id'        => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'parent_id' => array(
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
			'units'      => count( pcle_authoring_get_children( $program->ID, 'pcle_unit' ) ),
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

/* =========================================================================
 * Writes
 * ========================================================================= */

/**
 * Permission callback for creating a node.
 *
 * Creation cannot use pcle_authoring_guard_node(): the post does not exist
 * yet, so pcle_get_program_for_post() has nothing to walk up from. The
 * decision has to be made against the parent named in the request.
 *
 * A programme has no parent, so creating one is gated on being staff.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function pcle_authoring_guard_create( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
	}

	$type = (string) $request['type'];

	if ( ! in_array( $type, pcle_authorable_post_types(), true ) ) {
		return new WP_Error( 'pcle_invalid_type', __( 'That is not something you can create here.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	if ( 'pcle_program' === $type ) {
		return pcle_user_is_staff()
			? true
			: new WP_Error( 'pcle_cannot_edit', __( 'You may not create programmes.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	$parent_id = (int) $request['parent_id'];
	$map       = pcle_relationship_map();

	if ( ! $parent_id || get_post_type( $parent_id ) !== $map[ $type ]['parent'] ) {
		return new WP_Error(
			'pcle_invalid_parent',
			/* translators: %s: parent post type. */
			sprintf( __( 'A %s must be created inside its parent.', 'platform-cle' ), $type ),
			array( 'status' => 400 )
		);
	}

	$program_id = pcle_get_program_for_post( $parent_id );

	if ( ! $program_id || ! pcle_user_can_edit_program( $program_id ) ) {
		return new WP_Error( 'pcle_cannot_edit', __( 'You may not edit this programme.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * The next free position among a parent's children of one type.
 *
 * @param int    $parent_id  Parent post.
 * @param string $child_type Child post type.
 * @return int
 */
function pcle_authoring_next_order( $parent_id, $child_type ) {
	$siblings = pcle_authoring_get_children( $parent_id, $child_type );

	$highest = 0;
	foreach ( $siblings as $sibling ) {
		$highest = max( $highest, (int) $sibling->menu_order );
	}

	return $highest + 1;
}

/**
 * POST /authoring/nodes — create one item inside its parent.
 *
 * Created as a draft. Publishing is a separate, deliberate act; a half-built
 * unit appearing to participants the moment it is named would be the wrong
 * default.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_authoring_create_node( $request ) {
	$type      = (string) $request['type'];
	$parent_id = (int) $request['parent_id'];
	$title     = sanitize_text_field( (string) $request['title'] );

	$post_id = wp_insert_post(
		array(
			'post_type'   => $type,
			'post_title'  => '' !== $title ? $title : __( '(untitled)', 'platform-cle' ),
			'post_status' => 'draft',
			'menu_order'  => $parent_id ? pcle_authoring_next_order( $parent_id, $type ) : 0,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'pcle_create_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	if ( $parent_id ) {
		$map = pcle_relationship_map();
		update_post_meta( $post_id, $map[ $type ]['meta_key'], $parent_id );
	}

	return rest_ensure_response( pcle_authoring_shape_node( get_post( $post_id ) ) );
}

/**
 * PATCH /authoring/nodes/<id> — update one item.
 *
 * Only the fields present in the request are touched, so a client editing a
 * title cannot blank a body it never loaded.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_authoring_update_node( $request ) {
	$id      = (int) $request['id'];
	$changes = array( 'ID' => $id );

	if ( null !== $request['title'] ) {
		$changes['post_title'] = sanitize_text_field( (string) $request['title'] );
	}

	if ( null !== $request['excerpt'] ) {
		$changes['post_excerpt'] = sanitize_textarea_field( (string) $request['excerpt'] );
	}

	/*
	 * `body` is the builder's field: plain authored text, which the server
	 * turns into block markup. The client never sends HTML.
	 *
	 * `content` remains for callers that legitimately hold markup already —
	 * it is still sanitised, because instructors do not hold unfiltered_html
	 * and relying on whichever kses filters happen to be installed for a REST
	 * request is not something to assume.
	 */
	if ( null !== $request['body'] ) {
		$changes['post_content'] = pcle_authoring_content_from_text( (string) $request['body'] );
	} elseif ( null !== $request['content'] ) {
		$changes['post_content'] = wp_kses_post( (string) $request['content'] );
	}

	if ( null !== $request['status'] ) {
		$status = (string) $request['status'];

		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			return new WP_Error( 'pcle_invalid_status', __( 'Unknown status.', 'platform-cle' ), array( 'status' => 400 ) );
		}

		$changes['post_status'] = $status;
	}

	if ( count( $changes ) > 1 ) {
		$updated = wp_update_post( $changes, true );

		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'pcle_update_failed', $updated->get_error_message(), array( 'status' => 500 ) );
		}
	}

	// Type-specific fields, each validated by the sanitiser that already owns it.
	if ( null !== $request['starts_at'] && 'pcle_event' === get_post_type( $id ) ) {
		update_post_meta( $id, PCLE_EVENT_DATETIME_META, pcle_sanitize_event_datetime( (string) $request['starts_at'] ) );
	}

	if ( null !== $request['credits'] && 'pcle_program' === get_post_type( $id ) ) {
		foreach ( (array) $request['credits'] as $code => $hours ) {
			if ( ! isset( pcle_jurisdictions()[ $code ] ) ) {
				continue;
			}

			$sanitized = pcle_sanitize_credit_hours( $hours );

			if ( $sanitized > 0 ) {
				update_post_meta( $id, pcle_credit_hours_meta_key( $code ), $sanitized );
			} else {
				delete_post_meta( $id, pcle_credit_hours_meta_key( $code ) );
			}
		}
	}

	return rest_ensure_response( pcle_authoring_shape_node( get_post( $id ) ) );
}

/**
 * Every descendant of a node, depth-first.
 *
 * @param int $post_id Root post.
 * @return WP_Post[]
 */
function pcle_authoring_descendants( $post_id ) {
	$found = array();

	foreach ( pcle_allowed_child_types( get_post_type( $post_id ) ) as $child_type ) {
		foreach ( pcle_authoring_get_children( $post_id, $child_type ) as $child ) {
			$found[] = $child;
			$found   = array_merge( $found, pcle_authoring_descendants( $child->ID ) );
		}
	}

	return $found;
}

/**
 * DELETE /authoring/nodes/<id> — remove an item, and optionally what hangs off it.
 *
 * Refuses with the list of descendants unless the caller asked for a cascade.
 * Deleting a unit silently takes its modules, scenarios, templates and
 * sessions with it, and "I did not realise" is not a recoverable state.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_authoring_delete_node( $request ) {
	$id          = (int) $request['id'];
	$cascade     = (bool) $request['cascade'];
	$descendants = pcle_authoring_descendants( $id );

	if ( $descendants && ! $cascade ) {
		return new WP_Error(
			'pcle_has_descendants',
			__( 'This item still contains other items.', 'platform-cle' ),
			array(
				'status'      => 409,
				'descendants' => array_map( 'pcle_authoring_shape_node', $descendants ),
			)
		);
	}

	foreach ( $descendants as $descendant ) {
		wp_delete_post( $descendant->ID, true );
	}

	wp_delete_post( $id, true );

	return rest_ensure_response(
		array(
			'deleted' => $id,
			'also'    => count( $descendants ),
		)
	);
}

/**
 * Checks that every id really is a child of that parent, of that type.
 *
 * @param int    $parent_id  Parent post.
 * @param string $child_type Child post type.
 * @param int[]  $ids        Proposed ordering.
 * @return true|WP_Error
 */
function pcle_authoring_validate_sibling_set( $parent_id, $child_type, $ids ) {
	$map = pcle_relationship_map();

	if ( ! isset( $map[ $child_type ] ) ) {
		return new WP_Error( 'pcle_invalid_type', __( 'Unknown item type.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	$actual = wp_list_pluck( pcle_authoring_get_children( $parent_id, $child_type ), 'ID' );
	$actual = array_map( 'intval', $actual );

	foreach ( $ids as $id ) {
		if ( ! in_array( (int) $id, $actual, true ) ) {
			return new WP_Error(
				'pcle_not_a_sibling',
				__( 'That list contains an item that does not belong here.', 'platform-cle' ),
				array( 'status' => 400 )
			);
		}
	}

	if ( count( $ids ) !== count( $actual ) ) {
		return new WP_Error(
			'pcle_incomplete_order',
			__( 'Reordering needs the whole list, not part of it.', 'platform-cle' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * POST /authoring/reorder — renumber a whole sibling list in one call.
 *
 * Takes the complete list rather than a moved id and a position, because the
 * whole list is what changed and sending it entire is what makes this
 * idempotent and safe to retry.
 *
 * Validation happens before anything is written: a reorder that half-applies
 * leaves the curriculum in an order nobody chose, which is worse than one
 * that refuses.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_authoring_reorder( $request ) {
	$parent_id  = (int) $request['parent_id'];
	$child_type = (string) $request['child_type'];
	$ids        = array_map( 'absint', (array) $request['ids'] );

	$valid = pcle_authoring_validate_sibling_set( $parent_id, $child_type, $ids );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	foreach ( $ids as $position => $id ) {
		wp_update_post(
			array(
				'ID'         => $id,
				'menu_order' => $position + 1,
			)
		);
	}

	return rest_ensure_response(
		array(
			'parent_id' => $parent_id,
			'ids'       => wp_list_pluck( pcle_authoring_get_children( $parent_id, $child_type ), 'ID' ),
		)
	);
}

/**
 * Permission callback for reorder.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function pcle_authoring_guard_reorder( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
	}

	$program_id = pcle_get_program_for_post( (int) $request['parent_id'] );

	if ( ! $program_id || ! pcle_user_can_edit_program( $program_id ) ) {
		return new WP_Error( 'pcle_cannot_edit', __( 'You may not edit this programme.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * POST /authoring/move — reparent an item, and place it among its new siblings.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_authoring_move( $request ) {
	$id        = (int) $request['id'];
	$parent_id = (int) $request['parent_id'];
	$type      = get_post_type( $id );
	$map       = pcle_relationship_map();

	if ( ! isset( $map[ $type ] ) || get_post_type( $parent_id ) !== $map[ $type ]['parent'] ) {
		return new WP_Error(
			'pcle_invalid_parent',
			__( 'That item cannot live there.', 'platform-cle' ),
			array( 'status' => 400 )
		);
	}

	update_post_meta( $id, $map[ $type ]['meta_key'], $parent_id );
	wp_update_post(
		array(
			'ID'         => $id,
			'menu_order' => pcle_authoring_next_order( $parent_id, $type ),
		)
	);

	// An explicit ordering for the destination is optional; without it the
	// item simply lands at the end.
	if ( null !== $request['ids'] ) {
		$ids   = array_map( 'absint', (array) $request['ids'] );
		$valid = pcle_authoring_validate_sibling_set( $parent_id, $type, $ids );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		foreach ( $ids as $position => $sibling ) {
			wp_update_post(
				array(
					'ID'         => $sibling,
					'menu_order' => $position + 1,
				)
			);
		}
	}

	return rest_ensure_response( pcle_authoring_shape_node( get_post( $id ) ) );
}

/**
 * Permission callback for move.
 *
 * Authorises against BOTH ends. Checking only the item being moved would let
 * someone with rights over one programme drop content into another they have
 * no business touching.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function pcle_authoring_guard_move( $request ) {
	$source = pcle_authoring_guard_node( $request );

	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$destination_program = pcle_get_program_for_post( (int) $request['parent_id'] );

	if ( ! $destination_program || ! pcle_user_can_edit_program( $destination_program ) ) {
		return new WP_Error( 'pcle_cannot_edit', __( 'You may not edit the destination programme.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * GET /authoring/nodes/<id> — one item, with its body in editable form.
 *
 * The tree deliberately does not carry bodies; loading a programme would
 * otherwise ship every module's prose to draw a list of titles. This is what
 * the editor screen asks for when an author opens one thing.
 *
 * `editable` false means the stored content contains something the builder
 * cannot express — a block from the WordPress inserter, or pre-block HTML. The
 * caller must then show it read-only: re-serialising content we could not
 * fully parse would destroy an author's work while looking like a save.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_authoring_get_node( $request ) {
	$post = get_post( (int) $request['id'] );
	$node = pcle_authoring_shape_node( $post );
	$body = pcle_authoring_text_from_content( $post->post_content );

	$node['body']     = $body['text'];
	$node['editable'] = $body['editable'];
	$node['excerpt']  = $post->post_excerpt;

	// What the participant will actually see, so the editor can preview it
	// without a second round trip and without a second renderer.
	$node['rendered'] = pcle_rest_rendered_content( $post );

	$parent_id = pcle_get_parent_id( $post->ID );

	$node['parent']  = $parent_id ? pcle_rest_shape_ref( get_post( $parent_id ) ) : null;
	$node['program'] = pcle_rest_shape_ref( get_post( pcle_get_program_for_post( $post->ID ) ) );

	return rest_ensure_response( $node );
}
