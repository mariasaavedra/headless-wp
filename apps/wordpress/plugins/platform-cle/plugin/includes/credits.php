<?php
/**
 * CLE credit hours per jurisdiction.
 *
 * The programme carries the hours; they are not derived from anything. How
 * many hours a course is worth, and in which state, is an accreditation
 * decision made by a person and entered here — not something this plugin can
 * compute from module counts or session lengths.
 *
 * Deliberately NOT connected to attendance (attendance.php). Attendance is a
 * record of who was in the room; these are the hours the course is approved
 * for. Wiring one into the other would invent a credit rule nobody signed off
 * on.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jurisdictions a programme can be accredited in.
 *
 * Single source of truth: the meta keys, the admin fields, the API output and
 * the certificate all derive from this, so adding a state is one line here.
 *
 * @return array<string,string> Code => display name.
 */
function pcle_jurisdictions() {
	/**
	 * Filters the jurisdictions offered for accreditation.
	 *
	 * @param array<string,string> $jurisdictions Code => display name.
	 */
	return apply_filters(
		'pcle_jurisdictions',
		array(
			'ks' => __( 'Kansas', 'platform-cle' ),
			'mo' => __( 'Missouri', 'platform-cle' ),
		)
	);
}

/**
 * Meta key holding a programme's approved hours for one jurisdiction.
 *
 * @param string $code Jurisdiction code.
 * @return string
 */
function pcle_credit_hours_meta_key( $code ) {
	return '_pcle_credit_hours_' . sanitize_key( $code );
}

/**
 * Approved credit hours for a programme, keyed by jurisdiction.
 *
 * These are NOT additive. An attorney admitted in both states reports the
 * same seat time to each bar; the two numbers can differ because the bars
 * approved the course differently, but adding them together would claim
 * hours that were never sat. There is deliberately no "total" helper.
 *
 * @param int $program_id Programme ID.
 * @return array<string,float> Code => hours (0.0 when unset).
 */
function pcle_get_credit_hours( $program_id ) {
	$hours = array();

	foreach ( pcle_jurisdictions() as $code => $label ) {
		$raw            = get_post_meta( (int) $program_id, pcle_credit_hours_meta_key( $code ), true );
		$hours[ $code ] = '' === $raw ? 0.0 : (float) $raw;
	}

	return $hours;
}

/**
 * Has anyone actually set hours for this programme?
 *
 * A programme with no hours entered cannot support a credit claim, and the
 * certificate needs to know the difference between "zero hours" and "nobody
 * has decided yet".
 *
 * @param int $program_id Programme ID.
 * @return bool
 */
function pcle_has_credit_hours( $program_id ) {
	foreach ( pcle_get_credit_hours( $program_id ) as $value ) {
		if ( $value > 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Normalises an hours input.
 *
 * Bars award credit in quarter-hour increments, so anything finer is a typo
 * rather than a real value. Negative hours are meaningless.
 *
 * @param mixed $value Raw input.
 * @return float
 */
function pcle_sanitize_credit_hours( $value ) {
	$hours = (float) $value;

	if ( $hours <= 0 ) {
		return 0.0;
	}

	return round( $hours * 4 ) / 4;
}

/* =========================================================================
 * Admin
 * ========================================================================= */

/**
 * Adds the credit-hours box to the Programme editor.
 */
function pcle_add_credits_metabox() {
	add_meta_box(
		'pcle_credit_hours',
		__( 'CLE Credit Hours', 'platform-cle' ),
		'pcle_render_credits_metabox',
		'pcle_program',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'pcle_add_credits_metabox' );

/**
 * Renders one hours field per jurisdiction.
 *
 * @param WP_Post $post Programme being edited.
 */
function pcle_render_credits_metabox( $post ) {
	$hours = pcle_get_credit_hours( $post->ID );

	wp_nonce_field( 'pcle_save_credit_hours', 'pcle_credit_hours_nonce' );

	foreach ( pcle_jurisdictions() as $code => $label ) {
		$field = 'pcle_credit_hours_' . esc_attr( $code );

		echo '<p>';
		printf(
			'<label for="%1$s"><strong>%2$s</strong></label><br />',
			esc_attr( $field ),
			esc_html( $label )
		);
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" min="0" step="0.25" style="width:100%%;" />',
			esc_attr( $field ),
			esc_attr( $hours[ $code ] > 0 ? (string) $hours[ $code ] : '' )
		);
		echo '</p>';
	}

	echo '<p class="description">';
	esc_html_e( 'Hours this programme is approved for in each jurisdiction, in quarter-hour steps. Leave blank if it is not accredited there. These are entered from the accreditation paperwork, not calculated.', 'platform-cle' );
	echo '</p>';
}

/**
 * Saves the credit hours.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post being saved.
 */
function pcle_save_credit_hours( $post_id, $post ) {
	if ( 'pcle_program' !== $post->post_type ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['pcle_credit_hours_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcle_credit_hours_nonce'] ) ), 'pcle_save_credit_hours' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( pcle_jurisdictions() as $code => $label ) {
		$field = 'pcle_credit_hours_' . $code;
		$key   = pcle_credit_hours_meta_key( $code );

		if ( ! isset( $_POST[ $field ] ) || '' === trim( (string) wp_unslash( $_POST[ $field ] ) ) ) {
			delete_post_meta( $post_id, $key );
			continue;
		}

		$hours = pcle_sanitize_credit_hours( wp_unslash( $_POST[ $field ] ) );

		if ( $hours <= 0 ) {
			delete_post_meta( $post_id, $key );
			continue;
		}

		update_post_meta( $post_id, $key, $hours );
	}
}
add_action( 'save_post', 'pcle_save_credit_hours', 10, 2 );

/**
 * Shapes the credit hours for the API.
 *
 * @param int $program_id Programme ID.
 * @return array<int,array{jurisdiction:string, label:string, hours:float}>
 */
function pcle_rest_shape_credit_hours( $program_id ) {
	$hours = pcle_get_credit_hours( $program_id );
	$out   = array();

	foreach ( pcle_jurisdictions() as $code => $label ) {
		$out[] = array(
			'jurisdiction' => $code,
			'label'        => $label,
			'hours'        => (float) $hours[ $code ],
		);
	}

	return $out;
}
