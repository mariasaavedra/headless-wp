<?php
/**
 * Attendance at live sessions.
 *
 * Marked by instructors, not by participants: progress is something a student
 * records about their own reading, attendance is one person vouching that
 * another was in the room. The table keeps who marked it for that reason.
 *
 * Deliberately independent of credits.php. Nothing here feeds the hours a
 * programme is worth, and nothing here is required for them. Whether a bar
 * would accept these records as verification is a question for the person who
 * files them, and coupling the two in code would answer it on their behalf.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records that a participant attended a session.
 *
 * @param int $event_id  Schedule event ID.
 * @param int $user_id   Participant.
 * @param int $marked_by Instructor doing the marking (defaults to current user).
 * @return bool False if the arguments do not describe a real session/user.
 */
function pcle_mark_attendance( $event_id, $user_id, $marked_by = 0 ) {
	global $wpdb;

	$event_id  = (int) $event_id;
	$user_id   = (int) $user_id;
	$marked_by = $marked_by ? (int) $marked_by : get_current_user_id();

	if ( ! $user_id || 'pcle_event' !== get_post_type( $event_id ) ) {
		return false;
	}

	// INSERT IGNORE: re-marking keeps the original assertion and its author,
	// rather than reattributing it to whoever opened the screen last.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . pcle_attendance_table() . ' (user_id, event_id, marked_at, marked_by) VALUES (%d, %d, %s, %d)',
			$user_id,
			$event_id,
			current_time( 'mysql' ),
			$marked_by
		)
	);

	return true;
}

/**
 * Removes an attendance record.
 *
 * @param int $event_id Schedule event ID.
 * @param int $user_id  Participant.
 * @return bool
 */
function pcle_unmark_attendance( $event_id, $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		pcle_attendance_table(),
		array(
			'user_id'  => $user_id,
			'event_id' => (int) $event_id,
		),
		array( '%d', '%d' )
	);

	return true;
}

/**
 * Did this participant attend this session?
 *
 * @param int $event_id Schedule event ID.
 * @param int $user_id  Participant (defaults to the current user).
 * @return bool
 */
function pcle_has_attended( $event_id, $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT 1 FROM ' . pcle_attendance_table() . ' WHERE user_id = %d AND event_id = %d',
			$user_id,
			(int) $event_id
		)
	);
}

/**
 * Everyone recorded as attending a session.
 *
 * @param int $event_id Schedule event ID.
 * @return int[] User IDs.
 */
function pcle_get_event_attendee_ids( $event_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT user_id FROM ' . pcle_attendance_table() . ' WHERE event_id = %d',
			(int) $event_id
		)
	);

	return array_map( 'intval', $ids );
}

/**
 * Every scheduled session in a programme, in curriculum order.
 *
 * @param int $program_id Programme ID.
 * @return int[] Event IDs.
 */
function pcle_get_program_event_ids( $program_id ) {
	$ids = array();

	foreach ( pcle_get_weeks( $program_id ) as $week ) {
		foreach ( pcle_get_events( $week->ID ) as $event ) {
			$ids[] = (int) $event->ID;
		}
	}

	return $ids;
}

/**
 * A participant's attendance across a programme.
 *
 * @param int $program_id Programme ID.
 * @param int $user_id    Participant (defaults to the current user).
 * @return array{attended:int, total:int, event_ids:int[]}
 */
