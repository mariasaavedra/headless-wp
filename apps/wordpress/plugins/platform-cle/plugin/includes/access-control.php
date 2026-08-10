<?php
/**
 * Habeas CLE access control.
 *
 * Decides who can SEE the content (unlike roles.php, which decides who can
 * EDIT it). Three responsibilities:
 *   1. Protect the CPTs' singular views behind login.
 *   2. Hide the "model answers" except from users with reveal_model_answers.
 *   3. Expose a reusable "can access?" helper.
 *
 * @package Habeas_CLE
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
function hcle_protected_post_types() {
	$types = array(
		'hcle_program',
		'hcle_week',
		'hcle_module',
		'hcle_scenario',
		'hcle_template',
		'hcle_event',
		'hcle_case_update',
	);

	/**
	 * Allows adjusting which CPTs are protected (e.g. if later you want case
	 * updates to be public).
	 *
	 * @param string[] $types List of protected post types.
	 */
	return apply_filters( 'hcle_protected_post_types', $types );
}

/**
 * Is the user, broadly speaking, a CLE participant?
 *
 * Logged in + view_cle_content capability. This is the COARSE check (used as
 * defense in depth for REST and search). Fine-grained, per-program access is
 * decided by hcle_can_access_post().
 *
 * @return bool
 */
function hcle_user_can_access() {
	$caps = hcle_custom_caps();
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
function hcle_can_access_post( $post_id = 0, $user_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_queried_object_id();
	$uid     = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $uid ) {
		return false;
	}
	if ( hcle_user_is_staff( $uid ) ) {
		return true;
	}
	if ( ! user_can( $uid, 'view_cle_content' ) ) {
		return false;
	}

	// Case Updates are cross-program announcements: any participant can see them.
	if ( 'hcle_case_update' === get_post_type( $post_id ) ) {
		return true;
	}

	$program_id = hcle_get_program_for_post( $post_id );
	if ( ! $program_id ) {
		// No associated program: fall back to the participant check.
		return true;
	}

	return hcle_is_enrolled( $program_id, $uid );
}

/**
 * Access gate: protects the CPTs' singular views.
 *
 *   - Anonymous            → to login (returns to the page after signing in).
 *   - Logged in, no access → to "My Training" with a not-enrolled notice.
 */
function hcle_guard_protected_content() {
	// We only care about the singular view of ONE post on the frontend.
	if ( is_admin() || ! is_singular( hcle_protected_post_types() ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( hcle_can_access_post( $post_id ) ) {
		return; // Has access: continue normally.
	}

	// Anonymous → login with return URL.
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( get_permalink( $post_id ) ) );
		exit;
	}

	// Logged in but not enrolled → to the front door with a notice.
	$front = function_exists( 'hcle_get_front_door_url' ) ? hcle_get_front_door_url() : '';
	if ( $front ) {
		wp_safe_redirect( add_query_arg( 'hcle_notice', 'not_enrolled', $front ) );
		exit;
	}

	wp_die(
		esc_html__( 'You are not enrolled in this program. Please contact an administrator.', 'habeas-cle' ),
		esc_html__( 'Not enrolled', 'habeas-cle' ),
		array( 'response' => 403 )
	);
}
add_action( 'template_redirect', 'hcle_guard_protected_content' );

/**
 * Guards REST reads of the CLE CPTs.
 *
 * The CPTs are publicly_queryable + show_in_rest (needed for the block editor),
 * so the default REST controller treats their PUBLISHED items as readable by
 * anyone. Without this, /wp-json/wp/v2/hcle_program/… would leak curriculum
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
function hcle_guard_rest_reads( $result, $server, $request ) {
	if ( is_wp_error( $result ) || 'GET' !== $request->get_method() ) {
		return $result;
	}

	$route = $request->get_route();

	foreach ( hcle_protected_post_types() as $post_type ) {
		$obj     = get_post_type_object( $post_type );
		$base    = ( $obj && ! empty( $obj->rest_base ) ) ? $obj->rest_base : $post_type;
		$pattern = '#^/wp/v2/' . preg_quote( $base, '#' ) . '(?:/(?P<id>\d+))?/?$#';

		if ( ! preg_match( $pattern, $route, $m ) ) {
			continue;
		}

		// Single item → enforce per-program access (staff pass inside the helper).
		if ( isset( $m['id'] ) ) {
			return hcle_can_access_post( (int) $m['id'] ) ? $result : hcle_rest_forbidden();
		}

		// Collection listing → require at least a participant (or an editor).
		if ( hcle_user_can_access() || current_user_can( 'edit_posts' ) ) {
			return $result;
		}
		return hcle_rest_forbidden();
	}

	return $result;
}
add_filter( 'rest_pre_dispatch', 'hcle_guard_rest_reads', 10, 3 );

/**
 * The standard "forbidden" REST error for CLE content.
 *
 * @return WP_Error
 */
function hcle_rest_forbidden() {
	return new WP_Error(
		'hcle_rest_forbidden',
		__( 'You must be enrolled to access this content.', 'habeas-cle' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Shortcode [hcle_model_answer]...[/hcle_model_answer].
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
function hcle_model_answer_shortcode( $atts, $content = '' ) {
	$caps = hcle_custom_caps();

	// No permission: we return absolutely none of the protected content.
	if ( ! current_user_can( $caps['reveal_answers'] ) ) {
		return '';
	}

	// do_shortcode allows nesting blocks/shortcodes inside the answer.
	$inner = do_shortcode( $content );

	return sprintf(
		'<details class="hcle-model-answer"><summary>%s</summary><div class="hcle-model-answer__body">%s</div></details>',
		esc_html__( 'Reveal model answer', 'habeas-cle' ),
		wp_kses_post( $inner )
	);
}
add_shortcode( 'hcle_model_answer', 'hcle_model_answer_shortcode' );

/**
 * Hides the program CPTs from search results and feeds for users without
 * access (defense in depth).
 *
 * @param WP_Query $query The main query.
 */
function hcle_filter_protected_from_search( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ( $query->is_search() || $query->is_feed() ) && ! hcle_user_can_access() ) {
		$public = array_diff( (array) $query->get( 'post_type' ), hcle_protected_post_types() );
		// If there was no explicit post_type, search defaults to 'post'/'page',
		// which doesn't include our CPTs, so there's nothing to change.
		if ( ! empty( $public ) ) {
			$query->set( 'post_type', $public );
		}
	}
}
add_action( 'pre_get_posts', 'hcle_filter_protected_from_search' );
