<?php
/**
 * Platform CLE REST API.
 *
 * General-purpose REST routes for the headless frontend. Callbacks only
 * shape responses; all domain logic (enrollment, access, progress) lives in
 * enrollment.php / access-control.php / progress.php and is reused here.
 *
 * Routes:
 *   - GET /platform-cle/v1/my-training : programs available to the current user.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "my-training" REST route.
 */
function pcle_register_my_training_route() {
	register_rest_route(
		'platform-cle/v1',
		'/my-training',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_get_my_training',
			'permission_callback' => function () {
				return is_user_logged_in() && current_user_can( 'view_cle_content' );
			},
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_my_training_route' );

/**
 * REST callback: programs available to the current user, with progress.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_get_my_training( $request ) {
	$programs = pcle_get_visible_programs();

	$out = array();
	foreach ( $programs as $program ) {
		$progress = pcle_get_program_progress( $program->ID );
		$out[]    = array(
			'id'       => (int) $program->ID,
			'title'    => get_the_title( $program ),
			'progress' => array(
				'completed'  => $progress['completed'],
				'total'      => $progress['total'],
				'percentage' => $progress['percent'],
			),
		);
	}

	return rest_ensure_response( array( 'programs' => $out ) );
}
