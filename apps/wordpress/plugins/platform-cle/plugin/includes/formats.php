<?php
/**
 * Platform CLE programme formats.
 *
 * A programme is either a SERIES — units, modules, sessions, the full shape —
 * or a WEBINAR: one recorded video, optionally with a quiz, and nothing to
 * navigate between.
 *
 * A webinar is deliberately not a separate post type. Progress, certificates,
 * credit hours, quizzes, enrollment and reports all hang off the
 * programme → unit → module hierarchy, and every one of them would need a
 * second implementation. Instead a webinar keeps that hierarchy with exactly
 * one unit and one module, and the format tells the app to hide it: the
 * participant goes straight to the module, which is where the video lives.
 *
 * @package Platform_CLE
 */

defined( 'ABSPATH' ) || exit;

/** Post meta holding a programme's format. Absent means a series. */
const PCLE_PROGRAM_FORMAT_META = '_pcle_program_format';

/**
 * The formats a programme can take.
 *
 * @return array<string,string> Slug => label.
 */
function pcle_program_formats() {
	return array(
		'series'  => __( 'Series', 'platform-cle' ),
		'webinar' => __( 'Webinar', 'platform-cle' ),
	);
}

/**
 * A programme's format.
 *
 * @param int $program_id Programme ID.
 * @return string 'series' or 'webinar'.
 */
function pcle_get_program_format( $program_id ) {
	$format = (string) get_post_meta( (int) $program_id, PCLE_PROGRAM_FORMAT_META, true );

	return isset( pcle_program_formats()[ $format ] ) ? $format : 'series';
}

/**
 * Is this programme a webinar?
 *
 * @param int $program_id Programme ID.
 * @return bool
 */
function pcle_is_webinar( $program_id ) {
	return 'webinar' === pcle_get_program_format( $program_id );
}

/**
 * Changes a programme's format, reshaping it into a webinar when asked.
 *
 * Becoming a webinar needs exactly one unit and one module. What is missing is
 * created, with the programme's own status so a published webinar does not
 * gain a draft module that silently hides the video. What cannot be merged
 * safely — a second unit, a second module — is refused: which one to keep is
 * the author's decision, not this function's.
 *
 * If the unit already carries a body and there is no module yet, the body
 * moves to the new module. That is where a webinar built before this format
 * existed kept its video, and leaving it on the unit would put it on a screen
 * a webinar no longer shows.
 *
 * Going back to a series only changes the format; the structure is already a
 * valid series.
 *
 * @param int    $program_id Programme ID.
 * @param string $format     'series' or 'webinar'.
 * @return true|WP_Error
 */
function pcle_set_program_format( $program_id, $format ) {
	$program_id = (int) $program_id;

	if ( ! isset( pcle_program_formats()[ $format ] ) ) {
		return new WP_Error( 'pcle_invalid_format', __( 'Unknown programme format.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	if ( 'series' === $format ) {
		delete_post_meta( $program_id, PCLE_PROGRAM_FORMAT_META );
		return true;
	}

	$units = pcle_authoring_get_children( $program_id, 'pcle_unit' );

	if ( count( $units ) > 1 ) {
		return new WP_Error(
			'pcle_webinar_shape',
			/* translators: %d: number of units. */
			sprintf( __( 'A webinar has one unit, and this programme has %d. Delete or move the others first.', 'platform-cle' ), count( $units ) ),
			array( 'status' => 409 )
		);
	}

	$modules = $units ? pcle_authoring_get_children( $units[0]->ID, 'pcle_module' ) : array();

	if ( count( $modules ) > 1 ) {
		return new WP_Error(
			'pcle_webinar_shape',
			/* translators: %d: number of modules. */
			sprintf( __( 'A webinar has one module, and this programme has %d. Delete or move the others first.', 'platform-cle' ), count( $modules ) ),
			array( 'status' => 409 )
		);
	}

	$status = get_post_status( $program_id );

	if ( ! $units ) {
		$unit_id = wp_insert_post(
			array(
				'post_type'   => 'pcle_unit',
				'post_title'  => __( 'Webinar', 'platform-cle' ),
				'post_status' => $status,
				'menu_order'  => 1,
				'meta_input'  => array( '_pcle_program_id' => $program_id ),
			),
			true
		);

		if ( is_wp_error( $unit_id ) ) {
			return new WP_Error( 'pcle_create_failed', $unit_id->get_error_message(), array( 'status' => 500 ) );
		}

		$unit = get_post( $unit_id );
	} else {
		$unit = $units[0];
	}

	if ( ! $modules ) {
		$module_id = wp_insert_post(
			array(
				'post_type'    => 'pcle_module',
				'post_title'   => get_the_title( $program_id ),
				'post_content' => $unit->post_content,
				'post_status'  => $status,
				'menu_order'   => 1,
				'meta_input'   => array( '_pcle_unit_id' => (int) $unit->ID ),
			),
			true
		);

		if ( is_wp_error( $module_id ) ) {
			return new WP_Error( 'pcle_create_failed', $module_id->get_error_message(), array( 'status' => 500 ) );
		}

		if ( '' !== trim( $unit->post_content ) ) {
			wp_update_post(
				array(
					'ID'           => (int) $unit->ID,
					'post_content' => '',
				)
			);
		}
	}

	update_post_meta( $program_id, PCLE_PROGRAM_FORMAT_META, 'webinar' );
	pcle_sync_webinar_status( $program_id );

	return true;
}

/**
 * Gives a webinar's unit and module the programme's own status.
 *
 * A webinar's author never sees the unit, so it has no publish control of its
 * own: left alone, publishing the programme would leave a draft unit hiding
 * the video from every participant. The programme's status decides for all
 * three. Quizzes keep theirs — they are visible, and an unfinished one is a
 * reason to keep it back on its own.
 *
 * @param int $program_id Programme ID.
 */
function pcle_sync_webinar_status( $program_id ) {
	if ( ! pcle_is_webinar( $program_id ) ) {
		return;
	}

	$status = get_post_status( $program_id );

	foreach ( pcle_authoring_get_children( $program_id, 'pcle_unit' ) as $unit ) {
		$posts = array_merge( array( $unit ), pcle_authoring_get_children( $unit->ID, 'pcle_module' ) );

		foreach ( $posts as $post ) {
			if ( $post->post_status !== $status ) {
				wp_update_post(
					array(
						'ID'          => (int) $post->ID,
						'post_status' => $status,
					)
				);
			}
		}
	}
}

/**
 * The module a webinar's participants land on, if it has one they can see.
 *
 * @param int $program_id Programme ID.
 * @return int Module ID, or 0.
 */
function pcle_get_webinar_module_id( $program_id ) {
	if ( ! pcle_is_webinar( $program_id ) ) {
		return 0;
	}

	$modules = pcle_get_program_module_ids( $program_id );

	return $modules ? (int) $modules[0] : 0;
}
