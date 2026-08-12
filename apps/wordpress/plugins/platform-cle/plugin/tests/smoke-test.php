<?php
/**
 * Platform CLE — dependency-free smoke tests.
 *
 * Boots WordPress, creates isolated fixtures, asserts the critical paths
 * (access control, enrollment, progress, protected files, REST guard), then
 * cleans everything up. No PHPUnit / composer required.
 *
 * Exit code is non-zero on failure, so it can gate CI later.
 *
 * Usage (from the site root, with Local's socket):
 *   php -d mysqli.default_socket=<sock> wp-content/plugins/platform-cle/tests/smoke-test.php
 *
 * @package Platform_CLE
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Run from CLI only.' );
}

// Load WordPress.
$dir = __DIR__;
while ( '/' !== $dir && ! file_exists( $dir . '/wp-load.php' ) ) {
	$dir = dirname( $dir );
}
if ( ! file_exists( $dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "wp-load.php not found\n" );
	exit( 2 );
}
require $dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/* ------------------------------------------------------------------ */
/* Tiny assertion framework                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['pcle_t'] = array(
	'pass' => 0,
	'fail' => 0,
);

function pcle_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['pcle_t']['pass']++;
		return;
	}
	$GLOBALS['pcle_t']['fail']++;
	echo "  \033[31mFAIL\033[0m  {$msg}\n";
}

function pcle_eq( $actual, $expected, $msg ) {
	pcle_ok(
		$actual === $expected,
		$msg . ' (got ' . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . ')'
	);
}

function pcle_section( $name ) {
	echo "\n{$name}\n";
}

// Capture outgoing mail instead of sending it (no MTA on dev; keeps tests quiet).
$GLOBALS['pcle_mail'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['pcle_mail'][] = $atts;
		return true;
	},
	10,
	2
);

/* ------------------------------------------------------------------ */
/* Fixtures                                                            */
/* ------------------------------------------------------------------ */

function pcle_make_post( $type, $title, $meta = array(), $content = '' ) {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		)
	);
	update_post_meta( $id, '_pcle_test', 1 );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return (int) $id;
}

$created_posts   = array();
$created_users   = array();
$created_files   = array();

// Program A (student enrolled) → Week → Module; plus a Case Update.
$prog_a  = pcle_make_post( 'pcle_program', 'TEST Program A' );
$prog_b  = pcle_make_post( 'pcle_program', 'TEST Program B' );
$week    = pcle_make_post( 'pcle_week', 'TEST Week 1', array( '_pcle_program_id' => $prog_a ) );
$module  = pcle_make_post( 'pcle_module', 'TEST Module 1', array( '_pcle_week_id' => $week ) );
$module2 = pcle_make_post( 'pcle_module', 'TEST Module 2', array( '_pcle_week_id' => $week ) );
$case    = pcle_make_post( 'pcle_case_update', 'TEST Case Update' );

// A scenario carrying a model answer, so we can assert it never reaches a
// reader without access to the program it belongs to.
$scenario = pcle_make_post(
	'pcle_scenario',
	'TEST Scenario',
	array( '_pcle_module_id' => $module ),
	'The prompt. [pcle_model_answer]PCLE_SECRET_ANSWER[/pcle_model_answer]'
);
$created_posts = array( $prog_a, $prog_b, $week, $module, $module2, $case, $scenario );

/**
 * Creates a throwaway CLE student.
 *
 * @return int User ID.
 */
function pcle_make_student() {
	return wp_insert_user(
		array(
			'user_login' => 'pcle_test_student_' . wp_generate_password( 5, false ),
			'user_email' => 'test+' . wp_generate_password( 6, false ) . '@example.test',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'pcle_student',
		)
	);
}

// A student enrolled in Program A only, and an admin.
$student         = pcle_make_student();
$created_users[] = $student;
pcle_enroll_user( $prog_a, $student );

// A student enrolled in NOTHING — the "has an account but hasn't paid /
// isn't in this cohort" case. They hold view_cle_content and
// reveal_model_answers by virtue of the role, so they are the sharpest test
// of whether access is really decided by enrollment.
$outsider        = pcle_make_student();
$created_users[] = $outsider;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = $admins ? (int) $admins[0] : 0;

echo "Platform CLE smoke tests\n======================";

/* ------------------------------------------------------------------ */
/* 1) Access control (per program)                                    */
/* ------------------------------------------------------------------ */
pcle_section( '# Access control' );
pcle_eq( pcle_can_access_post( $prog_a, 0 ), false, 'anonymous cannot access a program' );
pcle_eq( pcle_can_access_post( $prog_a, $admin ), true, 'staff can access any program' );
pcle_eq( pcle_can_access_post( $prog_a, $student ), true, 'enrolled student can access their program' );
pcle_eq( pcle_can_access_post( $module, $student ), true, 'enrolled student can access a module in their program' );
pcle_eq( pcle_can_access_post( $prog_b, $student ), false, 'student cannot access a program they are NOT enrolled in' );
pcle_eq( pcle_can_access_post( $case, $student ), true, 'case updates are visible to any participant' );

/* ------------------------------------------------------------------ */
/* 2) Enrollment                                                      */
/* ------------------------------------------------------------------ */
pcle_section( '# Enrollment' );
pcle_eq( pcle_is_enrolled( $prog_a, $student ), true, 'is_enrolled true after enroll' );
pcle_eq( pcle_is_enrolled( $prog_b, $student ), false, 'is_enrolled false for other program' );
pcle_eq( in_array( $prog_a, pcle_get_enrolled_programs( $student ), true ), true, 'enrolled programs list contains program A' );
pcle_eq( pcle_enroll_user( $module, $student ), false, 'cannot enroll into a non-program id' );
pcle_unenroll_user( $prog_a, $student );
pcle_eq( pcle_is_enrolled( $prog_a, $student ), false, 'unenroll removes enrollment' );
pcle_enroll_user( $prog_a, $student ); // restore for later tests
pcle_eq( pcle_user_is_staff( $admin ), true, 'admin is staff' );
pcle_eq( pcle_user_is_staff( $student ), false, 'student is not staff' );

