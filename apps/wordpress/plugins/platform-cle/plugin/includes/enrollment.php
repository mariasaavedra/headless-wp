<?php
/**
 * Per-program student enrollment.
 *
 * Until now access depended only on the `view_cle_content` capability (any
 * student saw every program). Here we make it "per program": a student must be
 * ENROLLED in the specific program.
 *
 * Model: user meta `_hcle_enrolled_programs` = array of program IDs.
 * Teaching staff (instructor/admin) need no enrollment: their content-management
 * capability gives them full access.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** User meta key with the programs the user is enrolled in. */
const HCLE_ENROLLMENT_META = '_hcle_enrolled_programs';

/* =========================================================================
 * 1) DATA / HELPERS
 * ========================================================================= */

/**
 * Programs a user is enrolled in.
 *
 * @param int $user_id User ID (defaults to the current user).
 * @return int[]
 */
function hcle_get_enrolled_programs( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}
	$value = get_user_meta( $user_id, HCLE_ENROLLMENT_META, true );
	if ( ! is_array( $value ) ) {
		return array();
	}
	return array_values( array_unique( array_map( 'intval', $value ) ) );
}

/**
 * Is the user enrolled in this program?
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID (defaults to the current user).
 * @return bool
 */
function hcle_is_enrolled( $program_id, $user_id = 0 ) {
	return in_array( (int) $program_id, hcle_get_enrolled_programs( $user_id ), true );
}

/**
 * Enrolls a user in a program.
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID.
 * @return bool
 */
function hcle_enroll_user( $program_id, $user_id ) {
	$program_id = (int) $program_id;
	$user_id    = (int) $user_id;
	if ( ! $user_id || 'hcle_program' !== get_post_type( $program_id ) ) {
		return false;
	}
	$list = hcle_get_enrolled_programs( $user_id );
	if ( ! in_array( $program_id, $list, true ) ) {
		$list[] = $program_id;
		update_user_meta( $user_id, HCLE_ENROLLMENT_META, $list );

		/**
		 * Fires when a user is newly enrolled in a program.
		 *
		 * Only fires on a genuinely new enrollment (not when re-saving an
		 * existing one). Used for the enrollment confirmation email and, later,
		 * by the payment → enrollment bridge.
		 *
		 * @param int $program_id Program ID.
		 * @param int $user_id    User ID.
		 */
		do_action( 'hcle_user_enrolled', $program_id, $user_id );
	}
	return true;
}

/**
 * Unenrolls a user from a program.
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID.
 * @return bool
 */
function hcle_unenroll_user( $program_id, $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}
	$list     = hcle_get_enrolled_programs( $user_id );
	$filtered = array_values( array_diff( $list, array( (int) $program_id ) ) );
	if ( count( $filtered ) !== count( $list ) ) {
		update_user_meta( $user_id, HCLE_ENROLLMENT_META, $filtered );
	}
	return true;
}

/**
 * Is the user "teaching staff" (can manage content)?
 *
 * Instructors and administrators have `edit_hcle_contents`; students don't.
 *
 * @param int $user_id User ID (defaults to the current user).
 * @return bool
 */
function hcle_user_is_staff( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	return user_can( $user_id, 'edit_hcle_contents' );
}

/**
 * List of students (role hcle_student), optionally filtered by program.
 *
 * The meta is a serialized array, so we filter in PHP (enough for this
 * program's scale).
 *
 * @param int  $program_id    Program ID (to filter by enrolled).
 * @param bool $only_enrolled If true, only those enrolled in $program_id.
 * @return WP_User[]
 */
function hcle_get_program_students( $program_id = 0, $only_enrolled = false ) {
	$students = get_users(
		array(
			'role'    => 'hcle_student',
			'orderby' => 'display_name',
			'order'   => 'ASC',
		)
	);

	if ( ! $only_enrolled || ! $program_id ) {
		return $students;
	}

	return array_values(
		array_filter(
			$students,
			function ( $user ) use ( $program_id ) {
				return hcle_is_enrolled( $program_id, $user->ID );
			}
		)
	);
}

