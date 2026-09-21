<?php
/**
 * Per-program student enrollment.
 *
 * Until now access depended only on the `view_cle_content` capability (any
 * student saw every program). Here we make it "per program": a student must be
 * ENROLLED in the specific program.
 *
 * Model: one row per (user, program) in the `pcle_enrollments` table,
 * carrying the enrollment timestamp.
 * Teaching staff (instructor/admin) need no enrollment: their content-management
 * capability gives them full access.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy user meta key, kept only so the migration in schema.php can find
 * it. Nothing reads it as a source of truth any more.
 */
const PCLE_ENROLLMENT_META = '_pcle_enrolled_programs';

/* =========================================================================
 * 1) DATA / HELPERS
 * ========================================================================= */

/**
 * Programs a user is enrolled in.
 *
 * @param int $user_id User ID (defaults to the current user).
 * @return int[]
 */
function pcle_get_enrolled_programs( $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}

	$table = pcle_enrollments_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$ids = $wpdb->get_col(
		$wpdb->prepare( "SELECT program_id FROM {$table} WHERE user_id = %d", $user_id )
	);

	return array_map( 'intval', $ids );
}

/**
 * When a user was enrolled in a program.
 *
 * NULL for enrollments carried over from the old user-meta model, which never
 * recorded a date.
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID (defaults to the current user).
 * @return string|null MySQL datetime in site time, or null.
 */
function pcle_get_enrolled_at( $program_id, $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return null;
	}

	$table = pcle_enrollments_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$value = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT enrolled_at FROM {$table} WHERE user_id = %d AND program_id = %d",
			$user_id,
			(int) $program_id
		)
	);

	return null === $value ? null : (string) $value;
}

/**
 * Is the user enrolled in this program?
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID (defaults to the current user).
 * @return bool
 */
function pcle_is_enrolled( $program_id, $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}

	$table = pcle_enrollments_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE user_id = %d AND program_id = %d",
			$user_id,
			(int) $program_id
		)
	);
}

/**
 * Enrolls a user in a program.
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID.
 * @return bool
 */