/* ------------------------------------------------------------------ */
/* 3) Progress                                                        */
/* ------------------------------------------------------------------ */
pcle_section( '# Progress' );
pcle_eq( pcle_mark_module_complete( $prog_a, $student ), false, 'cannot mark a non-module complete' );
pcle_mark_module_complete( $module, $student );
pcle_eq( pcle_is_module_complete( $module, $student ), true, 'module marked complete' );
$wp = pcle_get_week_progress( $week, $student );
pcle_eq( $wp['total'], 2, 'week has 2 modules' );
pcle_eq( $wp['completed'], 1, 'week shows 1 completed' );
pcle_eq( $wp['percent'], 50, 'week progress is 50%' );
$pp = pcle_get_program_progress( $prog_a, $student );
pcle_eq( $pp['completed'], 1, 'program shows 1 completed' );
pcle_eq( $pp['total'], 2, 'program total is 2' );
pcle_mark_module_complete( $module2, $student );
pcle_eq( pcle_get_week_progress( $week, $student )['percent'], 100, 'week is 100% after both modules' );
pcle_unmark_module_complete( $module, $student );
pcle_eq( pcle_is_module_complete( $module, $student ), false, 'unmark removes completion' );

/* ------------------------------------------------------------------ */
/* 4) Relationships                                                   */
/* ------------------------------------------------------------------ */
pcle_section( '# Relationships' );
pcle_eq( pcle_get_program_for_post( $module ), $prog_a, 'get_program_for_post walks module → program' );
pcle_eq( pcle_get_program_for_post( $week ), $prog_a, 'get_program_for_post walks week → program' );
pcle_eq( pcle_get_program_for_post( $case ), 0, 'case update has no program' );
pcle_eq( count( pcle_get_modules( $week ) ), 2, 'week has 2 child modules' );
pcle_eq( count( pcle_get_weeks( $prog_a ) ), 1, 'program A has 1 week' );

/* ------------------------------------------------------------------ */
/* 5) Protected files                                                 */
/* ------------------------------------------------------------------ */
pcle_section( '# Protected files' );
$up = wp_get_upload_dir();

// Protected attachment.
$pdir = $up['basedir'] . '/pcle-protected/testfix';
wp_mkdir_p( $pdir );
$pfile = $pdir . '/brief.txt';
file_put_contents( $pfile, 'secret' );
$created_files[] = $pfile;
$patt = wp_insert_attachment(
	array( 'post_mime_type' => 'text/plain', 'post_status' => 'inherit', 'post_parent' => $module ),
	$pfile,
	$module
);
update_post_meta( $patt, '_wp_attached_file', 'pcle-protected/testfix/brief.txt' );
$created_posts[] = $patt;

// Public attachment.
$ufile = $up['basedir'] . '/pcle-test-public.txt';
file_put_contents( $ufile, 'public' );
$created_files[] = $ufile;
$uatt = wp_insert_attachment(
	array( 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ),
	$ufile
);
update_post_meta( $uatt, '_wp_attached_file', 'pcle-test-public.txt' );
$created_posts[] = $uatt;

pcle_eq( pcle_is_protected_attachment( $patt ), true, 'file in pcle-protected/ is detected as protected' );
pcle_eq( pcle_is_protected_attachment( $uatt ), false, 'file in uploads root is NOT protected' );
pcle_ok( false !== strpos( pcle_protected_file_url( $patt ), 'pcle_download=' . $patt ), 'protected file URL uses the guarded endpoint' );
pcle_ok( false !== strpos( wp_get_attachment_url( $patt ), 'pcle_download=' ), 'wp_get_attachment_url is rewritten for protected files' );
pcle_eq( strpos( wp_get_attachment_url( $uatt ), 'pcle_download=' ), false, 'wp_get_attachment_url is untouched for public files' );

/* ------------------------------------------------------------------ */
/* 6) REST guard (per program)                                        */
/* ------------------------------------------------------------------ */
pcle_section( '# REST guard' );
function pcle_rest_status( $uid, $route ) {
	wp_set_current_user( $uid );
	return rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
}
pcle_eq( pcle_rest_status( 0, "/wp/v2/pcle_program/{$prog_a}" ), 401, 'anon REST read → 401' );
pcle_eq( pcle_rest_status( $student, "/wp/v2/pcle_program/{$prog_a}" ), 200, 'enrolled student REST read → 200' );
pcle_eq( pcle_rest_status( $student, "/wp/v2/pcle_program/{$prog_b}" ), 403, 'non-enrolled student REST read → 403' );
pcle_eq( pcle_rest_status( $admin, "/wp/v2/pcle_program/{$prog_b}" ), 200, 'staff REST read → 200' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 7) REST: my-training                                               */
/* ------------------------------------------------------------------ */
pcle_section( '# REST my-training' );
function pcle_rest_my_training( $uid ) {
	wp_set_current_user( $uid );
	return rest_do_request( new WP_REST_Request( 'GET', '/platform-cle/v1/my-training' ) );
}

$anon_res = pcle_rest_my_training( 0 );
pcle_eq( $anon_res->get_status(), 401, 'anonymous my-training request → 401' );

