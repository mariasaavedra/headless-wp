<?php
/**
 * Platform CLE progress tracking (MVP, via user meta).
 *
 * Model: a single user meta `_pcle_completed_modules` per user, storing an array
 * of completed module IDs. Week/program progress is COMPUTED over the hierarchy
 * (relationships.php), not stored: that way it never drifts if the curriculum
 * changes.
 *
 * Includes:
 *   - Completion CRUD (mark / unmark / query).
 *   - Per-week and per-program progress computation.
 *   - REST endpoint for the "mark as complete" button.
 *   - Render helpers (button + bar) and asset loading.
 *   - Participant progress view for instructors.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** User meta key where we store the completed modules. */
const PCLE_PROGRESS_META = '_pcle_completed_modules';

/* =========================================================================
 * 1) COMPLETION CRUD (user meta)
 * ========================================================================= */

/**
 * Resolves the user ID to use (defaults to the current user).
 *
 * @param int|null $user_id Explicit ID or null.
 * @return int 0 if there is no user.
 */
function pcle_resolve_user_id( $user_id = null ) {
	return $user_id ? (int) $user_id : get_current_user_id();
}

/**
 * List of module IDs completed by a user.
 *
 * @param int|null $user_id User ID (defaults to the current user).
 * @return int[]
 */
function pcle_get_completed_modules( $user_id = null ) {
	$user_id = pcle_resolve_user_id( $user_id );
	if ( ! $user_id ) {
		return array();
	}

	$completed = get_user_meta( $user_id, PCLE_PROGRESS_META, true );
	if ( ! is_array( $completed ) ) {
		return array();
	}

	// Normalize to unique integers.
	return array_values( array_unique( array_map( 'intval', $completed ) ) );
}

/**
 * Did the user complete this module?
 *
 * @param int      $module_id Module ID.
 * @param int|null $user_id   User ID (defaults to the current user).
 * @return bool
 */
function pcle_is_module_complete( $module_id, $user_id = null ) {
	return in_array( (int) $module_id, pcle_get_completed_modules( $user_id ), true );
}

/**
 * Marks a module as complete.
 *
 * Validates that the ID is actually a published pcle_module before saving.
 *
 * @param int      $module_id Module ID.
 * @param int|null $user_id   User ID (defaults to the current user).
 * @return bool True if it ended up marked.
 */
function pcle_mark_module_complete( $module_id, $user_id = null ) {
	$user_id   = pcle_resolve_user_id( $user_id );
	$module_id = (int) $module_id;

	if ( ! $user_id || 'pcle_module' !== get_post_type( $module_id ) ) {
		return false;
	}
	if ( 'publish' !== get_post_status( $module_id ) ) {
		return false;
	}

	$completed = pcle_get_completed_modules( $user_id );
	if ( ! in_array( $module_id, $completed, true ) ) {
		$completed[] = $module_id;
		update_user_meta( $user_id, PCLE_PROGRESS_META, $completed );
	}
	return true;
}

/**
 * Unmarks a module (removes it from completed).
 *
 * @param int      $module_id Module ID.
 * @param int|null $user_id   User ID (defaults to the current user).
 * @return bool
 */
function pcle_unmark_module_complete( $module_id, $user_id = null ) {
	$user_id   = pcle_resolve_user_id( $user_id );
	$module_id = (int) $module_id;
	if ( ! $user_id ) {
		return false;
	}

	$completed = pcle_get_completed_modules( $user_id );
	$filtered  = array_values( array_diff( $completed, array( $module_id ) ) );

	if ( count( $filtered ) !== count( $completed ) ) {
		update_user_meta( $user_id, PCLE_PROGRESS_META, $filtered );
	}
	return true;
}

/* =========================================================================
 * 2) PROGRESS COMPUTATION (over the hierarchy)
 * ========================================================================= */

/**
 * Standard progress structure.
 *
 * @param int $completed Completed modules.
 * @param int $total     Total modules.
 * @return array{completed:int, total:int, percent:int}
 */
function pcle_progress_struct( $completed, $total ) {
	$percent = $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0;
	return array(
		'completed' => (int) $completed,
		'total'     => (int) $total,
		'percent'   => $percent,
	);
}

/**
 * Progress of a week (its published modules).
 *
 * @param int      $week_id Week ID.
 * @param int|null $user_id User ID (defaults to the current user).
 * @return array{completed:int, total:int, percent:int}
 */
