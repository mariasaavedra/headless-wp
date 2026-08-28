<?php
/**
 * Platform CLE progress tracking (MVP, via user meta).
 *
 * Model: one row per (user, module) in the `pcle_progress` table, carrying the
 * completion timestamp. Unit/program progress is COMPUTED over the hierarchy
 * (relationships.php), not stored: that way it never drifts if the curriculum
 * changes.
 *
 * Includes:
 *   - Completion CRUD (mark / unmark / query).
 *   - Per-unit and per-program progress computation.
 *   - REST endpoint for the "mark as complete" button.
 *   - Render helpers (button + bar) and asset loading.
 *   - Participant progress view for instructors.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy user meta key, kept only so the migration in schema.php can find it.
 * Nothing reads it as a source of truth any more.
 */
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
	global $wpdb;

	$user_id = pcle_resolve_user_id( $user_id );
	if ( ! $user_id ) {
		return array();
	}

	$table = pcle_progress_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$ids = $wpdb->get_col(
		$wpdb->prepare( "SELECT module_id FROM {$table} WHERE user_id = %d", $user_id )
	);

	return array_map( 'intval', $ids );
}

/**
 * When a user completed a module.
 *
 * Rows carried over from the old user-meta model have no date: that model
 * never recorded one. NULL there means "before this was tracked", which a
 * credit report has to be able to say rather than guess at.
 *
 * @param int      $module_id Module ID.
 * @param int|null $user_id   User ID (defaults to the current user).
 * @return string|null MySQL datetime in site time, or null.
 */
function pcle_get_module_completed_at( $module_id, $user_id = null ) {
	global $wpdb;

	$user_id = pcle_resolve_user_id( $user_id );
	if ( ! $user_id ) {
		return null;
	}

	$table = pcle_progress_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$value = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT completed_at FROM {$table} WHERE user_id = %d AND module_id = %d",
			$user_id,
			(int) $module_id
		)
	);

	return null === $value ? null : (string) $value;
}

/**
 * Did the user complete this module?
 *
 * @param int      $module_id Module ID.
 * @param int|null $user_id   User ID (defaults to the current user).
 * @return bool
 */
function pcle_is_module_complete( $module_id, $user_id = null ) {
	global $wpdb;

	$user_id = pcle_resolve_user_id( $user_id );
	if ( ! $user_id ) {
		return false;
	}

	$table = pcle_progress_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE user_id = %d AND module_id = %d",
			$user_id,
			(int) $module_id
		)
	);
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

	/*
	 * A module carrying a quiz its author marked as required is not complete
	 * until that quiz has been passed. The switch is per quiz and off by
	 * default — see pcle_quiz_gates_completion() — so this changes nothing for
	 * a programme that has not opted in.
	 *
	 * Enforced here rather than in the REST callback because this function is
	 * the single place completion is recorded: the callback, the admin and any
	 * future importer all come through it, and a gate that only one caller
	 * honours is not a gate.
	 */
	if ( pcle_module_completion_blockers( $module_id, $user_id ) ) {
		return false;
	}

	global $wpdb;

	$table = pcle_progress_table();

	/*
	 * INSERT IGNORE against the UNIQUE (user_id, module_id): completing an
	 * already-complete module is a no-op that preserves the ORIGINAL date.
	 * Re-stamping it on every click would quietly move a completion record
	 * forward in time, which is the sort of thing a credit audit notices.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (user_id, module_id, completed_at) VALUES (%d, %d, %s)",
			$user_id,
			$module_id,
			current_time( 'mysql' )
		)
	);

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

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		pcle_progress_table(),
		array(
			'user_id'   => $user_id,
			'module_id' => $module_id,
		),
		array( '%d', '%d' )
	);

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
 * Progress of a unit (its published modules).
 *
 * @param int      $unit_id Unit ID.
 * @param int|null $user_id User ID (defaults to the current user).
 * @return array{completed:int, total:int, percent:int}
 */