$student_res  = pcle_rest_my_training( $student );
$student_data = $student_res->get_data();
pcle_eq( $student_res->get_status(), 200, 'enrolled student my-training request → 200' );
pcle_eq( count( $student_data['programs'] ), 1, 'student sees only their enrolled program' );
pcle_eq( $student_data['programs'][0]['id'], $prog_a, 'student sees Program A' );
pcle_eq( $student_data['programs'][0]['title'], 'TEST Program A', 'program title is present' );
pcle_eq( $student_data['programs'][0]['progress']['total'], 2, 'program progress total is 2 modules' );
pcle_eq( $student_data['programs'][0]['progress']['completed'], 1, 'program progress completed matches earlier fixture state (module2 completed)' );
pcle_eq( $student_data['programs'][0]['progress']['percentage'], 50, 'program progress percentage matches completed/total' );
pcle_ok( ! isset( $student_data['programs'][0]['post_type'] ), 'response does not leak raw WP_Post fields' );

$admin_res  = pcle_rest_my_training( $admin );
$admin_data = $admin_res->get_data();
pcle_eq( $admin_res->get_status(), 200, 'staff my-training request → 200' );
pcle_ok( count( $admin_data['programs'] ) >= 2, 'staff sees all programs, not just enrolled ones' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 8) REST guard: collection listings                                 */
/* ------------------------------------------------------------------ */
/*
 * The per-item guard (section 6) is not enough on its own: /wp/v2/pcle_module
 * without an ID is a different route, and answering it with "is this a
 * participant?" hands the whole curriculum to anyone holding a student
 * account. These assert that a listing is narrowed to what the reader is
 * actually enrolled in.
 */
pcle_section( '# REST guard (collections)' );

/**
 * Requests a collection route and returns the post IDs it yielded, or the
 * HTTP status when the request was refused outright.
 *
 * @param int    $uid   User to run as (0 = anonymous).
 * @param string $route REST route.
 * @return int[]|int
 */
function pcle_rest_collection( $uid, $route ) {
	wp_set_current_user( $uid );
	$request = new WP_REST_Request( 'GET', $route );
	$request->set_param( 'per_page', 100 );
	$response = rest_do_request( $request );

	if ( 200 !== $response->get_status() ) {
		return $response->get_status();
	}

	return wp_list_pluck( $response->get_data(), 'id' );
}

/**
 * Number of items a listing returned, or the refusal status.
 *
 * @param int[]|int $result Result of pcle_rest_collection().
 * @return int
 */
function pcle_rest_count( $result ) {
	return is_array( $result ) ? count( $result ) : $result;
}

pcle_eq( pcle_rest_collection( 0, '/wp/v2/pcle_program' ), 401, 'anonymous program listing → 401' );

$student_programs = pcle_rest_collection( $student, '/wp/v2/pcle_program' );
pcle_ok( is_array( $student_programs ) && in_array( $prog_a, $student_programs, true ), 'student listing includes their enrolled program' );
pcle_ok( is_array( $student_programs ) && ! in_array( $prog_b, $student_programs, true ), 'student listing EXCLUDES a program they are not enrolled in' );

$student_modules = pcle_rest_collection( $student, '/wp/v2/pcle_module' );
pcle_ok( is_array( $student_modules ) && in_array( $module, $student_modules, true ), 'student listing includes modules of their own program' );

pcle_eq( pcle_rest_count( pcle_rest_collection( $outsider, '/wp/v2/pcle_module' ) ), 0, 'student enrolled in nothing gets an empty module listing' );
pcle_eq( pcle_rest_count( pcle_rest_collection( $outsider, '/wp/v2/pcle_scenario' ) ), 0, 'student enrolled in nothing gets an empty scenario listing' );
pcle_eq( pcle_rest_count( pcle_rest_collection( $outsider, '/wp/v2/pcle_program' ) ), 0, 'student enrolled in nothing gets an empty program listing' );

$admin_programs = pcle_rest_collection( $admin, '/wp/v2/pcle_program' );
pcle_ok( is_array( $admin_programs ) && in_array( $prog_b, $admin_programs, true ), 'staff listing still sees every program' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 9) Model answers                                                   */
/* ------------------------------------------------------------------ */
/*
 * reveal_model_answers comes with the student role, so the capability alone
 * says nothing about whether this reader belongs in this program.
 */
pcle_section( '# Model answers' );

/**
 * Renders a post's content as a given user, the way the_content would.
 *
 * @param int $uid     User to run as (0 = anonymous).
 * @param int $post_id Post to render.
 * @return string Rendered HTML.
 */
function pcle_render_as( $uid, $post_id ) {
	wp_set_current_user( $uid );

	$post            = get_post( $post_id );
	$GLOBALS['post'] = $post;
	setup_postdata( $post );

	$html = apply_filters( 'the_content', $post->post_content );

	$GLOBALS['post'] = null;
	return $html;
}

pcle_ok( false !== strpos( pcle_render_as( $student, $scenario ), 'PCLE_SECRET_ANSWER' ), 'enrolled student still sees the model answer' );
pcle_ok( false !== strpos( pcle_render_as( $admin, $scenario ), 'PCLE_SECRET_ANSWER' ), 'staff still sees the model answer' );
pcle_ok( false === strpos( pcle_render_as( $outsider, $scenario ), 'PCLE_SECRET_ANSWER' ), 'student without access to the program does NOT receive the model answer' );
pcle_ok( false === strpos( pcle_render_as( 0, $scenario ), 'PCLE_SECRET_ANSWER' ), 'anonymous does not receive the model answer' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 10) Curriculum endpoints                                           */
/* ------------------------------------------------------------------ */
pcle_section( '# Curriculum endpoints' );

