<?php
/**
 * Health check endpoint.
 *
 * A lightweight status endpoint for deploy verification and uptime monitoring:
 *
 *   GET /wp-json/habeas-cle/v1/health
 *
 * Public callers get only `{status, version}` (safe for load balancers / uptime
 * pings). Administrators additionally get configuration `checks` — useful right
 * after a deploy to confirm roles, cron, the protected dir, and the front door
 * are all in place.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the health route.
 */
function hcle_register_health_route() {
	register_rest_route(
		'habeas-cle/v1',
		'/health',
		array(
			'methods'             => 'GET',
			'callback'            => 'hcle_rest_health',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'hcle_register_health_route' );

/**
 * Health payload.
 *
 * @return WP_REST_Response
 */
function hcle_rest_health() {
	$data = array(
		'status'  => 'ok',
		'plugin'  => 'habeas-cle',
		'version' => HABEAS_CLE_VERSION,
	);

	// Detailed configuration checks only for administrators.
	if ( current_user_can( 'manage_options' ) ) {
		$checks = array(
			'roles'                  => (bool) get_role( 'hcle_student' ) && (bool) get_role( 'hcle_instructor' ),
			'reminder_cron'          => (bool) wp_next_scheduled( HCLE_REMINDER_CRON ),
			'protected_dir'          => is_dir( hcle_protected_basedir() ) && wp_is_writable( hcle_protected_basedir() ),
			'front_door'             => '' !== hcle_get_front_door_url(),
			'programs_published'     => (int) wp_count_posts( 'hcle_program' )->publish,
		);
		$data['checks'] = $checks;
		$data['status'] = in_array( false, array( $checks['roles'], $checks['reminder_cron'], $checks['protected_dir'] ), true )
			? 'degraded'
			: 'ok';
	}

	return rest_ensure_response( $data );
}
