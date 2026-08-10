<?php
/**
 * Schedule Event meta: session date and time.
 *
 * Provides:
 *   - Registration of the `_hcle_event_datetime` meta (string 'Y-m-d H:i:s', in REST).
 *   - A meta box with a datetime-local field on the event screen.
 *   - Secure save (nonce + capability + format validation).
 *   - Helpers to read/format the date respecting the site timezone.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Meta key where we store the event date/time. */
const HCLE_EVENT_DATETIME_META = '_hcle_event_datetime';

/* =========================================================================
 * 1) META REGISTRATION
 * ========================================================================= */

/**
 * Registers the event date meta (exposed in REST).
 */
function hcle_register_event_meta() {
	register_post_meta(
		'hcle_event',
		HCLE_EVENT_DATETIME_META,
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'hcle_sanitize_event_datetime',
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
		)
	);
}
add_action( 'init', 'hcle_register_event_meta' );

/**
 * Normalizes a date value to 'Y-m-d H:i:s' (or '' if invalid).
 *
 * Accepts both 'Y-m-d H:i:s' and the HTML5 input format 'Y-m-d\TH:i'.
 *
 * @param string $value Incoming value.
 * @return string
 */
function hcle_sanitize_event_datetime( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	// Normalize the datetime-local separator (T) to a space.
	$value = str_replace( 'T', ' ', $value );

	// If it comes without seconds (Y-m-d H:i), add them.
	if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
		$value .= ':00';
	}

	$dt = date_create_from_format( 'Y-m-d H:i:s', $value, wp_timezone() );
	if ( ! $dt ) {
		return '';
	}

	// Reject overflowed values (e.g. month 13, day 99), which date_create_from_format
	// silently "corrects" instead of failing.
	$errors = date_get_last_errors();
	if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
		return '';
	}

	return $dt->format( 'Y-m-d H:i:s' );
}

/* =========================================================================
 * 2) META BOX
 * ========================================================================= */

/**
 * Adds the date meta box to the Schedule Event screen.
 */
function hcle_add_event_metabox() {
	add_meta_box(
		'hcle_event_datetime',
		__( 'Session Date & Time', 'habeas-cle' ),
		'hcle_render_event_metabox',
		'hcle_event',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'hcle_add_event_metabox' );

/**
 * Renders the datetime-local field.
 *
 * @param WP_Post $post Event being edited.
 */
function hcle_render_event_metabox( $post ) {
	$raw = (string) get_post_meta( $post->ID, HCLE_EVENT_DATETIME_META, true );

	// 'Y-m-d H:i:s' -> 'Y-m-d\TH:i' for the HTML5 input.
	$input_value = '';
	if ( '' !== $raw ) {
		$dt = date_create_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );
		if ( $dt ) {
			$input_value = $dt->format( 'Y-m-d\TH:i' );
		}
	}

	wp_nonce_field( 'hcle_save_event_datetime', 'hcle_event_datetime_nonce' );

	echo '<p>';
	printf(
		'<label for="hcle_event_datetime_field" class="screen-reader-text">%s</label>',
		esc_html__( 'Session date and time', 'habeas-cle' )
	);
	printf(
		'<input type="datetime-local" id="hcle_event_datetime_field" name="hcle_event_datetime" value="%s" style="width:100%%;" />',
		esc_attr( $input_value )
	);
	echo '</p>';

	echo '<p class="description">';
	esc_html_e( 'Shown to students in the week schedule. Uses the site timezone.', 'habeas-cle' );
	echo '</p>';
}

/* =========================================================================
 * 3) SECURE SAVE
 * ========================================================================= */

/**
 * Saves the event date.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    The post object.
 */
function hcle_save_event_datetime( $post_id, $post ) {
	if ( 'hcle_event' !== $post->post_type ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Nonce: if our field isn't present, it's not our form.
	if ( ! isset( $_POST['hcle_event_datetime_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hcle_event_datetime_nonce'] ) ), 'hcle_save_event_datetime' )
	) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$value = isset( $_POST['hcle_event_datetime'] )
		? hcle_sanitize_event_datetime( wp_unslash( $_POST['hcle_event_datetime'] ) )
		: '';

	if ( '' !== $value ) {
		update_post_meta( $post_id, HCLE_EVENT_DATETIME_META, $value );
	} else {
		delete_post_meta( $post_id, HCLE_EVENT_DATETIME_META );
	}
}
add_action( 'save_post', 'hcle_save_event_datetime', 10, 2 );

/* =========================================================================
 * 4) READ / FORMAT HELPERS
 * ========================================================================= */

/**
 * Returns the event's raw date ('Y-m-d H:i:s') or ''.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function hcle_get_event_datetime( $event_id ) {
	return (string) get_post_meta( $event_id, HCLE_EVENT_DATETIME_META, true );
}

/**
 * Returns the event's date formatted per the site settings.
 *
 * @param int    $event_id Event ID.
 * @param string $format   Optional format; defaults to the site's date + time.
 * @return string Empty string if there is no date.
 */
function hcle_format_event_datetime( $event_id, $format = '' ) {
	$raw = hcle_get_event_datetime( $event_id );
	if ( '' === $raw ) {
		return '';
	}

	$dt = date_create_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );
	if ( ! $dt ) {
		return '';
	}

	if ( '' === $format ) {
		$format = get_option( 'date_format' ) . ' · ' . get_option( 'time_format' );
	}

	// wp_date respects the site timezone and translation.
	return wp_date( $format, $dt->getTimestamp() );
}