function pcle_enroll_user( $program_id, $user_id ) {
	$program_id = (int) $program_id;
	$user_id    = (int) $user_id;
	if ( ! $user_id || 'pcle_program' !== get_post_type( $program_id ) ) {
		return false;
	}
	global $wpdb;

	$table = pcle_enrollments_table();

	/*
	 * INSERT IGNORE against UNIQUE (user_id, program_id). The affected-row
	 * count is what tells us this was a NEW enrollment rather than a re-save,
	 * which is what the action below depends on — and it is now decided by
	 * the database rather than by a read-then-write that two concurrent
	 * requests could both win.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$inserted = (int) $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (user_id, program_id, enrolled_at) VALUES (%d, %d, %s)",
			$user_id,
			$program_id,
			current_time( 'mysql' )
		)
	);

	if ( $inserted > 0 ) {
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
		do_action( 'pcle_user_enrolled', $program_id, $user_id );
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
function pcle_unenroll_user( $program_id, $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		pcle_enrollments_table(),
		array(
			'user_id'    => $user_id,
			'program_id' => (int) $program_id,
		),
		array( '%d', '%d' )
	);

	return true;
}

/**
 * Programs visible to a user: all published programs for staff, or only the
 * ones they're enrolled in for a student.
 *
 * Shared by the "My Training" front door (blocks.php) and the my-training
 * REST endpoint (rest.php) so both list the same set of programs the same way.
 *
 * @param int $user_id User ID (defaults to the current user).
 * @return WP_Post[]
 */
function pcle_get_visible_programs( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( pcle_user_is_staff( $user_id ) ) {
		return get_posts(
			array(
				'post_type'   => 'pcle_program',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
	}

	$enrolled = pcle_get_enrolled_programs( $user_id );
	if ( ! $enrolled ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'   => 'pcle_program',
			'post_status' => 'publish',
			'numberposts' => -1,
			'post__in'    => $enrolled,
			'orderby'     => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);
}

/**
 * Is the user "teaching staff" (can manage content)?
 *
 * Instructors and administrators have `edit_pcle_contents`; students don't.
 *
 * @param int $user_id User ID (defaults to the current user).
 * @return bool
 */
function pcle_user_is_staff( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	return user_can( $user_id, 'edit_pcle_contents' );
}

/**
 * List of students (role pcle_student), optionally filtered by program.
 *
 * Filtering is done by the database now. It used to load every student and
 * test them one at a time in PHP, which was one enrollment lookup per user
 * on a screen that already shows all of them.
 *
 * @param int  $program_id    Program ID (to filter by enrolled).
 * @param bool $only_enrolled If true, only those enrolled in $program_id.
 * @return WP_User[]
 */
function pcle_get_program_students( $program_id = 0, $only_enrolled = false ) {
	$args = array(
		'role'    => 'pcle_student',
		'orderby' => 'display_name',
		'order'   => 'ASC',
	);

	if ( $only_enrolled && $program_id ) {
		$enrolled = pcle_get_program_enrollee_ids( $program_id );
		if ( ! $enrolled ) {
			return array();
		}
		$args['include'] = $enrolled;
	}

	return get_users( $args );
}

/**
 * IDs of every user enrolled in a program.
 *
 * The query the old model could not express, and the one every roster,
 * report and certificate run needs.
 *
 * @param int $program_id Program ID.
 * @return int[]
 */
function pcle_get_program_enrollee_ids( $program_id ) {
	global $wpdb;

	$table = pcle_enrollments_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$ids = $wpdb->get_col(
		$wpdb->prepare( "SELECT user_id FROM {$table} WHERE program_id = %d", (int) $program_id )
	);

	return array_map( 'intval', $ids );
}

/* =========================================================================
 * 2) ENROLLMENT FORM SAVE (instructor screen)
 * ========================================================================= */

/**
 * Processes the enrollment form submitted from the participants screen.
 *
 * Receives the list of displayed students (pcle_students) and which ones were
 * checked (pcle_enrolled); enrolls/unenrolls accordingly.
 */
function pcle_handle_enrollment_save() {
	if ( empty( $_POST['pcle_enrollment_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcle_enrollment_nonce'] ) ), 'pcle_save_enrollment' ) ) {
		return;
	}
	if ( ! current_user_can( 'view_participant_progress' ) ) {
		return;
	}

	$program_id = isset( $_POST['pcle_enrollment_program'] ) ? absint( $_POST['pcle_enrollment_program'] ) : 0;
	if ( 'pcle_program' !== get_post_type( $program_id ) ) {
		return;
	}

	$candidates = isset( $_POST['pcle_students'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pcle_students'] ) ) : array();
	$checked    = isset( $_POST['pcle_enrolled'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pcle_enrolled'] ) ) : array();

	foreach ( $candidates as $student_id ) {
		if ( in_array( $student_id, $checked, true ) ) {
			pcle_enroll_user( $program_id, $student_id );
		} else {
			pcle_unenroll_user( $program_id, $student_id );
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'platform-cle-progress',
				'program'      => $program_id,
				'pcle_message' => 'enrollment_saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'pcle_handle_enrollment_save' );

/**
 * Builds a unique user_login from an email local-part.
 *
 * @param string $email Email address.
 * @return string
 */
function pcle_unique_login_from_email( $email ) {
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
 * Splits a pasted list of emails into individual addresses.
 *
 * One parser for the wp-admin textarea and the REST route both, so "a, b; c"
 * and a list one per line mean the same thing wherever someone pastes it.
 *
 * @param string $raw Raw textarea contents.
 * @return string[] Trimmed, de-duplicated, still unvalidated.
 */
function pcle_parse_email_list( $raw ) {
	$parts = preg_split( '/[\s,;]+/', (string) $raw );

	return array_values( array_filter( array_unique( array_map( 'trim', (array) $parts ) ) ) );
}

/**
 * Enrolls a cohort by email address.
 *
 * The logic behind both the wp-admin Participants screen and the REST route
 * the frontend calls. It lives on its own because a second implementation of
 * "what does enrolling a list of strangers mean" is a second thing to keep
 * correct: who counts as already enrolled, what happens to an address with no
 * account, and whether an account may be created at all.
 *
 * Account creation is the caller's decision AND the caller's permission to
 * make: $create is only honoured for someone who can create_users. An
 * unknown address is otherwise reported back rather than silently dropped,
 * because "I pasted twelve and it says nine" is a question the caller has to
 * be able to answer.
 *
 * @param int      $program_id Programme to enrol into.
 * @param string[] $emails     Email addresses.
 * @param bool     $create     Create accounts for unknown addresses.
 * @return array{enrolled:int,created:int,skipped:int,people:array<int,array{email:string,outcome:string,user_id:int,name:string}>}
 */
function pcle_bulk_enroll( $program_id, $emails, $create = false ) {
	$program_id = (int) $program_id;
	$create     = $create && current_user_can( 'create_users' );

	$result = array(
		'enrolled' => 0,
		'created'  => 0,
		'skipped'  => 0,
		'people'   => array(),
	);

	if ( 'pcle_program' !== get_post_type( $program_id ) ) {
		return $result;
	}

	foreach ( (array) $emails as $raw_email ) {
		$email   = sanitize_email( (string) $raw_email );
		$outcome = '';
		$user_id = 0;
		$name    = '';

		if ( ! is_email( $email ) ) {
			$result['people'][] = array(
				'email'   => (string) $raw_email,
				'outcome' => 'invalid',
				'user_id' => 0,
				'name'    => '',
			);
			++$result['skipped'];
			continue;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			if ( ! $create ) {
				$result['people'][] = array(
					'email'   => $email,
					'outcome' => 'unknown',
					'user_id' => 0,
					'name'    => '',
				);
				++$result['skipped'];
				continue;
			}

			$new_id = wp_insert_user(
				array(
					'user_login' => pcle_unique_login_from_email( $email ),
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 20 ),
					'role'       => 'pcle_student',
				)
			);

			if ( is_wp_error( $new_id ) ) {
				$result['people'][] = array(
					'email'   => $email,
					'outcome' => 'failed',
					'user_id' => 0,
					'name'    => '',
				);
				++$result['skipped'];
				continue;
			}

			// WordPress sends the set-password link. No password is ever
			// chosen, transported or seen by whoever is enrolling.
			wp_send_new_user_notifications( $new_id, 'user' );

			++$result['created'];
			$user_id = (int) $new_id;
			$outcome = 'created';
		} else {
			$user_id = (int) $user->ID;
			$name    = $user->display_name;
			// Asked before enrolling: pcle_enroll_user() is an INSERT IGNORE
			// and cannot tell the caller afterwards which of the two it did.
			$outcome = pcle_is_enrolled( $program_id, $user_id ) ? 'already' : 'enrolled';
		}

		pcle_enroll_user( $program_id, $user_id );

		if ( 'already' !== $outcome ) {
			++$result['enrolled'];
		}

		if ( '' === $name ) {
			$person = get_userdata( $user_id );
			$name   = $person ? $person->display_name : '';
		}

		$result['people'][] = array(
			'email'   => $email,
			'outcome' => $outcome,
			'user_id' => $user_id,
			'name'    => $name,
		);
	}

	return $result;
}

/**
 * Bulk-enrolls a cohort from a list of emails (Participants screen).
 *
 * Instructors can enroll EXISTING students by email. Admins (create_users) can
 * also create accounts for unknown emails and send them a set-password email.
 */
function pcle_handle_bulk_enroll() {
	if ( empty( $_POST['pcle_bulk_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcle_bulk_nonce'] ) ), 'pcle_bulk_enroll' ) ) {
		return;
	}
	if ( ! current_user_can( 'view_participant_progress' ) ) {
		return;
	}

	$program_id = isset( $_POST['pcle_bulk_program'] ) ? absint( $_POST['pcle_bulk_program'] ) : 0;
	if ( 'pcle_program' !== get_post_type( $program_id ) ) {
		return;
	}

	$raw    = isset( $_POST['pcle_bulk_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pcle_bulk_emails'] ) ) : '';
	$create = ! empty( $_POST['pcle_bulk_create'] );

	// The counting and the deciding live in pcle_bulk_enroll(); this handler
	// is now only the wp-admin transport around it.
	$outcome  = pcle_bulk_enroll( $program_id, pcle_parse_email_list( $raw ), $create );
	$enrolled = $outcome['enrolled'];
	$created  = $outcome['created'];
	$skipped  = $outcome['skipped'];

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'platform-cle-progress',
				'program'      => $program_id,
				'pcle_message' => 'bulk_done',
				'enrolled'     => $enrolled,
				'created'      => $created,
				'skipped'      => $skipped,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'pcle_handle_bulk_enroll' );

/* =========================================================================
 * REST: managing a programme's participants
 * ========================================================================= */

/**
 * Permission callback for the enrollment routes.
 *
 * Deliberately the same question the report routes ask — may you see this
 * programme's participants? — because the two always travel together: the
 * screen that lists a cohort is the screen that changes it. It differs only
 * in where the programme comes from, a body field rather than the path.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function pcle_rest_guard_enrollment( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
	}

	$program_id = (int) $request['program_id'];

	if ( 'pcle_program' !== get_post_type( $program_id ) ) {
		return new WP_Error( 'pcle_not_found', __( 'Not found.', 'platform-cle' ), array( 'status' => 404 ) );
	}

	if ( ! pcle_user_can_edit_program( $program_id ) ) {
		return new WP_Error( 'pcle_cannot_enroll', __( 'You may not manage this programme\'s participants.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Enrols a list of email addresses into a programme.
 *
 * Existing accounts only. An address nobody holds comes back as `unknown`
 * rather than becoming a new account: creating accounts is a heavier decision
 * than enrolling — it sends mail to a stranger and is awkward to undo — and
 * it stays in wp-admin until it is built here deliberately.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_enroll( $request ) {
	$program_id = (int) $request['program_id'];
	$emails     = pcle_parse_email_list( $request['emails'] );

	if ( empty( $emails ) ) {
		return rest_ensure_response(
			array(
				'enrolled' => 0,
				'created'  => 0,
				'skipped'  => 0,
				'people'   => array(),
			)
		);
	}

	return rest_ensure_response( pcle_bulk_enroll( $program_id, $emails, false ) );
}

/**
 * Removes one participant from a programme.
 *
 * Enrollment only. The account, and everything it has recorded — progress,
 * attendance, quiz attempts — is left alone, because unenrolling someone by
 * mistake should cost an enrollment row and not a term's work.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_unenroll( $request ) {
	$program_id = (int) $request['program_id'];
	$user_id    = (int) $request['user_id'];

	if ( ! get_userdata( $user_id ) ) {
		return new WP_Error( 'pcle_no_such_user', __( 'That person could not be found.', 'platform-cle' ), array( 'status' => 404 ) );
	}

	pcle_unenroll_user( $program_id, $user_id );

	return rest_ensure_response(
		array(
			'program_id' => $program_id,
			'user_id'    => $user_id,
			'enrolled'   => pcle_is_enrolled( $program_id, $user_id ),
		)
	);
}

/**
 * Registers the enrollment routes.
 *
 * POST and DELETE on one route in a single call, rather than two calls for
 * the same path relying on the server merging them.
 */
function pcle_register_enrollment_routes() {
	$program_arg = array(
		'required'          => true,
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
	);

	register_rest_route(
		'platform-cle/v1',
		'/enrollments',
		array(
			array(
				'methods'             => 'POST',
				'callback'            => 'pcle_rest_enroll',
				'permission_callback' => 'pcle_rest_guard_enrollment',
				'args'                => array(
					'program_id' => $program_arg,
					/*
					 * A pasted list, not an array: it is what the textarea in
					 * wp-admin sends and what the frontend's own textarea
					 * sends, and pcle_parse_email_list() is then the single
					 * answer to what "a, b; c" means.
					 */
					'emails'     => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'pcle_rest_unenroll',
				'permission_callback' => 'pcle_rest_guard_enrollment',
				'args'                => array(
					'program_id' => $program_arg,
					'user_id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_enrollment_routes' );