/**
 * Runs a curriculum route as a user.
 *
 * @param int    $uid   User to run as (0 = anonymous).
 * @param string $route REST route.
 * @return WP_REST_Response
 */
function pcle_rest_get( $uid, $route ) {
	wp_set_current_user( $uid );
	return rest_do_request( new WP_REST_Request( 'GET', $route ) );
}

// Access: the whole point of these routes is that enrollment is checked once.
foreach ( array( "/platform-cle/v1/programs/{$prog_a}", "/platform-cle/v1/weeks/{$week}", "/platform-cle/v1/modules/{$module}" ) as $route ) {
	pcle_eq( pcle_rest_get( 0, $route )->get_status(), 401, "anonymous {$route} → 401" );
	pcle_eq( pcle_rest_get( $outsider, $route )->get_status(), 403, "non-enrolled {$route} → 403" );
	pcle_eq( pcle_rest_get( $student, $route )->get_status(), 200, "enrolled {$route} → 200" );
	pcle_eq( pcle_rest_get( $admin, $route )->get_status(), 200, "staff {$route} → 200" );
}

// A program the student is not enrolled in stays closed on the new routes too.
pcle_eq( pcle_rest_get( $student, "/platform-cle/v1/programs/{$prog_b}" )->get_status(), 403, 'enrolled student cannot open another program' );

// Wrong post type for the route, and a missing id, are both 404 — not a 200
// shaped from whatever post happened to carry that ID.
pcle_eq( pcle_rest_get( $student, "/platform-cle/v1/programs/{$module}" )->get_status(), 404, 'module id on the programs route → 404' );
pcle_eq( pcle_rest_get( $student, '/platform-cle/v1/modules/99999999' )->get_status(), 404, 'unknown id → 404' );

// Shape: a program carries its weeks, and each week its modules and events.
$program_data = pcle_rest_get( $student, "/platform-cle/v1/programs/{$prog_a}" )->get_data();
pcle_eq( $program_data['id'], $prog_a, 'program response carries the id' );
pcle_eq( count( $program_data['weeks'] ), 1, 'program response carries its weeks' );
pcle_eq( count( $program_data['weeks'][0]['modules'] ), 2, 'week carries its modules' );
pcle_ok( isset( $program_data['weeks'][0]['events'] ), 'week carries an events list' );

// Progress is spelled the same way on every route that reports it.
$expected_progress_keys = array( 'completed', 'total', 'percentage' );
pcle_eq( array_keys( $program_data['progress'] ), $expected_progress_keys, 'program progress keys' );
pcle_eq( array_keys( $program_data['weeks'][0]['progress'] ), $expected_progress_keys, 'week progress keys' );
$my_training_data = pcle_rest_my_training( $student );
pcle_eq( array_keys( $my_training_data->get_data()['programs'][0]['progress'] ), $expected_progress_keys, 'my-training progress keys match' );

// Module detail: breadcrumb refs, children, and the completion flag.
$module_data = pcle_rest_get( $student, "/platform-cle/v1/modules/{$module}" )->get_data();
pcle_eq( $module_data['week']['id'], $week, 'module response points at its week' );
pcle_eq( $module_data['program']['id'], $prog_a, 'module response points at its program' );
pcle_eq( count( $module_data['scenarios'] ), 1, 'module carries its scenarios' );
pcle_eq( $module_data['completed'], pcle_is_module_complete( $module, $student ), 'module completion flag matches stored progress' );

/*
 * The model answer has to survive the trip through REST. The shortcode fails
 * closed when it cannot tell which post it is inside, so rendering without
 * the global post set up would strip answers from everyone, silently.
 */
pcle_ok( false !== strpos( $module_data['scenarios'][0]['content'], 'PCLE_SECRET_ANSWER' ), 'enrolled reader receives the model answer through the API' );

// Excerpts are built from stripped shortcodes, so a listing never carries an
// answer in plain text even where the reader is allowed to see the scenario.
$scenario_post = get_post( $scenario );
pcle_ok( false === strpos( pcle_rest_excerpt( $scenario_post ), 'PCLE_SECRET_ANSWER' ), 'excerpts do not carry model answers' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 11) Progress endpoint                                              */
/* ------------------------------------------------------------------ */
pcle_section( '# Progress endpoint' );

/**
 * Posts a progress toggle as a user.
 *
 * @param int  $uid       User to run as.
 * @param int  $module_id Module.
 * @param bool $completed Desired state.
 * @return WP_REST_Response
 */
function pcle_rest_post_progress( $uid, $module_id, $completed ) {
	wp_set_current_user( $uid );
	$request = new WP_REST_Request( 'POST', '/platform-cle/v1/progress' );
	$request->set_body_params(
		array(
			'module_id' => $module_id,
			'completed' => $completed,
		)
	);
	return rest_do_request( $request );
}

pcle_eq( pcle_rest_post_progress( 0, $module, true )->get_status(), 401, 'anonymous cannot record progress' );
pcle_eq( pcle_rest_post_progress( $outsider, $module, true )->get_status(), 403, 'a reader with no access cannot record progress against the module' );
pcle_eq( pcle_is_module_complete( $module, $outsider ), false, 'the refused write left no progress behind' );

$toggle = pcle_rest_post_progress( $student, $module, true );
pcle_eq( $toggle->get_status(), 200, 'enrolled student can record progress' );
pcle_eq( $toggle->get_data()['completed'], true, 'response reports the new state' );
pcle_eq( array_keys( $toggle->get_data()['week_progress'] ), $expected_progress_keys, 'progress endpoint uses the same progress keys' );
pcle_eq( pcle_is_module_complete( $module, $student ), true, 'progress was actually stored' );

