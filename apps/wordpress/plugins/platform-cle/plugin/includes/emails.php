<?php
/**
 * Transactional emails.
 *
 * Two notifications, both decoupled so they can be reused (e.g. by the future
 * payment → enrollment bridge):
 *   - Enrollment confirmation: sent when a user is newly enrolled in a program
 *     (via the `hcle_user_enrolled` action fired in enrollment.php).
 *   - Session reminder: a WP-Cron job emails enrolled students about live
 *     sessions happening within the next window (default 24h), de-duplicated
 *     per event date.
 *
 * All subjects/bodies/headers are filterable. Sending uses wp_mail(); on hosts
 * without a mailer, configure SMTP for deliverability.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cron hook name for session reminders. */
const HCLE_REMINDER_CRON = 'hcle_session_reminder_cron';

/**
 * Standard email headers (plain text).
 *
 * @return string[]
 */
function hcle_email_headers() {
	return apply_filters( 'hcle_email_headers', array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

/* =========================================================================
 * 1) ENROLLMENT CONFIRMATION
 * ========================================================================= */

/**
 * Sends the enrollment confirmation when a user is newly enrolled.
 *
 * Hooked to `hcle_user_enrolled` (fired only on a genuinely new enrollment, so
 * re-saving the participants screen does not resend).
 *
 * @param int $program_id Program ID.
 * @param int $user_id    User ID.
 */
function hcle_send_enrollment_email( $program_id, $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return;
	}

	$program_title = get_the_title( $program_id );
	$program_url   = get_permalink( $program_id );
	$dashboard     = function_exists( 'hcle_get_front_door_url' ) ? hcle_get_front_door_url() : home_url( '/' );

	/* translators: %s: program title. */
	$subject = sprintf( __( 'You are enrolled: %s', 'habeas-cle' ), $program_title );

	$lines = array(
		sprintf(
			/* translators: 1: display name, 2: program title. */
			__( 'Hi %1$s,', 'habeas-cle' ),
			$user->display_name
		),
		'',
		sprintf(
			/* translators: %s: program title. */
			__( 'You have been enrolled in the training program "%s".', 'habeas-cle' ),
			$program_title
		),
		'',
		__( 'Open the program:', 'habeas-cle' ) . ' ' . $program_url,
		__( 'Your training dashboard:', 'habeas-cle' ) . ' ' . $dashboard,
		'',
		__( 'See you inside.', 'habeas-cle' ),
	);
	$body = implode( "\n", $lines );

	$subject = apply_filters( 'hcle_enrollment_email_subject', $subject, $program_id, $user_id );
	$body    = apply_filters( 'hcle_enrollment_email_body', $body, $program_id, $user_id );

	wp_mail( $user->user_email, $subject, $body, hcle_email_headers() );
}
add_action( 'hcle_user_enrolled', 'hcle_send_enrollment_email', 10, 2 );

/* =========================================================================
 * 2) SESSION REMINDERS (WP-Cron)
 * ========================================================================= */

/**
 * Schedules the reminder cron. Called on plugin activation.
 */
function hcle_schedule_reminder_cron() {
	if ( ! wp_next_scheduled( HCLE_REMINDER_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', HCLE_REMINDER_CRON );
	}
}

/**
 * Clears the reminder cron. Called on plugin deactivation.
 */
function hcle_clear_reminder_cron() {
	$timestamp = wp_next_scheduled( HCLE_REMINDER_CRON );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, HCLE_REMINDER_CRON );
	}
}

/**
 * Emails enrolled students about upcoming live sessions.
 *
 * Runs hourly. Finds events whose date is within the reminder window (default
 * 24h ahead), and emails the enrolled students of the event's program. Each
 * event is reminded once per date value (rescheduling re-arms it).
 */
function hcle_send_session_reminders() {
	$window = (int) apply_filters( 'hcle_reminder_window', DAY_IN_SECONDS );
	$now    = time();

	$events = get_posts(
		array(
			'post_type'   => 'hcle_event',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_key'    => '_hcle_event_datetime', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	foreach ( $events as $event ) {
		$raw = hcle_get_event_datetime( $event->ID );
		if ( '' === $raw ) {
			continue;
		}

		$dt = date_create_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );
		if ( ! $dt ) {
			continue;
		}
		$ts = $dt->getTimestamp();

		// Only sessions in the [now, now + window] range.
		if ( $ts < $now || $ts > $now + $window ) {
			continue;
		}

		// De-dupe: skip if we already reminded for this exact date.
		if ( get_post_meta( $event->ID, '_hcle_reminder_sent', true ) === $raw ) {
			continue;
		}

		$program_id = hcle_get_program_for_post( $event->ID );
		if ( ! $program_id ) {
			continue;
		}

		$students = hcle_get_program_students( $program_id, true );
		$when     = hcle_format_event_datetime( $event->ID );

		foreach ( $students as $student ) {
			hcle_send_reminder_email( $student, $event, $when );
		}

		update_post_meta( $event->ID, '_hcle_reminder_sent', $raw );
	}
}
add_action( HCLE_REMINDER_CRON, 'hcle_send_session_reminders' );

/**
 * Sends one session-reminder email.
 *
 * @param WP_User $user  Recipient.
 * @param WP_Post $event The session.
 * @param string  $when  Formatted date/time.
 */
function hcle_send_reminder_email( $user, $event, $when ) {
	if ( ! is_email( $user->user_email ) ) {
		return;
	}

	$title = get_the_title( $event->ID );
	$url   = get_permalink( $event->ID );

	/* translators: %s: session title. */
	$subject = sprintf( __( 'Reminder: %s', 'habeas-cle' ), $title );

	$lines = array(
		sprintf(
			/* translators: %s: display name. */
			__( 'Hi %s,', 'habeas-cle' ),
			$user->display_name
		),
		'',
		sprintf(
			/* translators: 1: session title, 2: date/time. */
			__( 'Your upcoming live session "%1$s" is scheduled for %2$s.', 'habeas-cle' ),
			$title,
			$when
		),
		'',
		__( 'Details:', 'habeas-cle' ) . ' ' . $url,
	);
	$body = implode( "\n", $lines );

	$subject = apply_filters( 'hcle_reminder_email_subject', $subject, $event->ID, $user->ID );
	$body    = apply_filters( 'hcle_reminder_email_body', $body, $event->ID, $user->ID );

	wp_mail( $user->user_email, $subject, $body, hcle_email_headers() );
}
