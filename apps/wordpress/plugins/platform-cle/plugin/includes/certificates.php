<?php
/**
 * Completion certificates.
 *
 * SCAFFOLD — not issuing real certificates yet.
 *
 * Everything here works and draws on real records: who the participant is,
 * which programme, when they finished it, the hours it is approved for, and
 * what they attended. What is missing is the accreditation identity — the
 * provider numbers the Kansas and Missouri bars issue, the authorised
 * signatory, and whatever wording each bar requires on the face of the
 * document. None of that can be guessed.
 *
 * So the document is gated. Until `pcle_certificate_accreditation()` returns
 * complete details, every rendering is stamped as a draft with no credit
 * value, in the title, in a banner and in a repeated watermark. That is
 * deliberate and it is the whole safety property of this file: a certificate
 * is a document an attorney may rely on to show a bar they met an obligation,
 * and one that looks finished while carrying placeholder accreditation is
 * worse than no certificate at all.
 *
 * To finish this: fill the `pcle_accreditation` option (see
 * pcle_certificate_accreditation() for the shape), confirm the required
 * wording with each bar, and the gate opens by itself.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the accreditation identity. */
const PCLE_ACCREDITATION_OPTION = 'pcle_accreditation';

/**
 * The organisation's accreditation details.
 *
 * Shape — every key must be non-empty for certificates to become issuable:
 *
 *   provider_name      string  Legal name of the accredited provider.
 *   provider_numbers   array   Jurisdiction code => provider/sponsor number.
 *   signatory_name     string  Who signs.
 *   signatory_title    string  Their title.
 *
 * @return array
 */
function pcle_certificate_accreditation() {
	$stored = get_option( PCLE_ACCREDITATION_OPTION, array() );

	$defaults = array(
		'provider_name'    => '',
		'provider_numbers' => array(),
		'signatory_name'   => '',
		'signatory_title'  => '',
	);

	/**
	 * Filters the accreditation details used on certificates.
	 *
	 * @param array $accreditation Details, merged over the empty defaults.
	 */
	return apply_filters(
		'pcle_certificate_accreditation',
		wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults )
	);
}

/**
 * Reasons this certificate cannot yet be issued for real.
 *
 * Returns an empty array when everything needed is present. Anything in it
 * means the document renders as a draft.
 *
 * @param int $program_id Programme ID.
 * @return string[] Human-readable blockers.
 */
function pcle_certificate_blockers( $program_id ) {
	$blockers      = array();
	$accreditation = pcle_certificate_accreditation();

	if ( '' === trim( (string) $accreditation['provider_name'] ) ) {
		$blockers[] = __( 'The accredited provider name has not been set.', 'platform-cle' );
	}

	if ( empty( $accreditation['provider_numbers'] ) ) {
		$blockers[] = __( 'No bar provider numbers have been recorded.', 'platform-cle' );
	}

	if ( '' === trim( (string) $accreditation['signatory_name'] ) ) {
		$blockers[] = __( 'No authorised signatory has been set.', 'platform-cle' );
	}

	if ( ! pcle_has_credit_hours( $program_id ) ) {
		$blockers[] = __( 'This programme has no approved credit hours entered.', 'platform-cle' );
	}

	return $blockers;
}

/**
 * Can this programme's certificates be issued as valid documents?
 *
 * @param int $program_id Programme ID.
 * @return bool
 */
function pcle_certificate_is_issuable( $program_id ) {
	return array() === pcle_certificate_blockers( $program_id );
}

/**
 * Assembles everything a certificate states about one participant.
 *
 * The completion date is the LATEST module completion, which is when the
 * programme was actually finished. It is null when the programme is
 * unfinished, and also when any completion predates timestamped storage —
 * see pcle_get_module_completed_at(). A missing date is reported as missing
 * rather than filled in with a plausible one.
 *
 * @param int $program_id Programme ID.
 * @param int $user_id    Participant.
 * @return array
 */
function pcle_get_certificate_data( $program_id, $user_id ) {
	$program  = get_post( (int) $program_id );
	$user     = get_userdata( (int) $user_id );
	$progress = pcle_get_program_progress( $program_id, $user_id );

	$completed_at   = null;
	$dates_complete = true;

	foreach ( pcle_get_program_module_ids( $program_id ) as $module_id ) {
		if ( ! pcle_is_module_complete( $module_id, $user_id ) ) {
			continue;
		}

		$stamp = pcle_get_module_completed_at( $module_id, $user_id );

		if ( null === $stamp ) {
			$dates_complete = false;
			continue;
		}

		if ( null === $completed_at || strtotime( $stamp ) > strtotime( $completed_at ) ) {
			$completed_at = $stamp;
		}
	}

	return array(
		'participant'      => $user ? $user->display_name : '',
		'participant_id'   => (int) $user_id,
		'program'          => $program ? get_the_title( $program ) : '',
		'program_id'       => (int) $program_id,
		'progress'         => $progress,
		'finished'         => $progress['total'] > 0 && $progress['completed'] === $progress['total'],
		'completed_at'     => $completed_at,
		'dates_complete'   => $dates_complete,
		'credit_hours'     => pcle_get_credit_hours( $program_id ),
		'attendance'       => pcle_get_program_attendance( $program_id, $user_id ),
		'accreditation'    => pcle_certificate_accreditation(),
		'blockers'         => pcle_certificate_blockers( $program_id ),
		'issued_at'        => current_time( 'mysql' ),
	);
}