pcle_rest_post_progress( $student, $module, false ); // restore fixture state
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 12) Storage: tables, timestamps and migration                      */
/* ------------------------------------------------------------------ */
pcle_section( '# Storage' );

global $wpdb;
$enrollments_table = pcle_enrollments_table();
$progress_table    = pcle_progress_table();

// Writes through the public helpers land in the tables, not in user meta.
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments_table} WHERE user_id = %d AND program_id = %d", $student, $prog_a ) ),
	1,
	'enrollment is stored as a row'
);
pcle_eq( get_user_meta( $student, PCLE_ENROLLMENT_META, true ), '', 'enrolling no longer writes the legacy user meta' );

// A new completion is timestamped; that is the whole point of the move.
pcle_mark_module_complete( $module, $student );
$completed_at = pcle_get_module_completed_at( $module, $student );
pcle_ok( is_string( $completed_at ) && '' !== $completed_at, 'a new completion records when it happened' );

/*
 * Re-marking must not move the date. A completion record that silently
 * advances every time a student revisits the page is worthless as evidence
 * of when they did the work.
 */
pcle_mark_module_complete( $module, $student );
pcle_eq( pcle_get_module_completed_at( $module, $student ), $completed_at, 're-marking preserves the original completion date' );

// And unmark/remark is a genuinely new record, not a resurrected one.
pcle_unmark_module_complete( $module, $student );
pcle_eq( pcle_get_module_completed_at( $module, $student ), null, 'unmarking removes the row' );
pcle_mark_module_complete( $module, $student );

// The unique keys, not the callers, are what make double writes harmless.
pcle_enroll_user( $prog_a, $student );
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments_table} WHERE user_id = %d AND program_id = %d", $student, $prog_a ) ),
	1,
	'enrolling twice cannot create a second row'
);

// Migration: a user carrying only legacy meta is brought across, with the
// timestamps left NULL rather than invented.
$legacy_user     = pcle_make_student();
$created_users[] = $legacy_user;
update_user_meta( $legacy_user, PCLE_ENROLLMENT_META, array( $prog_b ) );
update_user_meta( $legacy_user, PCLE_PROGRESS_META, array( $module, $module2 ) );

$migrated = pcle_migrate_legacy_meta();
pcle_ok( $migrated['enrollments'] >= 1, 'migration reports the enrollment it moved' );
pcle_ok( $migrated['progress'] >= 2, 'migration reports the completions it moved' );
pcle_eq( pcle_is_enrolled( $prog_b, $legacy_user ), true, 'legacy enrollment is readable after migration' );
pcle_eq( pcle_is_module_complete( $module, $legacy_user ), true, 'legacy completion is readable after migration' );
pcle_eq( pcle_get_module_completed_at( $module, $legacy_user ), null, 'a migrated completion has no invented date' );
pcle_eq( pcle_get_enrolled_at( $prog_b, $legacy_user ), null, 'a migrated enrollment has no invented date' );

// Running it again must change nothing: it runs on every version bump.
$again = pcle_migrate_legacy_meta();
pcle_eq( $again['enrollments'], 0, 'a second migration pass inserts no enrollments' );
pcle_eq( $again['progress'], 0, 'a second migration pass inserts no completions' );
pcle_eq( count( pcle_get_enrolled_programs( $legacy_user ) ), 1, 'the migrated user is not enrolled twice' );

// The query the old model could not answer at all.
$enrollees = pcle_get_program_enrollee_ids( $prog_b );
pcle_ok( in_array( $legacy_user, $enrollees, true ), 'program roster includes the enrolled user' );
pcle_ok( ! in_array( $outsider, $enrollees, true ), 'program roster excludes a user enrolled in nothing' );

// The cohort view must agree with the per-user computation it replaced.
$participants = pcle_get_participant_progress( $prog_a );
pcle_ok( isset( $participants[ $student ] ), 'participant progress includes the student' );
pcle_eq(
	$participants[ $student ]['progress'],
	pcle_get_program_progress( $prog_a, $student ),
	'cohort progress agrees with the single-user computation'
);
pcle_eq(
	$participants[ $outsider ]['progress']['completed'],
	0,
	'a participant with no completions reports zero rather than being absent'
);

/* ------------------------------------------------------------------ */
/* 13) Credit hours                                                   */
/* ------------------------------------------------------------------ */
pcle_section( '# Credit hours' );

pcle_eq( array_keys( pcle_jurisdictions() ), array( 'ks', 'mo' ), 'jurisdictions are Kansas and Missouri' );
pcle_eq( pcle_has_credit_hours( $prog_a ), false, 'a programme starts with no hours entered' );

update_post_meta( $prog_a, pcle_credit_hours_meta_key( 'ks' ), 3.0 );
update_post_meta( $prog_a, pcle_credit_hours_meta_key( 'mo' ), 2.5 );

pcle_eq( pcle_get_credit_hours( $prog_a ), array( 'ks' => 3.0, 'mo' => 2.5 ), 'hours are stored per jurisdiction' );
pcle_eq( pcle_has_credit_hours( $prog_a ), true, 'a programme with hours reports having them' );
pcle_eq( pcle_get_credit_hours( $prog_b )['ks'], 0.0, 'an unaccredited programme reports zero, not null' );

