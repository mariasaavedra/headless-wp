<?php
/**
 * Cohort reporting.
 *
 * The question a CLE has to answer about a finished programme: who was
 * enrolled, what did they complete, when, and which sessions did they attend.
 * Under the old serialized user meta that meant loading every user and
 * unserializing in PHP; it is now four queries for any cohort size, and the
 * report is deliberately built that way rather than by calling the per-user
 * helpers in a loop.
 *
 * What this does NOT do is decide anything. It reports the records as they
 * are, including where they are incomplete — a completion with no date says
 * so instead of being quietly dropped or given a plausible one. Whether the
 * result supports a credit claim is a judgement for the person filing it.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per enrolled participant in a programme.
 *
 * Rows carry the raw records, not conclusions. `undated` is the count of
 * completions with no recorded date (they predate timestamped storage), and
 * it is surfaced because a compliance record that silently omits it would
 * overstate what is actually known.
 *
 * @param int $program_id Programme ID.
 * @return array<int,array{
 *     user:WP_User, enrolled_at:string|null, completed:int, total:int,
 *     percent:int, finished:bool, completed_at:string|null, undated:int,
 *     attended:int, sessions:int
 * }>
 */
function pcle_get_program_report( $program_id ) {
	global $wpdb;

	$program_id = (int) $program_id;
	$modules    = pcle_get_program_module_ids( $program_id );
	$events     = pcle_get_program_event_ids( $program_id );

	// 1) The roster, with when each enrollment happened.
	$enrollments_table = pcle_enrollments_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$enrollments = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT user_id, enrolled_at FROM {$enrollments_table} WHERE program_id = %d",
			$program_id
		)
	);

	if ( ! $enrollments ) {
		return array();
	}

	$user_ids   = array();
	$enrolled_at = array();
	foreach ( $enrollments as $row ) {
		$user_ids[]                       = (int) $row->user_id;
		$enrolled_at[ (int) $row->user_id ] = $row->enrolled_at;
	}

	// 2) Progress for the whole cohort at once.
	$progress = array();
	if ( $modules ) {
		$progress_table = pcle_progress_table();
		$placeholders   = implode( ',', array_fill( 0, count( $modules ), '%d' ) );

		// SUM(... IS NULL) counts the completions that carry no date, which is
		// the difference between "not recorded" and "not completed".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id,
				        COUNT(*) AS done,
				        MAX(completed_at) AS last_at,
				        SUM(completed_at IS NULL) AS undated
				 FROM {$progress_table}
				 WHERE module_id IN ({$placeholders})
				 GROUP BY user_id",
				$modules
			)
		);

		foreach ( $rows as $row ) {
			$progress[ (int) $row->user_id ] = $row;
		}
	}

	// 3) Attendance for the whole cohort at once.
	$attendance = array();
	if ( $events ) {
		$attendance_table = pcle_attendance_table();
		$placeholders     = implode( ',', array_fill( 0, count( $events ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS attended
				 FROM {$attendance_table}
				 WHERE event_id IN ({$placeholders})
				 GROUP BY user_id",
				$events
			)
		);

		foreach ( $rows as $row ) {
			$attendance[ (int) $row->user_id ] = (int) $row->attended;
		}
	}

	// 4) The users themselves.
	$users = get_users(
		array(
			'include' => $user_ids,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		)
	);

	$total  = count( $modules );
	$report = array();

	foreach ( $users as $user ) {
		$id      = (int) $user->ID;
		$row     = isset( $progress[ $id ] ) ? $progress[ $id ] : null;
		$done    = $row ? (int) $row->done : 0;
		$undated = $row ? (int) $row->undated : 0;

		$report[ $id ] = array(
			'user'        => $user,
			'enrolled_at' => isset( $enrolled_at[ $id ] ) ? $enrolled_at[ $id ] : null,
			'completed'   => $done,
			'total'       => $total,
			'percent'     => $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0,
			'finished'    => $total > 0 && $done === $total,
			'completed_at' => $row ? $row->last_at : null,
			'undated'     => $undated,
			'attended'    => isset( $attendance[ $id ] ) ? $attendance[ $id ] : 0,
			'sessions'    => count( $events ),
		);
	}

	return $report;
}