/* =========================================================================
 * 2) ENROLLMENT FORM SAVE (instructor screen)
 * ========================================================================= */

/**
 * Processes the enrollment form submitted from the participants screen.
 *
 * Receives the list of displayed students (hcle_students) and which ones were
 * checked (hcle_enrolled); enrolls/unenrolls accordingly.
 */
function hcle_handle_enrollment_save() {
	if ( empty( $_POST['hcle_enrollment_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hcle_enrollment_nonce'] ) ), 'hcle_save_enrollment' ) ) {
		return;
	}
	if ( ! current_user_can( 'view_participant_progress' ) ) {
		return;
	}

	$program_id = isset( $_POST['hcle_enrollment_program'] ) ? absint( $_POST['hcle_enrollment_program'] ) : 0;
	if ( 'hcle_program' !== get_post_type( $program_id ) ) {
		return;
	}

	$candidates = isset( $_POST['hcle_students'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['hcle_students'] ) ) : array();
	$checked    = isset( $_POST['hcle_enrolled'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['hcle_enrolled'] ) ) : array();

	foreach ( $candidates as $student_id ) {
		if ( in_array( $student_id, $checked, true ) ) {
			hcle_enroll_user( $program_id, $student_id );
		} else {
			hcle_unenroll_user( $program_id, $student_id );
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'habeas-cle-progress',
				'program'      => $program_id,
				'hcle_message' => 'enrollment_saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'hcle_handle_enrollment_save' );

/**
 * Builds a unique user_login from an email local-part.
 *
 * @param string $email Email address.
 * @return string
 */
function hcle_unique_login_from_email( $email ) {
	$parts = explode( '@', $email );
	$base  = sanitize_user( $parts[0], true );
	if ( '' === $base ) {
		$base = 'student';
	}
	$login = $base;
	$i     = 1;
	while ( username_exists( $login ) ) {
		$login = $base . $i;
		++$i;
	}
	return $login;
}

/**
 * Bulk-enrolls a cohort from a list of emails (Participants screen).
 *
 * Instructors can enroll EXISTING students by email. Admins (create_users) can
 * also create accounts for unknown emails and send them a set-password email.
 */
function hcle_handle_bulk_enroll() {
	if ( empty( $_POST['hcle_bulk_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hcle_bulk_nonce'] ) ), 'hcle_bulk_enroll' ) ) {
		return;
	}
	if ( ! current_user_can( 'view_participant_progress' ) ) {
		return;
	}

	$program_id = isset( $_POST['hcle_bulk_program'] ) ? absint( $_POST['hcle_bulk_program'] ) : 0;
	if ( 'hcle_program' !== get_post_type( $program_id ) ) {
		return;
	}

	$raw    = isset( $_POST['hcle_bulk_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hcle_bulk_emails'] ) ) : '';
	$create = ! empty( $_POST['hcle_bulk_create'] ) && current_user_can( 'create_users' );

	$emails   = array_filter( array_unique( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ) ) );
	$enrolled = 0;
	$created  = 0;
	$skipped  = 0;

	foreach ( $emails as $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			++$skipped;
			continue;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			if ( ! $create ) {
				++$skipped; // Unknown email and we're not allowed to create accounts.
				continue;
			}
			$uid = wp_insert_user(
				array(
					'user_login' => hcle_unique_login_from_email( $email ),
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 20 ),
					'role'       => 'hcle_student',
				)
			);
			if ( is_wp_error( $uid ) ) {
				++$skipped;
				continue;
			}
			++$created;
			wp_send_new_user_notifications( $uid, 'user' ); // Set-password / welcome email.
			$user_id = (int) $uid;
		} else {
			$user_id = (int) $user->ID;
		}

		hcle_enroll_user( $program_id, $user_id );
		++$enrolled;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'habeas-cle-progress',
				'program'      => $program_id,
				'hcle_message' => 'bulk_done',
				'enrolled'     => $enrolled,
				'created'      => $created,
				'skipped'      => $skipped,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'hcle_handle_bulk_enroll' );