function pcle_get_week_progress( $week_id, $user_id = null ) {
	$modules   = pcle_get_modules( $week_id );
	$completed = pcle_get_completed_modules( $user_id );

	$done = 0;
	foreach ( $modules as $module ) {
		if ( in_array( (int) $module->ID, $completed, true ) ) {
			$done++;
		}
	}
	return pcle_progress_struct( $done, count( $modules ) );
}

/**
 * Progress of a whole program (all modules of all its weeks).
 *
 * @param int      $program_id Program ID.
 * @param int|null $user_id    User ID (defaults to the current user).
 * @return array{completed:int, total:int, percent:int}
 */
function pcle_get_program_progress( $program_id, $user_id = null ) {
	$completed = pcle_get_completed_modules( $user_id );
	$total     = 0;
	$done      = 0;

	foreach ( pcle_get_weeks( $program_id ) as $week ) {
		foreach ( pcle_get_modules( $week->ID ) as $module ) {
			$total++;
			if ( in_array( (int) $module->ID, $completed, true ) ) {
				$done++;
			}
		}
	}
	return pcle_progress_struct( $done, $total );
}

/* =========================================================================
 * 3) REST ENDPOINT (button toggle)
 * ========================================================================= */

/**
 * Registers the REST route to mark/unmark progress.
 *
 * POST /wp-json/platform-cle/v1/progress  { module_id, completed }
 * Always operates on the CURRENT USER (never another).
 */
