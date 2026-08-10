<?php
/**
 * Health check endpoint.
 *
 * A lightweight status endpoint for deploy verification and uptime monitoring:
 *
 *   GET /wp-json/platform-cle/v1/health
 *
 * Public callers get only `{status, version}` (safe for load balancers / uptime
 * pings). Administrators additionally get configuration `checks` — useful right
 * after a deploy to confirm roles, cron, the protected dir, and the front door
 * are all in place.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the health route.
 */
function pcle_register_health_route() {
	register_rest_route(
		'platform-cle/v1',
		'/health',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_health',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_health_route' );

/**
 * Health payload.
 *
 * @return WP_REST_Response
 */
function pcle_rest_health() {
	$data = array(
		'status'  => 'ok',
		'plugin'  => 'platform-cle',
		'version' => PLATFORM_CLE_VERSION,
	);

	// Detailed configuration checks only for administrators.
	if ( current_user_can( 'manage_options' ) ) {
		$checks = array(
			'roles'                  => (bool) get_role( 'pcle_student' ) && (bool) get_role( 'pcle_instructor' ),
			'reminder_cron'          => (bool) wp_next_scheduled( PCLE_REMINDER_CRON ),
			'protected_dir'          => is_dir( pcle_protected_basedir() ) && wp_is_writable( pcle_protected_basedir() ),
			'front_door'             => '' !== pcle_get_front_door_url(),
			'programs_published'     => (int) wp_count_posts( 'pcle_program' )->publish,
		);
		$data['checks'] = $checks;
		$data['status'] = in_array( false, array( $checks['roles'], $checks['reminder_cron'], $checks['protected_dir'] ), true )
			? 'degraded'
			: 'ok';
	}

	return rest_ensure_response( $data );
}