function pcle_get_unit_progress( $unit_id, $user_id = null ) {
	$modules   = pcle_get_modules( $unit_id );
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
 * Progress of a whole program (all modules of all its units).
 *
 * @param int      $program_id Program ID.
 * @param int|null $user_id    User ID (defaults to the current user).
 * @return array{completed:int, total:int, percent:int}
 */
function pcle_get_program_progress( $program_id, $user_id = null ) {
	$completed = pcle_get_completed_modules( $user_id );
	$modules   = pcle_get_program_module_ids( $program_id );

	$done = count( array_intersect( $modules, $completed ) );

	return pcle_progress_struct( $done, count( $modules ) );
}

/**
 * Every module ID under a program, in curriculum order.
 *
 * Walking program → units → modules is the expensive part of any progress
 * computation, and it does not depend on the user. Pulled out so a report
 * over a cohort walks the hierarchy once instead of once per participant.
 *
 * @param int $program_id Program ID.
 * @return int[]
 */
function pcle_get_program_module_ids( $program_id ) {
	$ids = array();

	foreach ( pcle_get_units( $program_id ) as $unit ) {
		foreach ( pcle_get_modules( $unit->ID ) as $module ) {
			$ids[] = (int) $module->ID;
		}
	}

	return $ids;
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

	/*
	 * view_cle_content (checked in the permission callback) only says the
	 * caller is a participant somewhere. Recording progress against a module
	 * means claiming to be working through its program, so it takes the same
	 * per-program access as reading it.
	 */
	if ( ! pcle_can_access_post( $module_id ) ) {
		return new WP_Error(
			'pcle_rest_forbidden',
			__( 'You must be enrolled to track progress on this module.', 'platform-cle' ),
			array( 'status' => 403 )
		);
	}

	if ( $completed ) {
		/*
		 * Refusals here are not failures to save — they are the answer. A
		 * participant who ticks "complete" on a module with an unpassed
		 * required quiz has to be told why, or the button looks broken.
		 */
		$blockers = pcle_module_completion_blockers( $module_id );

		if ( $blockers ) {
			return new WP_Error(
				'pcle_quiz_required',
				__( 'This module has a quiz you need to pass first.', 'platform-cle' ),
				array(
					'status'  => 409,
					'quizzes' => array_map(
						function ( $quiz_id ) {
							return array(
								'id'    => $quiz_id,
								'title' => get_the_title( $quiz_id ),
							);
						},
						$blockers
					),
				)
			);
		}

		pcle_mark_module_complete( $module_id );
	} else {
		pcle_unmark_module_complete( $module_id );
	}

	// Recompute the parent unit's progress to refresh the UI.
	$unit_id  = pcle_get_parent_id( $module_id );
	$progress = $unit_id ? pcle_get_unit_progress( $unit_id ) : pcle_progress_struct( 0, 0 );

	return rest_ensure_response(
		array(
			'module_id'     => $module_id,
			'completed'     => pcle_is_module_complete( $module_id ),
			'unit_progress' => pcle_rest_shape_progress( $progress ),
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
	$is_cle_view = is_singular( array( 'pcle_module', 'pcle_unit', 'pcle_program' ) );

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
	global $wpdb;

	$students = get_users( array( 'role' => 'pcle_student' ) );
	$modules  = pcle_get_program_module_ids( $program_id );
	$total    = count( $modules );

	/*
	 * One grouped count for the whole cohort, rather than recomputing each
	 * participant's progress from scratch — which meant re-walking the
	 * curriculum once per student on a screen that lists all of them.
	 */
	$counts = array();
	if ( $modules && $students ) {
		$table        = pcle_progress_table();
		$placeholders = implode( ',', array_fill( 0, count( $modules ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS done
				 FROM {$table}
				 WHERE module_id IN ({$placeholders})
				 GROUP BY user_id",
				$modules
			)
		);

		foreach ( $rows as $row ) {
			$counts[ (int) $row->user_id ] = (int) $row->done;
		}
	}

	$out = array();
	foreach ( $students as $student ) {
		$done = isset( $counts[ $student->ID ] ) ? $counts[ $student->ID ] : 0;

		$out[ $student->ID ] = array(
			'user'     => $student,
			'progress' => pcle_progress_struct( $done, $total ),
		);
	}

	return $out;
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