// Bars award in quarter hours; anything finer is a typo.
pcle_eq( pcle_sanitize_credit_hours( 1.30 ), 1.25, 'hours round to the quarter' );
pcle_eq( pcle_sanitize_credit_hours( 1.9 ), 2.0, 'hours round up to the quarter' );
pcle_eq( pcle_sanitize_credit_hours( -4 ), 0.0, 'negative hours become zero' );
pcle_eq( pcle_sanitize_credit_hours( 'abc' ), 0.0, 'non-numeric hours become zero' );

/*
 * The two figures describe the same seat time approved by two bars. There is
 * deliberately no helper that adds them, because a total would be hours
 * nobody sat.
 */
pcle_eq( function_exists( 'pcle_get_total_credit_hours' ), false, 'no helper invites summing hours across jurisdictions' );

$program_payload = pcle_rest_get( $student, "/platform-cle/v1/programs/{$prog_a}" )->get_data();
pcle_eq( count( $program_payload['credits'] ), 2, 'the programme endpoint reports both jurisdictions' );
pcle_eq( $program_payload['credits'][0]['hours'], 3.0, 'the endpoint reports the Kansas hours' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 14) Attendance                                                     */
/* ------------------------------------------------------------------ */
pcle_section( '# Attendance' );

$session         = pcle_make_post( 'pcle_event', 'TEST Session A', array( '_pcle_week_id' => $week ) );
$created_posts[] = $session;

pcle_eq( pcle_has_attended( $session, $student ), false, 'nobody is present until marked' );
pcle_eq( pcle_mark_attendance( $module, $student, $admin ), false, 'cannot mark attendance against a non-event' );

pcle_mark_attendance( $session, $student, $admin );
pcle_eq( pcle_has_attended( $session, $student ), true, 'marking records attendance' );
pcle_eq( pcle_get_event_attendee_ids( $session ), array( $student ), 'the session roster lists who was there' );

/*
 * Attendance is one person vouching for another, so the record keeps who
 * asserted it — and re-marking must not silently reattribute it to whoever
 * saved the screen last.
 */
global $wpdb;
$attendance_table = pcle_attendance_table();
$marked_by        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT marked_by FROM {$attendance_table} WHERE user_id = %d AND event_id = %d", $student, $session ) );
pcle_eq( $marked_by, $admin, 'the record keeps which instructor marked it' );

pcle_mark_attendance( $session, $student, $outsider );
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT marked_by FROM {$attendance_table} WHERE user_id = %d AND event_id = %d", $student, $session ) ),
	$admin,
	're-marking does not reattribute the original assertion'
);

$program_attendance = pcle_get_program_attendance( $prog_a, $student );
pcle_eq( $program_attendance['attended'], 1, 'programme attendance counts the sessions attended' );
pcle_eq( $program_attendance['total'], count( pcle_get_program_event_ids( $prog_a ) ), 'programme attendance counts every session' );

/*
 * The two must stay unlinked: attendance is a record of presence, credit
 * hours are what a bar approved. Wiring one to the other would invent a
 * credit rule nobody approved.
 */
$hours_before = pcle_get_credit_hours( $prog_a );
pcle_unmark_attendance( $session, $student );
pcle_eq( pcle_has_attended( $session, $student ), false, 'unmarking removes attendance' );
pcle_eq( pcle_get_credit_hours( $prog_a ), $hours_before, 'changing attendance does not change credit hours' );
pcle_mark_attendance( $session, $student, $admin );

/* ------------------------------------------------------------------ */
/* 15) Certificates                                                   */
/* ------------------------------------------------------------------ */
/*
 * The safety property of the scaffold: while the accreditation identity is
 * missing, NOTHING may render as a valid certificate. An attorney can rely on
 * one of these to show a bar they met an obligation.
 */
pcle_section( '# Certificates' );

pcle_ok( count( pcle_certificate_blockers( $prog_a ) ) > 0, 'certificates are blocked while accreditation is unset' );
pcle_eq( pcle_certificate_is_issuable( $prog_a ), false, 'and are therefore not issuable' );

$draft = pcle_render_certificate( $prog_a, $student );
pcle_ok( false !== strpos( $draft, 'DRAFT' ), 'the draft says so in the document title' );
pcle_ok( false !== strpos( $draft, 'this document is not valid' ), 'the draft carries the warning banner' );
pcle_ok( false !== strpos( $draft, 'NOT VALID FOR CREDIT' ), 'the draft carries the watermark' );

// It still states the truth about the participant.
$cert = pcle_get_certificate_data( $prog_a, $student );
pcle_eq( $cert['program_id'], $prog_a, 'certificate data names the programme' );
pcle_eq( $cert['credit_hours']['ks'], 3.0, 'certificate data carries the approved hours' );
pcle_eq( $cert['attendance']['attended'], 1, 'certificate data carries attendance' );
pcle_eq( $cert['finished'], true, 'a participant who completed every module is reported finished' );
pcle_eq( pcle_get_certificate_data( $prog_a, $outsider )['finished'], false, 'a participant who completed nothing is not' );

/*
 * A completion carried over from the old storage has no date, and the
 * certificate has to say so rather than substitute a plausible one.
 */
pcle_eq( $cert['dates_complete'], true, 'all completions in this fixture have dates' );
$wpdb->query( $wpdb->prepare( "UPDATE {$progress_table} SET completed_at = NULL WHERE user_id = %d AND module_id = %d", $student, $module ) );
pcle_eq( pcle_get_certificate_data( $prog_a, $student )['dates_complete'], false, 'a completion with no date is flagged, not invented' );
pcle_mark_module_complete( $module, $student );

// And the gate must open once the details exist, or it is not a gate.
$fake_accreditation = function () {
	return array(
		'provider_name'    => 'TEST Provider',
		'provider_numbers' => array( 'ks' => 'KS-TEST' ),
		'signatory_name'   => 'TEST Signatory',
		'signatory_title'  => 'Director',
	);
};
add_filter( 'pcle_certificate_accreditation', $fake_accreditation );

