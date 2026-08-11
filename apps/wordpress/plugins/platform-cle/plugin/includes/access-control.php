<?php
/**
 * Platform CLE access control.
 *
 * Decides who can SEE the content (unlike roles.php, which decides who can
 * EDIT it). Three responsibilities:
 *   1. Protect the CPTs' singular views behind login.
 *   2. Hide the "model answers" except from users with reveal_model_answers.
 *   3. Expose a reusable "can access?" helper.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List of CPTs that are protected behind login.
 *
 * This is all the program content: no one sees these posts without being
 * authenticated and having the view_cle_content capability.
 *
 * @return string[]
 */
function pcle_protected_post_types() {
	$types = array(
		'pcle_program',
		'pcle_week',
		'pcle_module',
		'pcle_scenario',
		'pcle_template',
		'pcle_event',
		'pcle_case_update',
	);

	/**
	 * Allows adjusting which CPTs are protected (e.g. if later you want case
	 * updates to be public).
	 *
	 * @param string[] $types List of protected post types.
	 */
	return apply_filters( 'pcle_protected_post_types', $types );
}

/**
 * Is the user, broadly speaking, a CLE participant?
 *
 * Logged in + view_cle_content capability. This is the COARSE check (used as
 * defense in depth for REST and search). Fine-grained, per-program access is
 * decided by pcle_can_access_post().
 *
 * @return bool
 */
function pcle_user_can_access() {
	$caps = pcle_custom_caps();
	return is_user_logged_in() && current_user_can( $caps['view_content'] );
}

/**
 * Can the user view THIS specific post?
 *
 * Rules:
 *   - Staff (instructor/admin) → always yes.
 *   - Must be a participant (view_cle_content); otherwise no.
 *   - Case Update → visible to any participant (cross-program announcement).
 *   - Curriculum content → must be ENROLLED in its program.
 *
 * @param int $post_id Post ID (defaults to the queried object).
 * @param int $user_id User ID (defaults to the current user).
 * @return bool
 */
function pcle_can_access_post( $post_id = 0, $user_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_queried_object_id();
	$uid     = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $uid ) {
		return false;
	}
	if ( pcle_user_is_staff( $uid ) ) {
		return true;
	}
	if ( ! user_can( $uid, 'view_cle_content' ) ) {
		return false;
	}

	// Case Updates are cross-program announcements: any participant can see them.
	if ( 'pcle_case_update' === get_post_type( $post_id ) ) {
		return true;
	}

	$program_id = pcle_get_program_for_post( $post_id );
	if ( ! $program_id ) {
		// No associated program: fall back to the participant check.
		return true;
	}

	return pcle_is_enrolled( $program_id, $uid );
}

/**
 * Access gate: protects the CPTs' singular views.
 *
 *   - Anonymous            → to login (returns to the page after signing in).
 *   - Logged in, no access → to "My Training" with a not-enrolled notice.
 */
function pcle_guard_protected_content() {
	// We only care about the singular view of ONE post on the frontend.
	if ( is_admin() || ! is_singular( pcle_protected_post_types() ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( pcle_can_access_post( $post_id ) ) {
		return; // Has access: continue normally.
	}

	// Anonymous → login with return URL.
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( get_permalink( $post_id ) ) );
		exit;
	}

	// Logged in but not enrolled → to the front door with a notice.
	$front = function_exists( 'pcle_get_front_door_url' ) ? pcle_get_front_door_url() : '';
	if ( $front ) {
		wp_safe_redirect( add_query_arg( 'pcle_notice', 'not_enrolled', $front ) );
		exit;
	}

	wp_die(
		esc_html__( 'You are not enrolled in this program. Please contact an administrator.', 'platform-cle' ),
		esc_html__( 'Not enrolled', 'platform-cle' ),
		array( 'response' => 403 )
	);
}
add_action( 'template_redirect', 'pcle_guard_protected_content' );