/**
 * Renders the certificate as a standalone HTML document.
 *
 * HTML rather than a PDF binary: the plugin carries no Composer dependencies
 * by design, and a browser's "print to PDF" produces the same artefact
 * without adding one. The page is print-styled for that.
 *
 * @param int $program_id Programme ID.
 * @param int $user_id    Participant.
 * @return string Complete HTML document.
 */
function pcle_render_certificate( $program_id, $user_id ) {
	$data     = pcle_get_certificate_data( $program_id, $user_id );
	$is_draft = ! empty( $data['blockers'] );

	$title = $is_draft
		? __( 'DRAFT — not valid for CLE credit', 'platform-cle' )
		: __( 'Certificate of Completion', 'platform-cle' );

	ob_start();
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		body { font-family: Georgia, "Times New Roman", serif; color: #18181b; margin: 0; padding: 3rem 1.5rem; background: #fafafa; }
		.sheet { position: relative; max-width: 46rem; margin: 0 auto; background: #fff; border: 1px solid #d4d4d8; padding: 3.5rem; overflow: hidden; }
		.draft-banner { background: #7f1d1d; color: #fff; padding: 1rem 1.25rem; margin: 0 auto 1.5rem; max-width: 46rem; font-family: system-ui, sans-serif; }
		.draft-banner h2 { margin: 0 0 .5rem; font-size: 1.05rem; letter-spacing: .02em; }
		.draft-banner ul { margin: .5rem 0 0; padding-left: 1.25rem; font-size: .875rem; }
		.watermark { position: absolute; inset: 0; pointer-events: none; display: flex; align-items: center; justify-content: center; }
		.watermark span { font-family: system-ui, sans-serif; font-size: 3.5rem; font-weight: 700; color: rgba(127, 29, 29, .12); transform: rotate(-24deg); text-align: center; line-height: 1.2; }
		h1 { font-size: 1.75rem; text-align: center; margin: 0 0 2rem; }
		.eyebrow { text-align: center; text-transform: uppercase; letter-spacing: .18em; font-size: .75rem; color: #71717a; font-family: system-ui, sans-serif; }
		.name { font-size: 2rem; text-align: center; margin: 1.5rem 0; }
		.body { text-align: center; line-height: 1.8; }
		table { width: 100%; border-collapse: collapse; margin: 2rem 0; font-family: system-ui, sans-serif; font-size: .9rem; }
		th, td { border-bottom: 1px solid #e4e4e7; padding: .6rem .4rem; text-align: left; }
		th { color: #52525b; font-weight: 600; width: 45%; }
		.missing { color: #7f1d1d; font-style: italic; }
		.sign { margin-top: 3rem; display: flex; justify-content: space-between; gap: 2rem; font-family: system-ui, sans-serif; font-size: .85rem; }
		.sign div { flex: 1; border-top: 1px solid #a1a1aa; padding-top: .5rem; color: #52525b; }
		@media print { body { background: #fff; padding: 0; } .sheet { border: 0; } }
	</style>
</head>
<body>

<?php if ( $is_draft ) : ?>
	<div class="draft-banner">
		<h2><?php esc_html_e( 'DRAFT — this document is not valid for CLE credit', 'platform-cle' ); ?></h2>
		<p style="margin:0;font-size:.875rem;">
			<?php esc_html_e( 'It is a preview of the certificate layout. Do not send it to a participant and do not submit it to a bar. Outstanding:', 'platform-cle' ); ?>
		</p>
		<ul>
			<?php foreach ( $data['blockers'] as $blocker ) : ?>
				<li><?php echo esc_html( $blocker ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<div class="sheet">
	<?php if ( $is_draft ) : ?>
		<div class="watermark" aria-hidden="true">
			<span><?php esc_html_e( 'DRAFT', 'platform-cle' ); ?><br /><?php esc_html_e( 'NOT VALID FOR CREDIT', 'platform-cle' ); ?></span>
		</div>
	<?php endif; ?>

	<p class="eyebrow">
		<?php
		echo esc_html(
			'' !== $data['accreditation']['provider_name']
				? $data['accreditation']['provider_name']
				: __( '[accredited provider not set]', 'platform-cle' )
		);
		?>
	</p>

	<h1><?php esc_html_e( 'Certificate of Completion', 'platform-cle' ); ?></h1>

	<p class="body"><?php esc_html_e( 'This certifies that', 'platform-cle' ); ?></p>
	<p class="name"><?php echo esc_html( $data['participant'] ); ?></p>
	<p class="body">
		<?php esc_html_e( 'has completed the continuing legal education programme', 'platform-cle' ); ?><br />
		<strong><?php echo esc_html( $data['program'] ); ?></strong>
	</p>

	<table>
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Completed on', 'platform-cle' ); ?></th>
				<td>
					<?php if ( ! $data['finished'] ) : ?>
						<span class="missing"><?php esc_html_e( 'Programme not yet complete', 'platform-cle' ); ?></span>
					<?php elseif ( null === $data['completed_at'] ) : ?>
						<span class="missing"><?php esc_html_e( 'Date not recorded', 'platform-cle' ); ?></span>
					<?php else : ?>
						<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $data['completed_at'] ) ) ); ?>
						<?php if ( ! $data['dates_complete'] ) : ?>
							<span class="missing"> — <?php esc_html_e( 'some completions predate date tracking', 'platform-cle' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Modules completed', 'platform-cle' ); ?></th>
				<td><?php echo esc_html( $data['progress']['completed'] . ' / ' . $data['progress']['total'] ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Live sessions attended', 'platform-cle' ); ?></th>
				<td><?php echo esc_html( $data['attendance']['attended'] . ' / ' . $data['attendance']['total'] ); ?></td>
			</tr>
			<?php foreach ( pcle_jurisdictions() as $code => $label ) : ?>
				<tr>
					<th scope="row">
						<?php
						/* translators: %s: jurisdiction name. */
						printf( esc_html__( '%s credit hours', 'platform-cle' ), esc_html( $label ) );
						?>
					</th>
					<td>
						<?php if ( $data['credit_hours'][ $code ] > 0 ) : ?>
							<?php echo esc_html( number_format_i18n( $data['credit_hours'][ $code ], 2 ) ); ?>
							<?php
							$number = isset( $data['accreditation']['provider_numbers'][ $code ] )
								? $data['accreditation']['provider_numbers'][ $code ]
								: '';
							?>
							<?php if ( '' !== $number ) : ?>
								<?php
								/* translators: %s: provider number. */
								printf( esc_html__( '(provider no. %s)', 'platform-cle' ), esc_html( $number ) );
								?>
							<?php else : ?>
								<span class="missing"><?php esc_html_e( '(provider number not set)', 'platform-cle' ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span class="missing"><?php esc_html_e( 'Not accredited in this jurisdiction', 'platform-cle' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="sign">
		<div>
			<?php
			echo esc_html(
				'' !== $data['accreditation']['signatory_name']
					? $data['accreditation']['signatory_name']
					: __( '[signatory not set]', 'platform-cle' )
			);
			?>
			<br />
			<?php echo esc_html( $data['accreditation']['signatory_title'] ); ?>
		</div>
		<div>
			<?php esc_html_e( 'Issued', 'platform-cle' ); ?>
			<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $data['issued_at'] ) ) ); ?>
		</div>
	</div>
</div>

</body>
</html>
	<?php
	return (string) ob_get_clean();
}

/* =========================================================================
 * Viewing
 * ========================================================================= */

/**
 * Serves `?pcle_certificate=<program_id>[&user=<id>]`.
 *
 * A participant may see their own; staff may see anyone's, which is what
 * makes the draft reviewable before anything is issued.
 */
function pcle_handle_certificate_request() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
	if ( empty( $_GET['pcle_certificate'] ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$program_id = absint( $_GET['pcle_certificate'] );
	$user_id    = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : get_current_user_id();
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}

	if ( 'pcle_program' !== get_post_type( $program_id ) ) {
		wp_die( esc_html__( 'Programme not found.', 'platform-cle' ), '', array( 'response' => 404 ) );
	}

	$is_self = get_current_user_id() === $user_id;

	if ( ! $is_self && ! pcle_user_is_staff() ) {
		wp_die( esc_html__( 'You may only view your own certificate.', 'platform-cle' ), '', array( 'response' => 403 ) );
	}

	if ( $is_self && ! pcle_can_access_post( $program_id ) ) {
		wp_die( esc_html__( 'You are not enrolled in this programme.', 'platform-cle' ), '', array( 'response' => 403 ) );
	}

	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	// phpcs:ignore WordPress.Security.EscapeOutput -- a complete, escaped document.
	echo pcle_render_certificate( $program_id, $user_id );
	exit;
}
add_action( 'template_redirect', 'pcle_handle_certificate_request' );