/**
 * Neutralises a value that a spreadsheet would treat as a formula.
 *
 * Display names and emails come from people, and Excel/Sheets execute any
 * cell beginning `=`, `+`, `-` or `@`. Someone named `=HYPERLINK(...)` would
 * otherwise run on the machine of whoever opens the export. Prefixing with an
 * apostrophe keeps the text visible and inert.
 *
 * @param string $value Raw cell value.
 * @return string
 */
function pcle_csv_safe( $value ) {
	$value = (string) $value;

	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * The report as CSV rows, header first.
 *
 * @param int $program_id Programme ID.
 * @return array<int,string[]>
 */
function pcle_get_program_report_csv( $program_id ) {
	$credits = pcle_get_credit_hours( $program_id );

	$header = array(
		__( 'Participant', 'platform-cle' ),
		__( 'Email', 'platform-cle' ),
		__( 'Enrolled on', 'platform-cle' ),
		__( 'Modules completed', 'platform-cle' ),
		__( 'Modules total', 'platform-cle' ),
		__( 'Percent', 'platform-cle' ),
		__( 'Finished', 'platform-cle' ),
		__( 'Completed on', 'platform-cle' ),
		__( 'Completions without a date', 'platform-cle' ),
		__( 'Sessions attended', 'platform-cle' ),
		__( 'Sessions total', 'platform-cle' ),
	);

	foreach ( pcle_jurisdictions() as $code => $label ) {
		/* translators: %s: jurisdiction name. */
		$header[] = sprintf( __( '%s credit hours', 'platform-cle' ), $label );
	}

	$rows = array( $header );

	foreach ( pcle_get_program_report( $program_id ) as $row ) {
		$line = array(
			pcle_csv_safe( $row['user']->display_name ),
			pcle_csv_safe( $row['user']->user_email ),
			(string) $row['enrolled_at'],
			(string) $row['completed'],
			(string) $row['total'],
			(string) $row['percent'],
			$row['finished'] ? __( 'yes', 'platform-cle' ) : __( 'no', 'platform-cle' ),
			(string) $row['completed_at'],
			(string) $row['undated'],
			(string) $row['attended'],
			(string) $row['sessions'],
		);

		// The programme's approved hours, repeated per row so each line stands
		// alone once the file is filed or forwarded.
		foreach ( pcle_jurisdictions() as $code => $label ) {
			$line[] = $credits[ $code ] > 0 ? (string) $credits[ $code ] : '';
		}

		$rows[] = $line;
	}

	return $rows;
}

/* =========================================================================
 * Admin
 * ========================================================================= */

/**
 * "Reports" submenu under the Platform CLE menu.
 */
function pcle_register_reports_admin_page() {
	add_submenu_page(
		'platform-cle',
		__( 'Reports', 'platform-cle' ),
		__( 'Reports', 'platform-cle' ),
		'edit_pcle_contents',
		'platform-cle-reports',
		'pcle_render_reports_admin_page'
	);
}
add_action( 'admin_menu', 'pcle_register_reports_admin_page' );

/**
 * Streams the CSV export.
 */
function pcle_handle_report_export() {
	if ( empty( $_GET['pcle_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pcle_export_report' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'platform-cle' ) );
	}
	if ( ! pcle_user_is_staff() ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'platform-cle' ) );
	}

	$program_id = absint( $_GET['pcle_export'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'pcle_program' !== get_post_type( $program_id ) ) {
		wp_die( esc_html__( 'Programme not found.', 'platform-cle' ), '', array( 'response' => 404 ) );
	}

	$filename = sanitize_file_name(
		sprintf( '%s-%s.csv', get_post( $program_id )->post_name, gmdate( 'Y-m-d' ) )
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	$out = fopen( 'php://output', 'w' );
	foreach ( pcle_get_program_report_csv( $program_id ) as $row ) {
		fputcsv( $out, $row );
	}
	fclose( $out );

	exit;
}
add_action( 'admin_init', 'pcle_handle_report_export' );

/**
 * Reports screen: one programme's cohort.
 */
function pcle_render_reports_admin_page() {
	if ( ! pcle_user_is_staff() ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'platform-cle' ) );
	}

	$programs = get_posts(
		array(
			'post_type'   => 'pcle_program',
			'post_status' => array( 'publish', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Reports', 'platform-cle' ) . '</h1>';

	if ( ! $programs ) {
		echo '<p>' . esc_html__( 'No programmes yet.', 'platform-cle' ) . '</p></div>';
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$selected = isset( $_GET['program'] ) ? absint( $_GET['program'] ) : (int) $programs[0]->ID;

	echo '<form method="get" style="margin:1em 0;">';
	echo '<input type="hidden" name="page" value="platform-cle-reports" />';
	echo '<label for="pcle-report-program">' . esc_html__( 'Programme:', 'platform-cle' ) . ' </label>';
	echo '<select name="program" id="pcle-report-program" onchange="this.form.submit()">';
	foreach ( $programs as $program ) {
		printf(
			'<option value="%1$d" %2$s>%3$s</option>',
			(int) $program->ID,
			selected( $selected, (int) $program->ID, false ),
			esc_html( $program->post_title )
		);
	}
	echo '</select>';
	echo '</form>';

	$report  = pcle_get_program_report( $selected );
	$credits = pcle_get_credit_hours( $selected );

	// Approved hours, stated once, since they belong to the programme rather
	// than to any participant.
	echo '<p>';
	echo '<strong>' . esc_html__( 'Approved credit hours:', 'platform-cle' ) . '</strong> ';
	$parts = array();
	foreach ( pcle_jurisdictions() as $code => $label ) {
		$parts[] = $credits[ $code ] > 0
			? esc_html( $label . ': ' . number_format_i18n( $credits[ $code ], 2 ) )
			: esc_html( $label . ': ' . __( 'not accredited', 'platform-cle' ) );
	}
	echo wp_kses_post( implode( ' &nbsp;·&nbsp; ', $parts ) );
	echo '</p>';

	if ( ! $report ) {
		echo '<p>' . esc_html__( 'Nobody is enrolled in this programme yet.', 'platform-cle' ) . '</p></div>';
		return;
	}

	printf(
		'<p><a class="button button-primary" href="%s">%s</a></p>',
		esc_url(
			wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'platform-cle-reports',
						'pcle_export' => $selected,
					),
					admin_url( 'admin.php' )
				),
				'pcle_export_report'
			)
		),
		esc_html__( 'Download CSV', 'platform-cle' )
	);

	echo '<table class="wp-list-table widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Participant', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Enrolled', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Modules', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Completed on', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Sessions', 'platform-cle' ) . '</th>';
	echo '</tr></thead><tbody>';

	$date_format = get_option( 'date_format' );

	foreach ( $report as $row ) {
		echo '<tr>';

		printf(
			'<td><strong>%s</strong><br /><span class="description">%s</span></td>',
			esc_html( $row['user']->display_name ),
			esc_html( $row['user']->user_email )
		);

		printf(
			'<td>%s</td>',
			$row['enrolled_at']
				? esc_html( date_i18n( $date_format, strtotime( $row['enrolled_at'] ) ) )
				: '<span class="description">' . esc_html__( 'not recorded', 'platform-cle' ) . '</span>'
		);

		printf(
			'<td>%1$d / %2$d &nbsp;<span class="description">(%3$d%%)</span></td>',
			(int) $row['completed'],
			(int) $row['total'],
			(int) $row['percent']
		);

		echo '<td>';
		if ( ! $row['finished'] ) {
			echo '<span class="description">' . esc_html__( 'in progress', 'platform-cle' ) . '</span>';
		} elseif ( $row['completed_at'] ) {
			echo esc_html( date_i18n( $date_format, strtotime( $row['completed_at'] ) ) );
		} else {
			echo '<span class="description">' . esc_html__( 'date not recorded', 'platform-cle' ) . '</span>';
		}

		// Say so on the row rather than in a footnote: it changes what the
		// row can be used to claim.
		if ( $row['undated'] > 0 ) {
			echo '<br /><span class="description">';
			printf(
				/* translators: %d: number of completions. */
				esc_html( _n( '%d completion without a date', '%d completions without a date', (int) $row['undated'], 'platform-cle' ) ),
				(int) $row['undated']
			);
			echo '</span>';
		}
		echo '</td>';

		printf( '<td>%1$d / %2$d</td>', (int) $row['attended'], (int) $row['sessions'] );

		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}