/**
 * Guards REST reads of the CLE CPTs.
 *
 * The CPTs are publicly_queryable + show_in_rest (needed for the block editor),
 * so the default REST controller treats their PUBLISHED items as readable by
 * anyone. Without this, /wp-json/wp/v2/pcle_program/… would leak curriculum
 * content to anonymous users. We intercept GET requests to our CPT routes and
 * enforce the same per-program access as the front end.
 *
 * Uses `rest_pre_dispatch` (a real core filter). Note: there is no core
 * `rest_{$post_type}_item_permissions_check` filter — hooking that name is a
 * no-op, which is exactly the gap this replaces.
 *
 * @param mixed           $result  Pre-dispatch result (WP_Error short-circuits).
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Incoming request.
 * @return mixed
 */
function pcle_guard_rest_reads( $result, $server, $request ) {
	if ( is_wp_error( $result ) || 'GET' !== $request->get_method() ) {
		return $result;
	}

	$route = $request->get_route();

	foreach ( pcle_protected_post_types() as $post_type ) {
		$obj     = get_post_type_object( $post_type );
		$base    = ( $obj && ! empty( $obj->rest_base ) ) ? $obj->rest_base : $post_type;
		$pattern = '#^/wp/v2/' . preg_quote( $base, '#' ) . '(?:/(?P<id>\d+))?/?$#';

		if ( ! preg_match( $pattern, $route, $m ) ) {
			continue;
		}

		// Single item → enforce per-program access (staff pass inside the helper).
		if ( isset( $m['id'] ) ) {
			return pcle_can_access_post( (int) $m['id'] ) ? $result : pcle_rest_forbidden();
		}

		/*
		 * Collection listing → this is only the coarse gate (a stranger gets
		 * 401 here). It deliberately does NOT decide which items come back:
		 * a participant enrolled in one program still must not receive
		 * another program's items. That narrowing happens per query, in
		 * pcle_restrict_rest_collection() below, because at pre_dispatch
		 * time the query hasn't run yet and there is nothing to filter.
		 */
		if ( pcle_user_can_access() || current_user_can( 'edit_posts' ) ) {
			return $result;
		}
		return pcle_rest_forbidden();
	}

	return $result;
}
add_filter( 'rest_pre_dispatch', 'pcle_guard_rest_reads', 10, 3 );

/**
 * IDs of a protected post type that a user is allowed to read.
 *
 * Deliberately reuses pcle_can_access_post() per post rather than rebuilding
 * the rule as a meta_query: access depends on walking module → week →
 * program, and a second implementation of that rule is exactly how the
 * per-item guard and the listing guard drifted apart in the first place.
 * That costs one access check per post, which is in line with the rest of
 * the plugin at its intended scale (tens–low hundreds of items).
 *
 * @param string $post_type Post type to enumerate.
 * @param int    $user_id   User (defaults to the current one).
 * @return int[] Accessible post IDs.
 */
function pcle_accessible_post_ids( $post_type, $user_id = 0 ) {
	$ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$allowed = array();
	foreach ( $ids as $id ) {
		if ( pcle_can_access_post( (int) $id, $user_id ) ) {
			$allowed[] = (int) $id;
		}
	}

	return $allowed;
}

/**
 * Narrows a REST collection listing to the items the reader may actually see.
 *
 * Without this, GET /wp/v2/pcle_module (no ID) returns every module with its
 * rendered content to anyone holding a student account, regardless of which
 * program they paid for — the per-item guard never runs, because listing a
 * collection is a different route.
 *
 * @param array           $args    WP_Query args the REST controller will run.
 * @param WP_REST_Request $request Incoming request.
 * @return array Adjusted args.
 */