function pcle_register_progress_route() {
	register_rest_route(
		'platform-cle/v1',
		'/progress',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_rest_toggle_progress',
			'permission_callback' => function () {
				// Must be inside the program to record progress.
				return is_user_logged_in() && current_user_can( 'view_cle_content' );
			},
			'args'                => array(
				'module_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'completed' => array(
					'required' => true,
					'type'     => 'boolean',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_progress_route' );

/**
 * REST callback: marks/unmarks and returns the recomputed progress.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_toggle_progress( $request ) {
	$module_id = (int) $request->get_param( 'module_id' );
	$completed = (bool) $request->get_param( 'completed' );

	if ( 'pcle_module' !== get_post_type( $module_id ) ) {
		return new WP_Error(
			'pcle_invalid_module',
			__( 'Invalid module.', 'platform-cle' ),
			array( 'status' => 400 )
		);
	}

	if ( $completed ) {
		pcle_mark_module_complete( $module_id );
	} else {
		pcle_unmark_module_complete( $module_id );
	}

	// Recompute the parent week's progress to refresh the UI.
	$week_id  = pcle_get_parent_id( $module_id );
	$progress = $week_id ? pcle_get_week_progress( $week_id ) : pcle_progress_struct( 0, 0 );

	return rest_ensure_response(
		array(
			'module_id'     => $module_id,
			'completed'     => pcle_is_module_complete( $module_id ),
			'week_progress' => $progress,
		)
	);
}

/* =========================================================================
 * 4) RENDER (button + bar) AND ASSETS
 * ========================================================================= */

/**
 * Returns the HTML for a module's "mark as complete" button.
 *
 * Only for authenticated users with access. The JS (progress.js) handles the
 * click via REST.
 *
 * @param int $module_id Module ID.
 * @return string
 */
function pcle_render_complete_button( $module_id ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'view_cle_content' ) ) {
		return '';
	}
	if ( 'pcle_module' !== get_post_type( $module_id ) ) {
		return '';
	}

	$done  = pcle_is_module_complete( $module_id );
	$label = $done ? __( '✓ Completed', 'platform-cle' ) : __( 'Mark as complete', 'platform-cle' );

	return sprintf(
		'<button type="button" class="pcle-complete-btn%s" data-module-id="%d" aria-pressed="%s">%s</button>',
		$done ? ' is-complete' : '',
		(int) $module_id,
		$done ? 'true' : 'false',
		esc_html( $label )
	);
}

/**
 * Reusable progress bar.
 *
 * @param array  $progress Structure from pcle_progress_struct().
 * @param string $label    Optional text above the bar.
 * @return string
 */
function pcle_render_progress_bar( $progress, $label = '' ) {
	$percent = isset( $progress['percent'] ) ? (int) $progress['percent'] : 0;

	$caption = $label ? esc_html( $label ) . ' ' : '';
	$caption .= sprintf(
		/* translators: 1: completed count, 2: total count, 3: percent. */
		esc_html__( '%1$d / %2$d modules (%3$d%%)', 'platform-cle' ),
		(int) $progress['completed'],
		(int) $progress['total'],
		$percent
	);

	return sprintf(
		'<div class="pcle-progress"><div class="pcle-progress__caption">%s</div>'
		. '<div class="pcle-progress__track" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100">'
		. '<div class="pcle-progress__fill" style="width:%d%%;"></div></div></div>',
		$caption,
		$percent,
		$percent
	);
}

/**
 * Shortcode [pcle_module_progress] — complete button for the current module.
 *
 * Intended for use inside a Module's content.
 *
 * @return string
 */
function pcle_module_progress_shortcode() {
	$module_id = get_the_ID();
	if ( 'pcle_module' !== get_post_type( $module_id ) ) {
		return '';
	}
	return pcle_render_complete_button( $module_id );
}
add_shortcode( 'pcle_module_progress', 'pcle_module_progress_shortcode' );

/**
 * Enqueues the progress JS/CSS on the relevant frontend views.
 */
function pcle_enqueue_progress_assets() {
	$is_cle_view = is_singular( array( 'pcle_module', 'pcle_week', 'pcle_program' ) );

	// Also on any page that uses the "my-programs" block/shortcode.
	$content      = (string) get_post_field( 'post_content', get_queried_object_id() );
	$has_frontdoor = is_singular()
		&& ( has_block( 'platform-cle/my-programs' ) || has_shortcode( $content, 'pcle_my_programs' ) );

	if ( ! $is_cle_view && ! $has_frontdoor ) {
		return;
	}

	wp_enqueue_style(
		'pcle-progress',
		PLATFORM_CLE_PLUGIN_URL . 'assets/progress.css',
		array(),
		PLATFORM_CLE_VERSION
	);

	wp_enqueue_script(
		'pcle-progress',
		PLATFORM_CLE_PLUGIN_URL . 'assets/progress.js',
		array(),
		PLATFORM_CLE_VERSION,
		true
	);

	// Data for the JS: the endpoint URL and the REST nonce (cookie auth).
	wp_localize_script(
		'pcle-progress',
		'pcleProgress',
		array(
			'restUrl' => esc_url_raw( rest_url( 'platform-cle/v1/progress' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'complete'   => __( '✓ Completed', 'platform-cle' ),
				'incomplete' => __( 'Mark as complete', 'platform-cle' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'pcle_enqueue_progress_assets' );

/* =========================================================================
 * 5) PARTICIPANT PROGRESS (instructor view)
 * ========================================================================= */

/**
 * Progress of all students for a given program.
 *
 * @param int $program_id Program ID.
 * @return array<int, array{user:WP_User, progress:array}> Indexed by user ID.
 */
function pcle_get_participant_progress( $program_id ) {
	$students = get_users( array( 'role' => 'pcle_student' ) );
	$rows     = array();

	foreach ( $students as $student ) {
		$rows[ $student->ID ] = array(
			'user'     => $student,
			'progress' => pcle_get_program_progress( $program_id, $student->ID ),
		);
	}
	return $rows;
}

/**
 * "Participant Progress" submenu under the Platform CLE menu.
 */
function pcle_register_progress_admin_page() {
	add_submenu_page(
		'platform-cle',
		__( 'Participant Progress', 'platform-cle' ),
		__( 'Participant Progress', 'platform-cle' ),
		'view_participant_progress',
		'platform-cle-progress',
		'pcle_render_progress_admin_page'
	);
}
add_action( 'admin_menu', 'pcle_register_progress_admin_page' );

/**
 * Participants screen: enrollment + progress, per program.
 */
function pcle_render_progress_admin_page() {
	if ( ! current_user_can( 'view_participant_progress' ) ) {
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

	// Selected program (GET), defaults to the first.
	$selected = isset( $_GET['program'] ) ? absint( $_GET['program'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $selected && $programs ) {
		$selected = (int) $programs[0]->ID;
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Participants &amp; Enrollment', 'platform-cle' ) . '</h1>';

	if ( ! $programs ) {
		echo '<p>' . esc_html__( 'No programs found yet.', 'platform-cle' ) . '</p></div>';
		return;
	}

	// Notices after saving / bulk enrolling.
	$message = isset( $_GET['pcle_message'] ) ? sanitize_key( $_GET['pcle_message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'enrollment_saved' === $message ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Enrollment updated.', 'platform-cle' ) . '</p></div>';
	} elseif ( 'bulk_done' === $message ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$n_enrolled = isset( $_GET['enrolled'] ) ? absint( $_GET['enrolled'] ) : 0;
		$n_created  = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
		$n_skipped  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: 1: enrolled count, 2: created count, 3: skipped count. */
			esc_html__( 'Bulk enrollment done: %1$d enrolled, %2$d new accounts created, %3$d skipped.', 'platform-cle' ),
			(int) $n_enrolled,
			(int) $n_created,
			(int) $n_skipped
		);
		echo '</p></div>';
	}

	// Program selector (GET).
	echo '<form method="get" style="margin:1em 0;">';
	echo '<input type="hidden" name="page" value="platform-cle-progress" />';
	echo '<label for="pcle-program-select">' . esc_html__( 'Program:', 'platform-cle' ) . ' </label>';
	echo '<select name="program" id="pcle-program-select" onchange="this.form.submit()">';
	foreach ( $programs as $program ) {
		printf(
			'<option value="%d"%s>%s</option>',
			(int) $program->ID,
			selected( $selected, $program->ID, false ),
			esc_html( get_the_title( $program ) )
		);
	}
	echo '</select>';
	echo '</form>';

	// Bulk enroll by email.
	$can_create = current_user_can( 'create_users' );
	echo '<div class="card" style="max-width:720px;padding:0 1.25rem 1rem;">';
	echo '<h2>' . esc_html__( 'Bulk enroll by email', 'platform-cle' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=platform-cle-progress' ) ) . '">';
	wp_nonce_field( 'pcle_bulk_enroll', 'pcle_bulk_nonce' );
	printf( '<input type="hidden" name="pcle_bulk_program" value="%d" />', (int) $selected );
	echo '<p><textarea name="pcle_bulk_emails" rows="5" style="width:100%;" placeholder="' . esc_attr__( 'One email per line (or comma-separated)', 'platform-cle' ) . '"></textarea></p>';
	if ( $can_create ) {
		echo '<p><label><input type="checkbox" name="pcle_bulk_create" value="1" checked /> '
			. esc_html__( 'Create a Student account for unknown emails and send them a set-password email.', 'platform-cle' )
			. '</label></p>';
	} else {
		echo '<p class="description">' . esc_html__( 'Unknown emails will be skipped (only administrators can create accounts).', 'platform-cle' ) . '</p>';
	}
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Enroll emails', 'platform-cle' ) . '</button> ';
	echo '<span class="description">' . esc_html__( 'Enrolls the emails into the selected program above.', 'platform-cle' ) . '</span></p>';
	echo '</form>';
	echo '</div>';

	$students = pcle_get_program_students(); // All CLE Students.

	if ( ! $students ) {
		echo '<p>' . esc_html__( 'No students exist yet.', 'platform-cle' ) . ' ';
		printf(
			'<a href="%s">%s</a></p>',
			esc_url( admin_url( 'user-new.php' ) ),
			esc_html__( 'Add a user with the CLE Student role.', 'platform-cle' )
		);
		echo '</div>';
		return;
	}

	// Enrollment form (POST). Each row: checkbox + progress.
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=platform-cle-progress' ) ) . '">';
	wp_nonce_field( 'pcle_save_enrollment', 'pcle_enrollment_nonce' );
	printf( '<input type="hidden" name="pcle_enrollment_program" value="%d" />', (int) $selected );

	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th style="width:90px;">' . esc_html__( 'Enrolled', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Student', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'platform-cle' ) . '</th>';
	echo '<th>' . esc_html__( 'Modules completed', 'platform-cle' ) . '</th>';
	echo '<th style="width:30%;">' . esc_html__( 'Progress', 'platform-cle' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $students as $student ) {
		$is_enrolled = pcle_is_enrolled( $selected, $student->ID );
		$progress    = pcle_get_program_progress( $selected, $student->ID );

		echo '<tr>';
		printf( '<input type="hidden" name="pcle_students[]" value="%d" />', (int) $student->ID );
		printf(
			'<td><input type="checkbox" name="pcle_enrolled[]" value="%d"%s aria-label="%s" /></td>',
			(int) $student->ID,
			checked( $is_enrolled, true, false ),
			esc_attr__( 'Enrolled', 'platform-cle' )
		);
		echo '<td>' . esc_html( $student->display_name ) . '</td>';
		echo '<td>' . esc_html( $student->user_email ) . '</td>';
		if ( $is_enrolled ) {
			printf( '<td>%d / %d</td>', (int) $progress['completed'], (int) $progress['total'] );
			echo '<td>' . wp_kses_post( pcle_render_progress_bar( $progress ) ) . '</td>';
		} else {
			echo '<td>—</td><td>—</td>';
		}
		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save enrollment', 'platform-cle' ) . '</button></p>';
	echo '</form>';
	echo '</div>';
}