pcle_eq( pcle_certificate_blockers( $prog_a ), array(), 'complete accreditation clears every blocker' );
pcle_eq( pcle_certificate_is_issuable( $prog_a ), true, 'and the certificate becomes issuable' );

$issued = pcle_render_certificate( $prog_a, $student );
pcle_ok( false === strpos( $issued, 'this document is not valid' ), 'an issuable certificate drops the warning banner' );
pcle_ok( false === strpos( $issued, 'NOT VALID FOR CREDIT' ), 'an issuable certificate drops the watermark' );
pcle_ok( false !== strpos( $issued, 'KS-TEST' ), 'an issuable certificate prints the provider number' );

// A programme with no hours stays blocked even with full accreditation:
// hours are what the credit claim rests on.
pcle_ok( count( pcle_certificate_blockers( $prog_b ) ) > 0, 'a programme with no approved hours stays blocked' );

remove_filter( 'pcle_certificate_accreditation', $fake_accreditation );
pcle_eq( pcle_certificate_is_issuable( $prog_a ), false, 'removing the accreditation blocks issuing again' );

/* ------------------------------------------------------------------ */
/* 16) Reports                                                        */
/* ------------------------------------------------------------------ */
pcle_section( '# Reports' );

$report = pcle_get_program_report( $prog_a );

pcle_ok( isset( $report[ $student ] ), 'the report includes an enrolled participant' );
pcle_ok( ! isset( $report[ $outsider ] ), 'the report excludes someone enrolled in nothing' );

$student_row = $report[ $student ];

// The report is built from grouped queries rather than the per-user helpers,
// so it has to be checked against them or the two can drift apart silently.
$student_progress = pcle_get_program_progress( $prog_a, $student );
pcle_eq( $student_row['completed'], $student_progress['completed'], 'report progress agrees with the per-user helper' );
pcle_eq( $student_row['total'], $student_progress['total'], 'report total agrees with the per-user helper' );
pcle_eq( $student_row['percent'], $student_progress['percent'], 'report percentage agrees with the per-user helper' );
pcle_eq( $student_row['attended'], pcle_get_program_attendance( $prog_a, $student )['attended'], 'report attendance agrees with the per-user helper' );
pcle_ok( is_string( $student_row['enrolled_at'] ) && '' !== $student_row['enrolled_at'], 'the report carries when the participant enrolled' );

/*
 * A completion with no date must appear as such. A report that quietly
 * counted it as dated would overstate what is actually known about when the
 * work was done.
 */
$wpdb->query( $wpdb->prepare( "UPDATE {$progress_table} SET completed_at = NULL WHERE user_id = %d AND module_id = %d", $student, $module ) );
$undated_row = pcle_get_program_report( $prog_a )[ $student ];
pcle_eq( $undated_row['undated'], 1, 'a completion without a date is counted and surfaced' );
pcle_eq( $undated_row['completed'], $student_row['completed'], 'and still counts as completed' );
pcle_mark_module_complete( $module, $student );

// CSV: a header plus one line per participant.
$csv = pcle_get_program_report_csv( $prog_a );
pcle_ok( count( $csv ) === count( $report ) + 1, 'the CSV has a header row plus one row per participant' );
pcle_ok( in_array( 'Kansas credit hours', $csv[0], true ), 'the CSV names a credit-hours column per jurisdiction' );

/*
 * Display names come from people, and a spreadsheet executes any cell
 * starting `=`, `+`, `-` or `@`. Someone named `=HYPERLINK(...)` must not run
 * on the machine of whoever opens the export.
 */
pcle_eq( pcle_csv_safe( '=HYPERLINK("http://evil","x")' ), '\'=HYPERLINK("http://evil","x")', 'a formula-triggering value is neutralised' );
pcle_eq( pcle_csv_safe( '+1' ), '\'+1', 'a leading plus is neutralised' );
pcle_eq( pcle_csv_safe( '@sum' ), '\'@sum', 'a leading at sign is neutralised' );
pcle_eq( pcle_csv_safe( 'Ana Ruiz' ), 'Ana Ruiz', 'an ordinary name is left alone' );
pcle_eq( pcle_csv_safe( '' ), '', 'an empty value is left alone' );

/*
 * The point of moving to tables: a cohort costs the same as one person.
 * Measured warm, so the curriculum walk is not counted twice.
 */
$cohort = array();
for ( $i = 0; $i < 8; $i++ ) {
	$extra    = pcle_make_student();
	$cohort[] = $extra;
	pcle_enroll_user( $prog_a, $extra );
	pcle_mark_module_complete( $module, $extra );
}
$created_users = array_merge( $created_users, $cohort );

pcle_get_program_report( $prog_a ); // warm the hierarchy cache
$before_queries = $wpdb->num_queries;
pcle_get_program_report( $prog_a );
$cohort_queries = $wpdb->num_queries - $before_queries;

pcle_ok( $cohort_queries <= 6, "a cohort report stays within a fixed query budget (used {$cohort_queries})" );
pcle_eq( count( pcle_get_program_report( $prog_a ) ), count( $cohort ) + 1, 'every enrolled participant appears exactly once' );

/*
 * WordPress cleans up its own usermeta on user deletion; custom tables are
 * not part of that. Without the deleted_user hook these rows would keep
 * showing up as participants who no longer exist.
 */
$doomed = array_pop( $cohort );
$created_users = array_values( array_diff( $created_users, array( $doomed ) ) );
wp_delete_user( $doomed );

pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments_table} WHERE user_id = %d", $doomed ) ),
	0,
	'deleting a user removes their enrollment rows'
);
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$progress_table} WHERE user_id = %d", $doomed ) ),
	0,
	'deleting a user removes their progress rows'
);
pcle_ok( ! isset( pcle_get_program_report( $prog_a )[ $doomed ] ), 'and they stop appearing in the report' );

/*
 * The same hole on the other side. Deleting a module used to leave its
 * completion rows pointing at a dead ID — invisible, but WordPress reuses
 * auto-increment IDs, so a future post could inherit somebody else's
 * completion history.
 */
$throwaway = pcle_make_post( 'pcle_module', 'TEST Disposable Module', array( '_pcle_week_id' => $week ) );
pcle_mark_module_complete( $throwaway, $student );
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$progress_table} WHERE module_id = %d", $throwaway ) ),
	1,
	'a completion against a module is stored'
);

wp_delete_post( $throwaway, true );
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$progress_table} WHERE module_id = %d", $throwaway ) ),
	0,
	'deleting a module removes the completions that referenced it'
);

$throwaway_event = pcle_make_post( 'pcle_event', 'TEST Disposable Session', array( '_pcle_week_id' => $week ) );
pcle_mark_attendance( $throwaway_event, $student, $admin );
wp_delete_post( $throwaway_event, true );
pcle_eq(
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attendance_table} WHERE event_id = %d", $throwaway_event ) ),
	0,
	'deleting a session removes the attendance that referenced it'
);

/*
 * Tear the cohort down here rather than at the end. It is enrolled in
 * Program A, and anything later that counts enrolled participants — the
 * session reminder, for one — would otherwise be measuring this fixture.
 */
foreach ( $cohort as $extra ) {
	wp_delete_user( $extra );
}
$created_users = array_values( array_diff( $created_users, $cohort ) );
pcle_eq( count( pcle_get_program_report( $prog_a ) ), 1, 'the cohort fixture is cleaned up after itself' );

/* ------------------------------------------------------------------ */
/* 17) Emails                                                         */
/* ------------------------------------------------------------------ */
pcle_section( '# Emails' );
$before = count( $GLOBALS['pcle_mail'] );
pcle_enroll_user( $prog_b, $student ); // student not yet in program B
pcle_eq( count( $GLOBALS['pcle_mail'] ) - $before, 1, 'new enrollment fires one confirmation email' );
$last_mail = end( $GLOBALS['pcle_mail'] );
pcle_ok( false !== strpos( $last_mail['subject'], 'TEST Program B' ), 'enrollment email subject names the program' );

$before = count( $GLOBALS['pcle_mail'] );
pcle_enroll_user( $prog_b, $student ); // already enrolled
pcle_eq( count( $GLOBALS['pcle_mail'] ) - $before, 0, 're-enroll does not resend' );

$rem_event       = pcle_make_post( 'pcle_event', 'TEST Session', array( '_pcle_week_id' => $week ) );
$created_posts[] = $rem_event;
$rdt = new DateTime( 'now', wp_timezone() );
$rdt->modify( '+2 hours' );
update_post_meta( $rem_event, '_pcle_event_datetime', $rdt->format( 'Y-m-d H:i:s' ) );

/*
 * The reminder de-duplicates through post meta, which is global state another
 * process can consume: any HTTP request to the site can spawn WP-Cron, and if
 * that runs the reminder job first it marks this event as sent and the
 * assertion below sees nothing. Clearing the marker here makes the test own
 * its own state instead of racing whatever else is talking to the site.
 */
delete_post_meta( $rem_event, '_pcle_reminder_sent' );

/**
 * Counts captured mail addressed to one recipient.
 *
 * The reminder job sweeps every session on the site, so on a seeded install
 * it legitimately mails other cohorts too. Counting the global total would
 * make this assertion depend on whatever demo content happens to have a
 * session due — which is exactly how it broke.
 *
 * @param string $email    Recipient to count.
 * @param int    $from     Index to start counting from.
 * @return int
 */
function pcle_mail_count_for( $email, $from ) {
	$found = 0;

	foreach ( array_slice( $GLOBALS['pcle_mail'], $from ) as $mail ) {
		$to = is_array( $mail['to'] ) ? $mail['to'] : array( $mail['to'] );

		if ( in_array( $email, $to, true ) ) {
			$found++;
		}
	}

	return $found;
}

$student_email = get_userdata( $student )->user_email;

$before = count( $GLOBALS['pcle_mail'] );
pcle_send_session_reminders();
pcle_eq( pcle_mail_count_for( $student_email, $before ), 1, 'session reminder emails the enrolled student' );

$before = count( $GLOBALS['pcle_mail'] );
pcle_send_session_reminders();
pcle_eq( pcle_mail_count_for( $student_email, $before ), 0, 'reminder is de-duplicated on re-run' );

/* ------------------------------------------------------------------ */
/* Teardown                                                           */
/* ------------------------------------------------------------------ */
foreach ( $created_posts as $pid ) {
	wp_delete_post( $pid, true );
}
foreach ( $created_users as $uid ) {
	wp_delete_user( $uid );
}
foreach ( $created_files as $f ) {
	if ( file_exists( $f ) ) {
		unlink( $f );
	}
}
@rmdir( $pdir ); // phpcs:ignore

/* ------------------------------------------------------------------ */
/* Summary                                                            */
/* ------------------------------------------------------------------ */
$pass = $GLOBALS['pcle_t']['pass'];
$fail = $GLOBALS['pcle_t']['fail'];
echo "\n======================\n";
echo "Result: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