function pcle_restrict_rest_collection( $args, $request ) {
	// Staff read everything (the block editor depends on it).
	if ( pcle_user_is_staff() ) {
		return $args;
	}

	$post_type = isset( $args['post_type'] ) ? $args['post_type'] : '';
	if ( ! is_string( $post_type ) || ! in_array( $post_type, pcle_protected_post_types(), true ) ) {
		return $args;
	}

	$allowed = pcle_accessible_post_ids( $post_type );

	/*
	 * WP_Query ignores an EMPTY post__in and happily returns everything, so
	 * "nothing is allowed" has to be spelled with an ID that cannot match.
	 */
	if ( empty( $allowed ) ) {
		$args['post__in'] = array( 0 );
		return $args;
	}

	// Respect a caller's ?include= by intersecting with it, never widening it.
	if ( ! empty( $args['post__in'] ) ) {
		$allowed = array_intersect( (array) $args['post__in'], $allowed );
		if ( empty( $allowed ) ) {
			$allowed = array( 0 );
		}
	}

	$args['post__in'] = array_values( $allowed );

	return $args;
}

/**
 * Hooks the collection filter for every protected type.
 *
 * Registered on rest_api_init (not at load time) so that any filter on
 * pcle_protected_post_types() has already been added.
 */
function pcle_register_rest_collection_filters() {
	foreach ( pcle_protected_post_types() as $post_type ) {
		add_filter( "rest_{$post_type}_query", 'pcle_restrict_rest_collection', 10, 2 );
	}
}
add_action( 'rest_api_init', 'pcle_register_rest_collection_filters' );

/**
 * The standard "forbidden" REST error for CLE content.
 *
 * @return WP_Error
 */
function pcle_rest_forbidden() {
	return new WP_Error(
		'pcle_rest_forbidden',
		__( 'You must be enrolled to access this content.', 'platform-cle' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Shortcode [pcle_model_answer]...[/pcle_model_answer].
 *
 * Wraps the model answer of a Practice Scenario. Protection logic:
 *   - Visitor WITHOUT reveal_model_answers → nothing is rendered (not even in HTML).
 *   - User WITH the capability             → rendered inside a collapsed <details>
 *                                            that acts as a "reveal" button.
 *
 * Rendering on the server (not hiding with CSS) prevents the answer from
 * traveling to the browser of someone who shouldn't see it.
 *
 * @param array  $atts    Shortcode attributes (unused for now).
 * @param string $content Wrapped content (the model answer).
 * @return string HTML.
 */
function pcle_model_answer_shortcode( $atts, $content = '' ) {
	$caps = pcle_custom_caps();

	// No permission: we return absolutely none of the protected content.
	if ( ! current_user_can( $caps['reveal_answers'] ) ) {
		return '';
	}

	/*
	 * The capability travels with the student ROLE, so on its own it only
	 * says "this kind of user may reveal answers" — never "this user belongs
	 * in this program". Both have to hold, or a student from another cohort
	 * reads the answers straight out of the scenario body.
	 *
	 * If we cannot tell which post we are rendering inside, fail closed:
	 * for protected content, an unknown context is not a reason to reveal.
	 */
	$post_id = get_the_ID();
	if ( ! $post_id || ! pcle_can_access_post( $post_id ) ) {
		return '';
	}

	// do_shortcode allows nesting blocks/shortcodes inside the answer.
	$inner = do_shortcode( $content );

	return sprintf(
		'<details class="pcle-model-answer"><summary>%s</summary><div class="pcle-model-answer__body">%s</div></details>',
		esc_html__( 'Reveal model answer', 'platform-cle' ),
		wp_kses_post( $inner )
	);
}
add_shortcode( 'pcle_model_answer', 'pcle_model_answer_shortcode' );

/**
 * Hides the program CPTs from search results and feeds for users without
 * access (defense in depth).
 *
 * @param WP_Query $query The main query.
 */
function pcle_filter_protected_from_search( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ( $query->is_search() || $query->is_feed() ) && ! pcle_user_can_access() ) {
		$public = array_diff( (array) $query->get( 'post_type' ), pcle_protected_post_types() );
		// If there was no explicit post_type, search defaults to 'post'/'page',
		// which doesn't include our CPTs, so there's nothing to change.
		if ( ! empty( $public ) ) {
			$query->set( 'post_type', $public );
		}
	}
}
add_action( 'pre_get_posts', 'pcle_filter_protected_from_search' );
