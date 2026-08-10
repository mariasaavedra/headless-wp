<?php
/**
 * Habeas CLE roles and capabilities.
 *
 * Three roles per the brief:
 *   - hcle_student    : views content, tracks progress, reveals model answers.
 *   - hcle_instructor : creates/edits curriculum, publishes case updates, sees progress.
 *   - administrator   : full access (native WP role; we add our caps to it).
 *
 * The CPT capabilities are read from hcle_capability_types() (in post-types.php)
 * to avoid duplicating names.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom capabilities (not tied to a CPT).
 *
 * Used in access-control.php to decide who SEES what.
 *
 * @return array{
 *     view_content: string,
 *     reveal_answers: string,
 *     view_progress: string
 * }
 */
function hcle_custom_caps() {
	return array(
		'view_content'   => 'view_cle_content',          // Gate: being inside the program.
		'reveal_answers' => 'reveal_model_answers',       // Reveal model answers.
		'view_progress'  => 'view_participant_progress',  // View others' progress.
	);
}

/**
 * Collects ALL primitive caps of the CPTs (both groups) into a single list.
 *
 * @return string[]
 */
function hcle_all_content_caps() {
	$cap_types = hcle_capability_types();
	$caps      = array();

	foreach ( $cap_types as $type ) {
		$caps = array_merge( $caps, hcle_primitive_caps( $type['plural'] ) );
	}

	return $caps;
}

/**
 * Creates/updates the roles and their capabilities.
 *
 * Runs on plugin ACTIVATION (see hcle_activate in the main file). add_role() is
 * idempotent: if the role already exists it won't duplicate it, which is why we
 * remove it first to re-seed clean caps on every activation.
 */
function hcle_register_roles() {
	$custom  = hcle_custom_caps();
	$content = hcle_all_content_caps();

	/*
	 * ---------------------------------------------------------------
	 * STUDENT — only consumes content.
	 * ---------------------------------------------------------------
	 * `read` lets them enter the admin minimally and view their profile.
	 * They receive no CPT editing cap.
	 */
	remove_role( 'hcle_student' );
	add_role(
		'hcle_student',
		__( 'CLE Student', 'habeas-cle' ),
		array(
			'read'                     => true,
			$custom['view_content']    => true, // Can enter the program.
			$custom['reveal_answers']  => true, // Can reveal model answers.
		)
	);

	/*
	 * ---------------------------------------------------------------
	 * INSTRUCTOR — manages the curriculum and publishes case updates.
	 * ---------------------------------------------------------------
	 */
	$instructor_caps = array(
		'read'                    => true,
		'upload_files'            => true, // Upload PDFs/templates/images.
		$custom['view_content']   => true,
		$custom['reveal_answers'] => true,
		$custom['view_progress']  => true, // Sees participants' progress.
	);
	// Grant them ALL the CPT primitive caps (curriculum + case updates).
	foreach ( $content as $cap ) {
		$instructor_caps[ $cap ] = true;
	}

	remove_role( 'hcle_instructor' );
	add_role( 'hcle_instructor', __( 'CLE Instructor', 'habeas-cle' ), $instructor_caps );

	/*
	 * ---------------------------------------------------------------
	 * ADMINISTRATOR — native role; we add our caps to it.
	 * ---------------------------------------------------------------
	 * We don't create a new role: we extend the existing one so the admin
	 * can edit all CPTs and use the plugin's features.
	 */
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( $custom as $cap ) {
			$admin->add_cap( $cap );
		}
		foreach ( $content as $cap ) {
			$admin->add_cap( $cap );
		}
	}
}

/**
 * Removes the plugin's roles and caps (for uninstall.php).
 *
 * Not called on normal deactivation; only on uninstall, to avoid losing data
 * if the user only deactivates temporarily.
 */
function hcle_remove_roles() {
	remove_role( 'hcle_student' );
	remove_role( 'hcle_instructor' );

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( hcle_custom_caps() as $cap ) {
			$admin->remove_cap( $cap );
		}
		foreach ( hcle_all_content_caps() as $cap ) {
			$admin->remove_cap( $cap );
		}
	}
}