function pcle_get_program_attendance( $program_id, $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$events  = pcle_get_program_event_ids( $program_id );

	if ( ! $events || ! $user_id ) {
		return array(
			'attended'  => 0,
			'total'     => count( $events ),
			'event_ids' => array(),
		);
	}

	$placeholders = implode( ',', array_fill( 0, count( $events ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$attended = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT event_id FROM ' . pcle_attendance_table() . " WHERE user_id = %d AND event_id IN ({$placeholders})",
			array_merge( array( $user_id ), $events )
		)
	);

	$attended = array_map( 'intval', $attended );

	return array(
		'attended'  => count( $attended ),
		'total'     => count( $events ),
		'event_ids' => $attended,
	);
}

/* =========================================================================
 * Admin screen
 * ========================================================================= */

/**
 * "Attendance" submenu under the Platform CLE menu.
 *
 * Gated on staff rather than a new capability: adding one would need every
 * existing site to re-activate the plugin before instructors could reach the
 * screen, and `edit_pcle_contents` is already this plugin's definition of
 * teaching staff.
 */
function pcle_register_attendance_admin_page() {
	add_submenu_page(
		'platform-cle',
		__( 'Session Attendance', 'platform-cle' ),
		__( 'Session Attendance', 'platform-cle' ),
		'edit_pcle_contents',
		'platform-cle-attendance',
		'pcle_render_attendance_admin_page'
	);
}
add_action( 'admin_menu', 'pcle_register_attendance_admin_page' );

/**
 * Saves the attendance form.
 *
 * Runs on admin_init, like the enrollment screen, so the redirect happens
 * before any output.
 */
function pcle_handle_attendance_save() {
	if ( empty( $_POST['pcle_attendance_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcle_attendance_nonce'] ) ), 'pcle_save_attendance' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'platform-cle' ) );
	}
	if ( ! pcle_user_is_staff() ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'platform-cle' ) );
	}

	$event_id = isset( $_POST['pcle_event_id'] ) ? absint( $_POST['pcle_event_id'] ) : 0;
	if ( ! $event_id || 'pcle_event' !== get_post_type( $event_id ) ) {
		return;
	}

	// The form posts every participant it displayed, plus the subset ticked;
	// anyone shown but not ticked is explicitly absent, not merely missing.
	$shown    = isset( $_POST['pcle_shown'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pcle_shown'] ) ) : array();
	$attended = isset( $_POST['pcle_attended'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pcle_attended'] ) ) : array();

	foreach ( $shown as $user_id ) {
		if ( in_array( $user_id, $attended, true ) ) {
			pcle_mark_attendance( $event_id, $user_id );
		} else {
			pcle_unmark_attendance( $event_id, $user_id );
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'platform-cle-attendance',
				'event'        => $event_id,
				'pcle_message' => 'attendance_saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'pcle_handle_attendance_save' );

/**
 * Attendance screen: pick a session, tick who was there.
 */
function pcle_render_attendance_admin_page() {
	if ( ! pcle_user_is_staff() ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'platform-cle' ) );
	}

	$events = get_posts(
		array(
			'post_type'   => 'pcle_event',
			'post_status' => array( 'publish', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Session Attendance', 'platform-cle' ) . '</h1>';

	if ( ! $events ) {
		echo '<p>' . esc_html__( 'No scheduled sessions yet.', 'platform-cle' ) . '</p></div>';
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$message  = isset( $_GET['pcle_message'] ) ? sanitize_key( $_GET['pcle_message'] ) : '';
	$selected = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! $selected ) {
		$selected = (int) $events[0]->ID;
	}

	if ( 'attendance_saved' === $message ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Attendance updated.', 'platform-cle' ) . '</p></div>';
	}

	echo '<p class="description">';
	esc_html_e( 'Attendance is a record of who was present. It is kept separately from the credit hours a programme is approved for, and changing it does not change anyone\'s credit.', 'platform-cle' );
	echo '</p>';

	// Session selector.
	echo '<form method="get" style="margin:1em 0;">';
	echo '<input type="hidden" name="page" value="platform-cle-attendance" />';
	echo '<label for="pcle-event-select">' . esc_html__( 'Session:', 'platform-cle' ) . ' </label>';
	echo '<select name="event" id="pcle-event-select" onchange="this.form.submit()">';
	foreach ( $events as $event ) {
		$when = pcle_format_event_datetime( $event->ID );
		printf(
			'<option value="%1$d" %2$s>%3$s</option>',
			(int) $event->ID,
			selected( $selected, (int) $event->ID, false ),
			esc_html( $when ? $event->post_title . ' — ' . $when : $event->post_title )
		);
	}
	echo '</select>';
	echo '</form>';

	$program_id = pcle_get_program_for_post( $selected );
	$students   = $program_id ? pcle_get_program_students( $program_id, true ) : array();

	if ( ! $students ) {
		echo '<p>' . esc_html__( 'Nobody is enrolled in this session\'s programme yet.', 'platform-cle' ) . '</p></div>';
		return;
	}

	$attendees = pcle_get_event_attendee_ids( $selected );

	echo '<form method="post">';
	wp_nonce_field( 'pcle_save_attendance', 'pcle_attendance_nonce' );
	printf( '<input type="hidden" name="pcle_event_id" value="%d" />', (int) $selected );

	echo '<table class="wp-list-table widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th style="width:80px;">' . esc_html__( 'Present', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Participant', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'platform-cle' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $students as $student ) {
		$is_present = in_array( (int) $student->ID, $attendees, true );

		echo '<tr>';
		printf( '<td><input type="hidden" name="pcle_shown[]" value="%d" />', (int) $student->ID );
		printf(
			'<input type="checkbox" name="pcle_attended[]" value="%1$d" %2$s aria-label="%3$s" /></td>',
			(int) $student->ID,
			checked( $is_present, true, false ),
			/* translators: %s: participant name. */
			esc_attr( sprintf( __( 'Mark %s present', 'platform-cle' ), $student->display_name ) )
		);
		printf( '<td>%s</td>', esc_html( $student->display_name ) );
		printf( '<td>%s</td>', esc_html( $student->user_email ) );
		echo '</tr>';
	}

	echo '</tbody></table>';
	submit_button( __( 'Save attendance', 'platform-cle' ) );
	echo '</form>';
	echo '</div>';
}
