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

// Program A (student enrolled) → Unit → Module; plus a Case Update.
$prog_a  = pcle_make_post( 'pcle_program', 'TEST Program A' );
$prog_b  = pcle_make_post( 'pcle_program', 'TEST Program B' );
$unit    = pcle_make_post( 'pcle_unit', 'TEST Unit 1', array( '_pcle_program_id' => $prog_a ) );
$module  = pcle_make_post( 'pcle_module', 'TEST Module 1', array( '_pcle_unit_id' => $unit ) );
$module2 = pcle_make_post( 'pcle_module', 'TEST Module 2', array( '_pcle_unit_id' => $unit ) );
$case    = pcle_make_post( 'pcle_case_update', 'TEST Case Update' );

// A scenario carrying a model answer, so we can assert it never reaches a
// reader without access to the program it belongs to.
$scenario = pcle_make_post(
	'pcle_scenario',
	'TEST Scenario',
	array( '_pcle_module_id' => $module ),
	'The prompt. [pcle_model_answer]PCLE_SECRET_ANSWER[/pcle_model_answer]'
);
$created_posts = array( $prog_a, $prog_b, $unit, $module, $module2, $case, $scenario );

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
$wp = pcle_get_unit_progress( $unit, $student );
pcle_eq( $wp['total'], 2, 'unit has 2 modules' );
pcle_eq( $wp['completed'], 1, 'unit shows 1 completed' );
pcle_eq( $wp['percent'], 50, 'unit progress is 50%' );
$pp = pcle_get_program_progress( $prog_a, $student );
pcle_eq( $pp['completed'], 1, 'program shows 1 completed' );
pcle_eq( $pp['total'], 2, 'program total is 2' );
pcle_mark_module_complete( $module2, $student );
pcle_eq( pcle_get_unit_progress( $unit, $student )['percent'], 100, 'unit is 100% after both modules' );
pcle_unmark_module_complete( $module, $student );
pcle_eq( pcle_is_module_complete( $module, $student ), false, 'unmark removes completion' );

/* ------------------------------------------------------------------ */
/* 4) Relationships                                                   */
/* ------------------------------------------------------------------ */
pcle_section( '# Relationships' );
pcle_eq( pcle_get_program_for_post( $module ), $prog_a, 'get_program_for_post walks module → program' );
pcle_eq( pcle_get_program_for_post( $unit ), $prog_a, 'get_program_for_post walks unit → program' );
pcle_eq( pcle_get_program_for_post( $case ), 0, 'case update has no program' );
pcle_eq( count( pcle_get_modules( $unit ) ), 2, 'unit has 2 child modules' );
pcle_eq( count( pcle_get_units( $prog_a ) ), 1, 'program A has 1 unit' );

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
foreach ( array( "/platform-cle/v1/programs/{$prog_a}", "/platform-cle/v1/units/{$unit}", "/platform-cle/v1/modules/{$module}" ) as $route ) {
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

// Shape: a program carries its units, and each unit its modules and events.
$program_data = pcle_rest_get( $student, "/platform-cle/v1/programs/{$prog_a}" )->get_data();
pcle_eq( $program_data['id'], $prog_a, 'program response carries the id' );
pcle_eq( count( $program_data['units'] ), 1, 'program response carries its units' );
pcle_eq( count( $program_data['units'][0]['modules'] ), 2, 'unit carries its modules' );
pcle_ok( isset( $program_data['units'][0]['events'] ), 'unit carries an events list' );

// Progress is spelled the same way on every route that reports it.
$expected_progress_keys = array( 'completed', 'total', 'percentage' );
pcle_eq( array_keys( $program_data['progress'] ), $expected_progress_keys, 'program progress keys' );
pcle_eq( array_keys( $program_data['units'][0]['progress'] ), $expected_progress_keys, 'unit progress keys' );
$my_training_data = pcle_rest_my_training( $student );
pcle_eq( array_keys( $my_training_data->get_data()['programs'][0]['progress'] ), $expected_progress_keys, 'my-training progress keys match' );

// Module detail: breadcrumb refs, children, and the completion flag.
$module_data = pcle_rest_get( $student, "/platform-cle/v1/modules/{$module}" )->get_data();
pcle_eq( $module_data['unit']['id'], $unit, 'module response points at its unit' );
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
pcle_eq( array_keys( $toggle->get_data()['unit_progress'] ), $expected_progress_keys, 'progress endpoint uses the same progress keys' );
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

$session         = pcle_make_post( 'pcle_event', 'TEST Session A', array( '_pcle_unit_id' => $unit ) );
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
$throwaway = pcle_make_post( 'pcle_module', 'TEST Disposable Module', array( '_pcle_unit_id' => $unit ) );
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

$throwaway_event = pcle_make_post( 'pcle_event', 'TEST Disposable Session', array( '_pcle_unit_id' => $unit ) );
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
/* 17) Relationship integrity                                         */
/* ------------------------------------------------------------------ */
/*
 * The hierarchy is meta, and until now the only thing validating it was
 * pcle_save_relationship() — which returns early when its nonce is absent,
 * i.e. on every REST and WP-CLI write. So the parent of a module could be a
 * programme, a page, a deleted post, or the module itself, and only absint
 * ever ran.
 *
 * Validating on the metadata write itself covers every path at once.
 */
pcle_section( '# Relationship integrity' );

$orphan_page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'TEST Unrelated Page',
		'post_status' => 'publish',
	)
);
$created_posts[] = $orphan_page;

$relationship_victim = pcle_make_post( 'pcle_module', 'TEST Relationship Victim', array( '_pcle_unit_id' => $unit ) );
$created_posts[]     = $relationship_victim;

/**
 * Attempts to repoint a child at a parent and reports what stuck.
 *
 * @param int    $child_id Child post.
 * @param string $meta_key Relationship meta key.
 * @param mixed  $parent   Proposed parent.
 * @return int The parent actually stored afterwards.
 */
function pcle_try_reparent( $child_id, $meta_key, $parent ) {
	update_post_meta( $child_id, $meta_key, $parent );
	return (int) get_post_meta( $child_id, $meta_key, true );
}

pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', $prog_a ), $unit, 'a module cannot be reparented onto a programme' );
pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', $orphan_page ), $unit, 'a module cannot be reparented onto a page' );
pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', 99999999 ), $unit, 'a module cannot be reparented onto a post that does not exist' );
pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', $relationship_victim ), $unit, 'a module cannot be its own parent' );
pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', $module ), $unit, 'a module cannot hang off another module' );

// The legitimate paths must keep working, or this is a regression not a fix.
$second_unit     = pcle_make_post( 'pcle_unit', 'TEST Unit 2', array( '_pcle_program_id' => $prog_a ) );
$created_posts[] = $second_unit;
pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', $second_unit ), $second_unit, 'a module CAN be moved to another unit' );
pcle_eq( pcle_try_reparent( $relationship_victim, '_pcle_unit_id', 0 ), 0, 'a parent can still be cleared' );
pcle_try_reparent( $relationship_victim, '_pcle_unit_id', $unit ); // restore

// And the same rule has to hold through REST, which is the path that had none.
wp_set_current_user( $admin );
$reparent_request = new WP_REST_Request( 'POST', "/wp/v2/pcle_module/{$relationship_victim}" );
$reparent_request->set_body_params( array( 'meta' => array( '_pcle_unit_id' => $prog_a ) ) );
rest_do_request( $reparent_request );
pcle_eq( (int) get_post_meta( $relationship_victim, '_pcle_unit_id', true ), $unit, 'a REST write cannot reparent onto a programme either' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 18) Credit hours over REST                                         */
/* ------------------------------------------------------------------ */
/*
 * The hours were metabox-only: no register_post_meta, so a headless builder
 * could read them but never set them. They gate certificate issuance, so an
 * authoring UI that cannot touch them cannot finish a programme.
 */
pcle_section( '# Credit hours over REST' );

wp_set_current_user( $admin );
$credits_request = new WP_REST_Request( 'POST', "/wp/v2/pcle_program/{$prog_b}" );
$credits_request->set_body_params( array( 'meta' => array( pcle_credit_hours_meta_key( 'ks' ) => 3.3 ) ) );
$credits_response = rest_do_request( $credits_request );

pcle_eq( $credits_response->get_status(), 200, 'staff can write credit hours over REST' );
pcle_eq( pcle_get_credit_hours( $prog_b )['ks'], 3.25, 'hours written over REST are still rounded to the quarter' );

$credits_read = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/pcle_program/{$prog_b}" ) )->get_data();
pcle_ok( isset( $credits_read['meta'][ pcle_credit_hours_meta_key( 'ks' ) ] ), 'credit hours are exposed in REST' );

wp_set_current_user( $student );
$student_credits = new WP_REST_Request( 'POST', "/wp/v2/pcle_program/{$prog_b}" );
$student_credits->set_body_params( array( 'meta' => array( pcle_credit_hours_meta_key( 'ks' ) => 99 ) ) );
rest_do_request( $student_credits );
pcle_eq( pcle_get_credit_hours( $prog_b )['ks'], 3.25, 'a student cannot write credit hours' );
wp_set_current_user( 0 );

// Leave the fixture as the later sections expect it.
delete_post_meta( $prog_b, pcle_credit_hours_meta_key( 'ks' ) );

/* ------------------------------------------------------------------ */
/* 19) Ordering for templates and events                              */
/* ------------------------------------------------------------------ */
/*
 * Both lacked page-attributes, so menu_order could never be set from any UI
 * and their children always sorted alphabetically. A reorder endpoint needs
 * somewhere to put the order.
 */
pcle_section( '# Ordering' );

foreach ( array( 'pcle_template', 'pcle_event', 'pcle_module', 'pcle_unit' ) as $ordered_type ) {
	pcle_ok( post_type_supports( $ordered_type, 'page-attributes' ), "{$ordered_type} can carry an order" );
}

$order_b         = pcle_make_post( 'pcle_template', 'TEST Zebra Template', array( '_pcle_module_id' => $module ) );
$order_a         = pcle_make_post( 'pcle_template', 'TEST Alpha Template', array( '_pcle_module_id' => $module ) );
$created_posts[] = $order_b;
$created_posts[] = $order_a;

wp_update_post( array( 'ID' => $order_b, 'menu_order' => 1 ) );
wp_update_post( array( 'ID' => $order_a, 'menu_order' => 2 ) );

$ordered_templates = wp_list_pluck( pcle_get_templates( $module ), 'ID' );
pcle_eq( array_slice( $ordered_templates, 0, 2 ), array( $order_b, $order_a ), 'templates order by menu_order, not alphabetically' );

/* ------------------------------------------------------------------ */
/* 20) Protected uploads over REST                                    */
/* ------------------------------------------------------------------ */
/*
 * The upload router keyed on $_REQUEST['post_id'] — the admin async-upload
 * parameter. The REST media controller sends 'post'. So every file uploaded
 * through the API landed in the PUBLIC uploads root with a directly
 * downloadable URL, defeating protected-files entirely.
 */
pcle_section( '# Protected uploads over REST' );

wp_set_current_user( $admin );

$upload_probe = function ( $params ) {
	$saved   = $_REQUEST;
	$_REQUEST = $params; // phpcs:ignore WordPress.Security.NonceVerification
	$dir     = wp_upload_dir( null, false );
	$_REQUEST = $saved;
	return $dir['subdir'];
};

pcle_ok( false !== strpos( $upload_probe( array( 'post_id' => $module ) ), PCLE_PROTECTED_SUBDIR ), 'the admin upload parameter still routes into the protected directory' );
pcle_ok( false !== strpos( $upload_probe( array( 'post' => $module ) ), PCLE_PROTECTED_SUBDIR ), 'the REST upload parameter routes into the protected directory' );
pcle_eq( strpos( $upload_probe( array( 'post' => $orphan_page ) ), PCLE_PROTECTED_SUBDIR ), false, 'a non-CLE parent is left in the public uploads root' );
pcle_eq( strpos( $upload_probe( array() ), PCLE_PROTECTED_SUBDIR ), false, 'an upload with no parent is left in the public uploads root' );

wp_set_current_user( $student );
pcle_eq( strpos( $upload_probe( array( 'post' => $module ) ), PCLE_PROTECTED_SUBDIR ), false, 'a reader who cannot edit the parent does not steer the upload' );
wp_set_current_user( 0 );

/*
 * The routing above runs inside the `upload_dir` filter, and resolving the
 * uploads directory from in there used to re-enter that same filter — which
 * on a cold cache recursed until PHP died of memory exhaustion, taking the
 * whole request with it. Reproduced before the fix.
 *
 * The probes above would catch a regression only by killing the test run,
 * which is a terrible way to learn about it, so pin the mechanism directly:
 * the basedir must be resolvable from a value the caller already holds.
 */
pcle_ok(
	0 === strpos( pcle_protected_basedir( '/tmp/pcle-probe' ), '/tmp/pcle-probe' ),
	'the protected directory can be resolved without re-entering the uploads filter'
);

/* ------------------------------------------------------------------ */
/* 21) Authoring API                                                  */
/* ------------------------------------------------------------------ */
pcle_section( '# Authoring API' );

// Who am I — the app has no notion of an instructor without this.
wp_set_current_user( $student );
$me_student = rest_do_request( new WP_REST_Request( 'GET', '/platform-cle/v1/me' ) )->get_data();
pcle_eq( $me_student['can_author'], false, 'a participant is not offered authoring' );

wp_set_current_user( $admin );
$me_admin = rest_do_request( new WP_REST_Request( 'GET', '/platform-cle/v1/me' ) )->get_data();
pcle_eq( $me_admin['can_author'], true, 'staff are offered authoring' );
pcle_eq( $me_admin['id'], $admin, 'me reports the signed-in user' );

pcle_eq( rest_do_request( new WP_REST_Request( 'GET', '/platform-cle/v1/me' ) )->get_status(), 200, 'me is readable when signed in' );
wp_set_current_user( 0 );
pcle_eq( rest_do_request( new WP_REST_Request( 'GET', '/platform-cle/v1/me' ) )->get_status(), 401, 'me is closed to anonymous callers' );

// Access to the authoring routes.
foreach ( array( '/platform-cle/v1/authoring/programs', "/platform-cle/v1/authoring/programs/{$prog_a}/tree" ) as $route ) {
	pcle_eq( pcle_rest_get( 0, $route )->get_status(), 401, "anonymous {$route} → 401" );
	pcle_eq( pcle_rest_get( $student, $route )->get_status(), 403, "a participant {$route} → 403" );
	pcle_eq( pcle_rest_get( $admin, $route )->get_status(), 200, "staff {$route} → 200" );
}

// A participant enrolled in the programme still may not author it: reading and
// editing are different questions, and enrolment answers only the first.
pcle_eq( pcle_is_enrolled( $prog_a, $student ), true, 'the participant is genuinely enrolled' );
pcle_eq( pcle_rest_get( $student, "/platform-cle/v1/authoring/programs/{$prog_a}/tree" )->get_status(), 403, 'being enrolled does not confer authoring' );

// Wrong type and unknown ids are 404, not a 200 shaped from whatever that id was.
pcle_eq( pcle_rest_get( $admin, "/platform-cle/v1/authoring/programs/{$module}/tree" )->get_status(), 404, 'a module id on the tree route → 404' );
pcle_eq( pcle_rest_get( $admin, '/platform-cle/v1/authoring/programs/99999999/tree' )->get_status(), 404, 'an unknown id → 404' );

// The tree itself.
$tree = pcle_rest_get( $admin, "/platform-cle/v1/authoring/programs/{$prog_a}/tree" )->get_data();
pcle_eq( $tree['id'], $prog_a, 'the tree is rooted at the programme' );
pcle_eq( $tree['type'], 'pcle_program', 'the root carries its type' );
pcle_eq( $tree['allowed_children'], array( 'pcle_unit' ), 'a programme may only contain units' );
pcle_ok( isset( $tree['credits'] ), 'the root carries its credit hours' );

$tree_unit = $tree['children'][0];
pcle_eq( $tree_unit['id'], $unit, 'the tree carries the unit' );
pcle_ok( in_array( 'pcle_module', $tree_unit['allowed_children'], true ), 'a unit may contain modules' );
pcle_ok( in_array( 'pcle_event', $tree_unit['allowed_children'], true ), 'a unit may contain sessions' );

$tree_module_ids = wp_list_pluck( array_values( array_filter( $tree_unit['children'], fn( $n ) => 'pcle_module' === $n['type'] ) ), 'id' );
pcle_ok( in_array( $module, $tree_module_ids, true ), 'the tree reaches modules' );

/*
 * Drafts are the whole point of an authoring view: the participant routes
 * refuse them, so a builder that could not see them could not build.
 */
$draft_unit = wp_insert_post(
	array(
		'post_type'   => 'pcle_unit',
		'post_title'  => 'TEST Draft Unit',
		'post_status' => 'draft',
	)
);
update_post_meta( $draft_unit, '_pcle_program_id', $prog_a );
update_post_meta( $draft_unit, '_pcle_test', 1 );
$created_posts[] = $draft_unit;

$tree_with_draft = pcle_rest_get( $admin, "/platform-cle/v1/authoring/programs/{$prog_a}/tree" )->get_data();
pcle_ok( in_array( $draft_unit, wp_list_pluck( $tree_with_draft['children'], 'id' ), true ), 'the authoring tree includes drafts' );
pcle_eq( pcle_rest_get( $student, "/platform-cle/v1/units/{$draft_unit}" )->get_status(), 404, 'and the participant route still refuses that draft' );

// The programme list.
$authoring_list = pcle_rest_get( $admin, '/platform-cle/v1/authoring/programs' )->get_data();
$listed         = wp_list_pluck( $authoring_list['programs'], 'id' );
pcle_ok( in_array( $prog_a, $listed, true ), 'the programme list includes a programme staff may edit' );

$listed_a = $authoring_list['programs'][ array_search( $prog_a, $listed, true ) ];
pcle_eq( $listed_a['enrollees'], count( pcle_get_program_enrollee_ids( $prog_a ) ), 'the list reports the enrolee count' );
pcle_eq( $listed_a['modules'], count( pcle_get_program_module_ids( $prog_a ) ), 'the list reports the module count' );

// The child-type map must come from the relationship map, not a second copy.
pcle_eq( pcle_allowed_child_types( 'pcle_module' ), array( 'pcle_scenario', 'pcle_quiz', 'pcle_template' ), 'a module may contain scenarios, quizzes and templates' );
pcle_eq( pcle_allowed_child_types( 'pcle_scenario' ), array(), 'a scenario is a leaf' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 22) Authoring writes                                               */
/* ------------------------------------------------------------------ */
pcle_section( '# Authoring writes' );

/**
 * Runs an authoring request as a user.
 *
 * @param int    $uid    User to run as (0 = anonymous).
 * @param string $method HTTP method.
 * @param string $route  REST route.
 * @param array  $params Body parameters.
 * @return WP_REST_Response
 */
function pcle_authoring_call( $uid, $method, $route, $params = array() ) {
	wp_set_current_user( $uid );
	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request );
}

// Creation is gated on the intended parent, because the post being authorised
// does not exist yet and cannot be walked up from.
pcle_eq(
	pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_module', 'parent_id' => $prog_a, 'title' => 'TEST Wrong Parent' ) )->get_status(),
	400,
	'a module cannot be created directly inside a programme'
);
pcle_eq(
	pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_module', 'parent_id' => 0, 'title' => 'TEST No Parent' ) )->get_status(),
	400,
	'a module cannot be created with no parent at all'
);
pcle_eq(
	pcle_authoring_call( $student, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_unit', 'parent_id' => $prog_a, 'title' => 'TEST Student Unit' ) )->get_status(),
	403,
	'a participant cannot create curriculum'
);
pcle_eq(
	pcle_authoring_call( $student, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_program', 'title' => 'TEST Student Programme' ) )->get_status(),
	403,
	'a participant cannot create a programme'
);
pcle_eq(
	pcle_authoring_call( 0, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_unit', 'parent_id' => $prog_a ) )->get_status(),
	401,
	'an anonymous caller cannot create anything'
);

// The happy path.
$created_unit = pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_unit', 'parent_id' => $prog_a, 'title' => 'TEST Authored Unit' ) )->get_data();
$created_posts[] = $created_unit['id'];

pcle_eq( $created_unit['status'], 'draft', 'a new item starts as a draft, not visible to participants' );
pcle_eq( pcle_get_parent_id( $created_unit['id'] ), $prog_a, 'the new item is linked to the parent it was created in' );
pcle_eq( $created_unit['type'], 'pcle_unit', 'the new item is of the requested type' );

$first_module    = pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_module', 'parent_id' => $created_unit['id'], 'title' => 'TEST Authored Module One' ) )->get_data();
$second_module   = pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_module', 'parent_id' => $created_unit['id'], 'title' => 'TEST Authored Module Two' ) )->get_data();
$created_posts[] = $first_module['id'];
$created_posts[] = $second_module['id'];

pcle_ok( $second_module['menu_order'] > $first_module['menu_order'], 'a new sibling is appended rather than colliding' );

// Updates touch only what was sent.
pcle_authoring_call( $admin, 'PATCH', "/platform-cle/v1/authoring/nodes/{$first_module['id']}", array( 'content' => '<p>Body text</p>' ) );
pcle_authoring_call( $admin, 'PATCH', "/platform-cle/v1/authoring/nodes/{$first_module['id']}", array( 'title' => 'TEST Renamed Module' ) );
$after_rename = get_post( $first_module['id'] );

pcle_eq( $after_rename->post_title, 'TEST Renamed Module', 'a title can be changed' );
pcle_ok( false !== strpos( $after_rename->post_content, 'Body text' ), 'renaming did not blank the body it never sent' );

// Instructors do not hold unfiltered_html, and a builder must not become the
// way round that.
pcle_authoring_call( $admin, 'PATCH', "/platform-cle/v1/authoring/nodes/{$first_module['id']}", array( 'content' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>' ) );
$sanitised = get_post( $first_module['id'] )->post_content;
pcle_eq( strpos( $sanitised, '<script' ), false, 'a script tag does not survive an authoring write' );
pcle_eq( strpos( $sanitised, 'onerror' ), false, 'an event-handler attribute does not survive an authoring write' );

pcle_eq(
	pcle_authoring_call( $admin, 'PATCH', "/platform-cle/v1/authoring/nodes/{$first_module['id']}", array( 'status' => 'nonsense' ) )->get_status(),
	400,
	'an unknown status is refused'
);

/*
 * A blank title used to reach wp_update_post, which refused it with "Content,
 * title, and excerpt are empty" — its own wording, naming fields the caller
 * never sent — and this endpoint returned that as a 500. Sending a blank title
 * is a bad request, and a caller that is told 500 cannot tell its own mistake
 * from a broken server.
 */
foreach ( array( '' => 'an empty title', '   ' => 'a whitespace-only title' ) as $blank => $label ) {
	$refused = pcle_authoring_call( $admin, 'PATCH', "/platform-cle/v1/authoring/nodes/{$first_module['id']}", array( 'title' => $blank ) );

	pcle_eq( $refused->get_status(), 400, "{$label} is refused as a bad request, not a server error" );
	pcle_eq( $refused->get_data()['code'], 'pcle_title_required', "{$label} says which field is wrong" );
}

pcle_eq( get_post( $first_module['id'] )->post_title, 'TEST Renamed Module', 'and a refused blank title left the old one intact' );
pcle_eq(
	pcle_authoring_call( $student, 'PATCH', "/platform-cle/v1/authoring/nodes/{$first_module['id']}", array( 'title' => 'hijacked' ) )->get_status(),
	403,
	'a participant cannot edit curriculum'
);
pcle_eq( get_post( $first_module['id'] )->post_title, 'TEST Renamed Module', 'and the refused edit changed nothing' );

// Reordering is all-or-nothing: a half-applied order is one nobody chose.
$order_before = wp_list_pluck( pcle_authoring_get_children( $created_unit['id'], 'pcle_module' ), 'ID' );

$reordered = pcle_authoring_call(
	$admin,
	'POST',
	'/platform-cle/v1/authoring/reorder',
	array( 'parent_id' => $created_unit['id'], 'child_type' => 'pcle_module', 'ids' => array( $second_module['id'], $first_module['id'] ) )
);
pcle_eq( $reordered->get_status(), 200, 'a whole sibling list can be reordered' );
pcle_eq( $reordered->get_data()['ids'], array( $second_module['id'], $first_module['id'] ), 'the response reports the canonical order' );

$foreign = pcle_authoring_call(
	$admin,
	'POST',
	'/platform-cle/v1/authoring/reorder',
	array( 'parent_id' => $created_unit['id'], 'child_type' => 'pcle_module', 'ids' => array( $first_module['id'], $module ) )
);
pcle_eq( $foreign->get_status(), 400, 'a list containing something that is not a sibling is refused' );
pcle_eq(
	wp_list_pluck( pcle_authoring_get_children( $created_unit['id'], 'pcle_module' ), 'ID' ),
	array( $second_module['id'], $first_module['id'] ),
	'and the refused reorder left every sibling exactly where it was'
);

pcle_eq(
	pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/reorder', array( 'parent_id' => $created_unit['id'], 'child_type' => 'pcle_module', 'ids' => array( $first_module['id'] ) ) )->get_status(),
	400,
	'a partial list is refused rather than silently renumbering the rest'
);

/*
 * Duplicates used to slip through both guards: every id in [A, A] really is a
 * sibling, and the count really does match two siblings. The result named A
 * twice and B not at all, so B kept its old menu_order and tied with A — an
 * order nobody chose, reported as 200 OK.
 */
$dupe_order = wp_list_pluck( pcle_authoring_get_children( $created_unit['id'], 'pcle_module' ), 'ID' );

$duplicated = pcle_authoring_call(
	$admin,
	'POST',
	'/platform-cle/v1/authoring/reorder',
	array( 'parent_id' => $created_unit['id'], 'child_type' => 'pcle_module', 'ids' => array( $first_module['id'], $first_module['id'] ) )
);
pcle_eq( $duplicated->get_status(), 400, 'a list naming the same item twice is refused' );
pcle_eq( $duplicated->get_data()['code'], 'pcle_duplicate_order', 'and says so, rather than reporting an incomplete list' );
pcle_eq(
	wp_list_pluck( pcle_authoring_get_children( $created_unit['id'], 'pcle_module' ), 'ID' ),
	$dupe_order,
	'and the refused reorder left every sibling exactly where it was'
);

$menu_orders = array_map(
	static function ( $sibling ) {
		return (int) $sibling->menu_order;
	},
	pcle_authoring_get_children( $created_unit['id'], 'pcle_module' )
);
pcle_eq( count( array_unique( $menu_orders ) ), count( $menu_orders ), 'no two siblings share a menu_order' );

// Moving.
$move_target     = pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_unit', 'parent_id' => $prog_a, 'title' => 'TEST Move Target Unit' ) )->get_data();
$created_posts[] = $move_target['id'];

pcle_eq(
	pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/move', array( 'id' => $first_module['id'], 'parent_id' => $prog_a ) )->get_status(),
	400,
	'an item cannot be moved somewhere it could never live'
);
pcle_eq(
	pcle_authoring_call( $student, 'POST', '/platform-cle/v1/authoring/move', array( 'id' => $first_module['id'], 'parent_id' => $move_target['id'] ) )->get_status(),
	403,
	'a participant cannot move curriculum'
);

pcle_eq(
	pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/move', array( 'id' => $first_module['id'], 'parent_id' => $move_target['id'] ) )->get_status(),
	200,
	'staff can move an item to another unit'
);
pcle_eq( pcle_get_parent_id( $first_module['id'] ), $move_target['id'], 'the move actually reparented it' );

/*
 * The move guard authorises both the source and the destination, so nobody
 * can inject content into a programme they have no rights over. That case
 * cannot be exercised yet: pcle_user_can_edit_program() currently means "is
 * staff", so every author can edit every programme. It becomes testable with
 * per-programme assignment.
 */

// Deleting takes what hangs off it, but says so first.
$delete_refusal = pcle_authoring_call( $admin, 'DELETE', "/platform-cle/v1/authoring/nodes/{$created_unit['id']}" );
pcle_eq( $delete_refusal->get_status(), 409, 'deleting an item with contents is refused' );
pcle_ok( ! empty( $delete_refusal->as_error()->get_error_data()['descendants'] ), 'and the refusal lists what would have gone with it' );
pcle_ok( null !== get_post( $created_unit['id'] ), 'the refused delete removed nothing' );

$deleted = pcle_authoring_call( $admin, 'DELETE', "/platform-cle/v1/authoring/nodes/{$created_unit['id']}", array( 'cascade' => true ) );
pcle_eq( $deleted->get_status(), 200, 'an explicit cascade is accepted' );
pcle_eq( get_post( $created_unit['id'] ), null, 'the item is gone' );
pcle_eq( get_post( $second_module['id'] ), null, 'and so is what it contained' );

wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 23) Authored content                                               */
/* ------------------------------------------------------------------ */
/*
 * The builder sends plain text; the server escapes it and builds the markup.
 * Two properties matter: the round trip must be faithful, or editing loses
 * work, and no markup from a client may ever reach storage, because
 * instructors do not hold unfiltered_html.
 */
pcle_section( '# Authored content' );

$round_trips = array(
	'a paragraph'      => 'Just a paragraph.',
	'a heading'        => "## Foundations\n\nSome text.",
	'a small heading'  => "### Detail\n\nSome text.",
	'a list'           => "- first\n- second\n- third",
	'a quotation'      => '> The writ may not be suspended.',
	'inline emphasis'  => 'Cites **28 U.S.C. § 2241** and *the record*.',
	'a link'           => 'See [the statute](https://example.org/2241) for detail.',
	'the lot'          => "## Title\n\nText with **bold** and a [link](https://example.org/x).\n\n- one\n- two\n\n> Quoted.\n\nClosing.",
);

foreach ( $round_trips as $label => $text ) {
	$stored = pcle_authoring_content_from_text( $text );
	$back   = pcle_authoring_text_from_content( $stored );

	pcle_eq( $back['editable'], true, "{$label} survives as editable" );
	pcle_eq( trim( $back['text'] ), trim( $text ), "{$label} round-trips unchanged" );
}

/*
 * Markers are read per line, not per blank-line chunk. They used to be per
 * chunk, so the first line decided the type and swallowed the rest: a heading
 * followed directly by text and a list became one enormous <h2>. Nothing was
 * lost — it read back out identically, which is why the round-trip assertions
 * above never caught it — but what a participant saw was wrong, and avoiding
 * it meant knowing that blank lines were load-bearing.
 */
$tight   = "## Detention review\nCheck the deadline.\n- First point\n- Second point\n> Worth remembering\nClosing line.";
$tight_b = pcle_authoring_text_to_blocks( $tight );
$types   = array();
foreach ( $tight_b as $block ) {
	$types[] = $block['type'];
}
pcle_eq(
	$types,
	array( 'heading', 'paragraph', 'list', 'quote', 'paragraph' ),
	'markers start a new block without needing a blank line'
);
pcle_eq( count( $tight_b[2]['items'] ), 2, 'consecutive list lines are still one list' );
pcle_eq( $tight_b[0]['text'], 'Detention review', 'the heading takes only its own line' );

$tight_stored = pcle_authoring_content_from_text( $tight );
pcle_eq( substr_count( $tight_stored, '<!-- wp:heading' ), 1, 'and it stores exactly one heading block' );
pcle_ok( false !== strpos( $tight_stored, '<!-- wp:list' ), 'with the list as its own block' );
pcle_ok( false === strpos( $tight_stored, '<h2>Detention review' . "\n" ), 'nothing is swallowed into the heading' );

/*
 * Tight text does not round-trip byte for byte, and should not: reading it
 * back emits one blank line between blocks. What has to hold is that the
 * MEANING survives and then stays put — so the normalised form parses to the
 * same blocks, and saving it again changes nothing.
 */
$tight_back = pcle_authoring_text_from_content( $tight_stored );
pcle_eq( $tight_back['editable'], true, 'tight text survives as editable' );
pcle_eq(
	pcle_authoring_text_to_blocks( $tight_back['text'] ),
	$tight_b,
	'and reads back as the same blocks it was written as'
);
pcle_eq(
	pcle_authoring_content_from_text( $tight_back['text'] ),
	$tight_stored,
	'saving the normalised text again is a no-op'
);

// Blank-line-separated text has to parse exactly as it always did.
$spaced = "## Title\n\nOne.\n\n- a\n- b\n\n> Quoted.";
pcle_eq(
	pcle_authoring_text_to_blocks( $spaced ),
	pcle_authoring_text_to_blocks( "## Title\nOne.\n- a\n- b\n> Quoted." ),
	'blank lines and none produce the same blocks now'
);

// Two paragraphs separated by a blank line stay two paragraphs.
$two = pcle_authoring_text_to_blocks( "First para.\n\nSecond para." );
pcle_eq( count( $two ), 2, 'a blank line still separates two paragraphs' );

// A soft break inside a paragraph still joins.
$soft = pcle_authoring_text_to_blocks( "One line.\nSame paragraph." );
pcle_eq( count( $soft ), 1, 'consecutive plain lines are still one paragraph' );
pcle_eq( $soft[0]['text'], 'One line. Same paragraph.', 'joined with a space' );

// What gets stored is block markup, which is what opens cleanly in wp-admin.
$stored_blocks = pcle_authoring_content_from_text( "## Heading\n\nText." );
pcle_ok( false !== strpos( $stored_blocks, '<!-- wp:heading' ), 'headings are stored as blocks, not bare HTML' );
pcle_ok( false !== strpos( $stored_blocks, '<!-- wp:paragraph' ), 'paragraphs are stored as blocks' );

/*
 * No HTML from the client, ever. Note it is ESCAPED rather than stripped: the
 * author sees what they typed instead of it silently vanishing.
 */
$hostile = pcle_authoring_content_from_text( 'Hi <script>alert(1)</script> <iframe src="x"></iframe> <b>bold</b> <img src=x onerror=alert(1)>' );
pcle_eq( strpos( $hostile, '<script' ), false, 'a script tag cannot be authored' );
pcle_eq( strpos( $hostile, '<iframe' ), false, 'an iframe cannot be authored' );
pcle_eq( strpos( $hostile, '<img' ), false, 'an image tag with an event handler cannot be authored' );
pcle_ok( false !== strpos( $hostile, '&lt;img' ), 'that image tag survives only as escaped, inert text' );
pcle_ok( false !== strpos( $hostile, '&lt;b&gt;' ), 'typed markup is escaped and shown, not silently dropped' );

/*
 * The tags above are neutralised by escaping, not by removal — so the string
 * "onerror" is still present, as text. What must never happen is it becoming
 * an attribute, which is what the assertions above actually check. Confirm the
 * rendered output agrees, since that is what reaches a reader's browser.
 */
$hostile_rendered = apply_filters( 'the_content', $hostile );
pcle_eq( strpos( $hostile_rendered, '<img' ), false, 'and it is still not a tag once rendered' );

// A javascript: URL must not become a link.
$scheme = pcle_authoring_content_from_text( 'Try [this](javascript:alert(1)).' );
pcle_eq( strpos( $scheme, 'javascript:' ), false, 'a javascript URL does not become a link' );
pcle_ok( false !== strpos( $scheme, 'this' ), 'and its label survives as plain text' );

/*
 * Images and embeds are authored syntax now, so they come back as text rather
 * than as a preserved region.
 */
$image_block = "<!-- wp:image {\"id\":1} -->\n<figure class=\"wp-block-image\"><img src=\"/x.png\" alt=\"A map\"/></figure>\n<!-- /wp:image -->";
$image_read  = pcle_authoring_text_from_content( $image_block );
pcle_eq( trim( $image_read['text'] ), '![A map](/x.png)', 'an image block reads back as authored text' );
pcle_eq( count( $image_read['preserved'] ), 0, 'and needs no preserved region' );

$embed_block = "<!-- wp:embed {\"url\":\"https://example.org/v\"} -->\n<figure class=\"wp-block-embed\"></figure>\n<!-- /wp:embed -->";
$embed_read  = pcle_authoring_text_from_content( $embed_block );
pcle_eq( trim( $embed_read['text'] ), '@ https://example.org/v', 'an embed block reads back as authored text' );

$answer_block = "<!-- wp:shortcode -->\n[pcle_model_answer]<p>Because the warden holds them.</p>[/pcle_model_answer]\n<!-- /wp:shortcode -->";
$answer_read  = pcle_authoring_text_from_content( $answer_block );
pcle_eq( trim( $answer_read['text'] ), '! Because the warden holds them.', 'a model answer reads back as authored text' );
pcle_ok(
	false !== strpos( pcle_authoring_content_from_text( $answer_read['text'] ), '[pcle_model_answer]' ),
	'and writes back out as its shortcode'
);

/*
 * Everything else is preserved rather than refused. Its content is copied from
 * the stored post on save, never rebuilt from the client, so re-serialising
 * something we could not fully parse still never happens.
 */
$preserved_cases = array(
	'pre-block HTML' => '<p>Written before the builder existed.</p>',
	'a table'        => "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tr><td>x</td></tr></table></figure>\n<!-- /wp:table -->",
	'another plugin' => "<!-- wp:acme/widget {\"n\":1} /-->",
);

foreach ( $preserved_cases as $label => $content ) {
	$read = pcle_authoring_text_from_content( $content );
	pcle_eq( $read['editable'], true, "{$label} is editable" );
	pcle_eq( count( $read['preserved'] ), 1, "{$label} becomes one preserved region" );
	pcle_eq( trim( $read['text'] ), $read['preserved'][0]['token'], "{$label} is stood in for by its token" );

	// The round trip puts it back exactly as it was found.
	pcle_eq(
		trim( pcle_authoring_content_from_text( $read['text'], $content ) ),
		trim( $content ),
		"{$label} round trips byte for byte"
	);
}

pcle_eq( pcle_authoring_block_label( 'core/table' ), 'Table', 'a preserved region is named for what it is' );
pcle_eq( pcle_authoring_block_label( 'acme/widget' ), 'Widget', 'including one this plugin has never heard of' );
pcle_ok(
	is_wp_error( pcle_authoring_content_from_text( '[[block:0:deadbeef]]', '<p>Something else.</p>' ) ),
	'a token that does not match the stored block is an error'
);

pcle_eq( pcle_authoring_text_from_content( '' )['editable'], true, 'empty content is editable' );

/* ------------------------------------------------------------------ */
/* 24) The node editor endpoint                                       */
/* ------------------------------------------------------------------ */
pcle_section( '# Node editor endpoint' );

$editable_module = pcle_make_post( 'pcle_module', 'TEST Editable Module', array( '_pcle_unit_id' => $unit ) );
$created_posts[] = $editable_module;

pcle_eq( pcle_rest_get( 0, "/platform-cle/v1/authoring/nodes/{$editable_module}" )->get_status(), 401, 'anonymous cannot open a node for editing' );
pcle_eq( pcle_rest_get( $student, "/platform-cle/v1/authoring/nodes/{$editable_module}" )->get_status(), 403, 'a participant cannot open a node for editing' );

$saved = pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$editable_module}",
	array( 'body' => "## Section\n\nBody with **emphasis**." )
);
pcle_eq( $saved->get_status(), 200, 'staff can save an authored body' );

$reopened = pcle_rest_get( $admin, "/platform-cle/v1/authoring/nodes/{$editable_module}" )->get_data();
pcle_eq( $reopened['editable'], true, 'what the builder wrote, the builder can reopen' );
pcle_eq( trim( $reopened['body'] ), "## Section\n\nBody with **emphasis**.", 'and it comes back exactly as typed' );
pcle_ok( false !== strpos( $reopened['rendered'], '<strong>emphasis</strong>' ), 'the preview is the server rendering, with the emphasis applied' );
pcle_ok( isset( $reopened['program'] ) && $reopened['program']['id'] === $prog_a, 'the node knows which programme it belongs to' );

// A body written in WordPress opens as a preserved region, not read-only.
wp_update_post( array( 'ID' => $editable_module, 'post_content' => '<p>Legacy HTML from wp-admin.</p>' ) );
$legacy = pcle_rest_get( $admin, "/platform-cle/v1/authoring/nodes/{$editable_module}" )->get_data();
pcle_eq( $legacy['editable'], true, 'content the builder cannot express is still editable' );
pcle_eq( count( $legacy['preserved'] ), 1, 'and is reported as one preserved region' );
pcle_eq( $legacy['preserved'][0]['label'], 'Content written in WordPress', 'named for what it is' );
pcle_eq( trim( $legacy['body'] ), $legacy['preserved'][0]['token'], 'the body is the token standing in for it' );
pcle_ok( false !== strpos( $legacy['rendered'], 'Legacy HTML' ), 'and it is still rendered for reading' );

// Editing around a preserved region keeps it byte-for-byte.
$around = pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$editable_module}",
	array( 'body' => "## Before\n\n{$legacy['preserved'][0]['token']}\n\nAfter." )
);
pcle_eq( $around->get_status(), 200, 'a body carrying a preserved token saves' );
$kept = get_post_field( 'post_content', $editable_module );
pcle_ok( false !== strpos( $kept, '<p>Legacy HTML from wp-admin.</p>' ), 'the preserved region survives verbatim' );
pcle_ok( false !== strpos( $kept, '<h2>Before</h2>' ), 'and the authored parts around it were written' );

// A token that no longer matches the stored post is refused, not guessed at.
$stale = pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$editable_module}",
	array( 'body' => '[[block:0:deadbeef]]' )
);
pcle_eq( $stale->get_status(), 409, 'a stale preserved token is a conflict' );

// A token cannot be used to reach a block that is not there.
$forged = pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$editable_module}",
	array( 'body' => '[[block:99:00000000]]' )
);
pcle_eq( $forged->get_status(), 409, 'a token pointing at nothing is refused' );

// Images, embeds and model answers are first-class authored syntax.
pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$editable_module}",
	array( 'body' => "![A filing](https://example.org/a.png)\n\n@ https://example.org/v\n\n! The answer." )
);
$rich = pcle_rest_get( $admin, "/platform-cle/v1/authoring/nodes/{$editable_module}" )->get_data();
pcle_eq( count( $rich['preserved'] ), 0, 'none of it needs preserving' );
pcle_eq(
	trim( $rich['body'] ),
	"![A filing](https://example.org/a.png)\n\n@ https://example.org/v\n\n! The answer.",
	'image, embed and model answer all round trip'
);
$rich_stored = get_post_field( 'post_content', $editable_module );
pcle_ok( false !== strpos( $rich_stored, '<!-- wp:image -->' ), 'the image is stored as a native image block' );
pcle_ok( false !== strpos( $rich_stored, '<!-- wp:embed' ), 'the embed as a native embed block' );
pcle_ok( false !== strpos( $rich_stored, '[pcle_model_answer]' ), 'and the model answer as its shortcode' );

// The client still cannot smuggle markup in through the new syntax.
pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$editable_module}",
	array( 'body' => '![x"onerror="alert(1)](javascript:alert(1))' )
);
$smuggled = get_post_field( 'post_content', $editable_module );
pcle_ok( false === strpos( $smuggled, 'javascript:' ), 'an image URL with a script scheme is dropped' );
pcle_ok( false === strpos( $smuggled, '"onerror="' ), 'and an alt attribute cannot break out of the tag' );
pcle_ok( false !== strpos( $smuggled, '&quot;onerror=&quot;' ), 'its quotes having been escaped instead' );

// The participant view shows what the author wrote.
pcle_authoring_call( $admin, 'PATCH', "/platform-cle/v1/authoring/nodes/{$editable_module}", array( 'body' => '- alpha', 'status' => 'publish' ) );
$as_participant = pcle_rest_get( $student, "/platform-cle/v1/modules/{$editable_module}" )->get_data();
pcle_ok( false !== strpos( $as_participant['content'], '<li>alpha</li>' ), 'the participant sees the list the author wrote' );

// Creating a programme: the one creation with no parent.
$new_program = pcle_authoring_call( $admin, 'POST', '/platform-cle/v1/authoring/nodes', array( 'type' => 'pcle_program', 'title' => 'TEST Authored Programme' ) );
pcle_eq( $new_program->get_status(), 200, 'staff can create a programme' );
$created_posts[] = $new_program->get_data()['id'];
pcle_eq( $new_program->get_data()['status'], 'draft', 'a new programme starts as a draft' );

$with_credits = pcle_authoring_call(
	$admin,
	'PATCH',
	"/platform-cle/v1/authoring/nodes/{$new_program->get_data()['id']}",
	array( 'credits' => array( 'ks' => 2.6, 'mo' => 0 ) )
);
pcle_eq( $with_credits->get_status(), 200, 'credit hours can be set from the builder' );
pcle_eq( pcle_get_credit_hours( $new_program->get_data()['id'] )['ks'], 2.5, 'and are rounded to the quarter hour' );
pcle_eq( pcle_get_credit_hours( $new_program->get_data()['id'] )['mo'], 0.0, 'a blank jurisdiction stays unaccredited' );

/* ------------------------------------------------------------------ */
/* Attaching files                                                     */
/* ------------------------------------------------------------------ */
pcle_section( '# Attaching files' );

/**
 * Posts a file to the media endpoint the way an upload arrives.
 *
 * The bytes are written to a fresh temp file per call because
 * media_handle_sideload() MOVES what it is given: reusing one path would leave
 * the second call pointing at a file that is no longer there.
 */
function pcle_upload_to_node( $node_id, $filename, $bytes ) {
	// Plain tempnam(): wp_tempnam() lives in wp-admin/includes/file.php, which
	// this CLI context has not loaded.
	$tmp = tempnam( sys_get_temp_dir(), 'pcle' );
	file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$request = new WP_REST_Request( 'POST', "/platform-cle/v1/authoring/nodes/{$node_id}/media" );
	$request->set_file_params(
		array(
			'file' => array(
				'name'     => $filename,
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => strlen( $bytes ),
			),
		)
	);

	return rest_do_request( $request );
}

$pdf_bytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );

wp_set_current_user( $admin );

$up_pdf = pcle_upload_to_node( $editable_module, 'brief.pdf', $pdf_bytes );
pcle_eq( $up_pdf->get_status(), 200, 'staff can attach a document' );
$pdf_id = $up_pdf->get_data()['id'];
$created_posts[] = $pdf_id;
pcle_eq( $up_pdf->get_data()['token'], "[[media:{$pdf_id}]]", 'and are given the token for the body' );
pcle_ok( $up_pdf->get_data()['protected'], 'the file lands behind the download gate' );
pcle_ok(
	false !== strpos( get_attached_file( $pdf_id ), '/' . PCLE_PROTECTED_SUBDIR . '/' ),
	'in the protected directory on disk'
);
pcle_ok(
	false !== strpos( wp_get_attachment_url( $pdf_id ), 'pcle_download=' ),
	'and its URL is the gated endpoint, not the raw path'
);

$up_png = pcle_upload_to_node( $editable_module, 'exhibit.png', $png_bytes );
pcle_eq( $up_png->get_status(), 200, 'an image can be attached too' );
$png_id = $up_png->get_data()['id'];
$created_posts[] = $png_id;
pcle_ok( $up_png->get_data()['is_image'], 'and is reported as an image' );

// The allowlist is checked against the bytes, not the name.
$up_php = pcle_upload_to_node( $editable_module, 'shell.php', '<?php echo 1;' );
pcle_eq( $up_php->get_status(), 400, 'a script is refused' );
pcle_eq( $up_php->get_data()['code'], 'pcle_file_type_not_allowed', 'by type, and named as such' );

$up_disguised = pcle_upload_to_node( $editable_module, 'sneaky.pdf', '<?php echo 1;' );
pcle_eq( $up_disguised->get_status(), 400, 'a script wearing a .pdf name is refused' );

// Each kind becomes the block that suits it.
$doc_markup = pcle_authoring_content_from_text( "[[media:{$pdf_id}]]" );
pcle_ok( false !== strpos( $doc_markup, '<!-- wp:file' ), 'a document becomes a file block' );
pcle_ok( false !== strpos( $doc_markup, 'pcle_download=' ), 'pointing at the gated URL' );

$img_markup = pcle_authoring_content_from_text( "[[media:{$png_id}]]" );
pcle_ok( false !== strpos( $img_markup, '<!-- wp:image' ), 'an image becomes an image block' );

// And reads back as the file, not as the URL it currently resolves to.
$media_back = pcle_authoring_text_from_content( $doc_markup . "\n\n" . $img_markup );
pcle_eq(
	trim( $media_back['text'] ),
	"[[media:{$pdf_id}]]\n\n[[media:{$png_id}]]",
	'both round trip as their attachment'
);
pcle_eq( count( $media_back['preserved'] ), 0, 'and neither needs preserving' );

// A reference to something that is not there leaves nothing behind.
pcle_eq(
	trim( pcle_authoring_content_from_text( '[[media:99999999]]' ) ),
	'',
	'a token for a missing attachment writes nothing'
);

// Someone with no rights over the programme cannot attach to it.
wp_set_current_user( $student );
$up_student = pcle_upload_to_node( $editable_module, 'brief.pdf', $pdf_bytes );
pcle_eq( $up_student->get_status(), 403, 'a participant cannot attach files' );

wp_set_current_user( 0 );
$up_out = pcle_upload_to_node( $editable_module, 'brief.pdf', $pdf_bytes );
pcle_eq( $up_out->get_status(), 401, 'nor can a signed-out visitor' );

/* ------------------------------------------------------------------ */
/* 25) The week-to-unit migration                                     */
/* ------------------------------------------------------------------ */
/*
 * Renaming the concept moved the post type and the relationship meta key, so
 * existing rows have to move with them. This is the only part of the rename
 * that can destroy something: without it an install keeps its units as an
 * unregistered post type — invisible in the admin — and every module and
 * session loses its parent, because the code reads a meta key the rows do not
 * carry.
 */
pcle_section( '# Week to unit migration' );

$legacy_program = pcle_make_post( 'pcle_program', 'TEST Legacy Programme' );
$legacy_unit    = pcle_make_post( 'pcle_unit', 'TEST Legacy Unit', array( '_pcle_program_id' => $legacy_program ) );
$legacy_module  = pcle_make_post( 'pcle_module', 'TEST Legacy Module', array( '_pcle_unit_id' => $legacy_unit ) );
$legacy_event   = pcle_make_post( 'pcle_event', 'TEST Legacy Session', array( '_pcle_unit_id' => $legacy_unit ) );
$created_posts  = array_merge( $created_posts, array( $legacy_program, $legacy_unit, $legacy_module, $legacy_event ) );

pcle_eq( count( pcle_get_units( $legacy_program ) ), 1, 'the fixture starts with a reachable unit' );

// Put this fixture back the way an install looked before the rename.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->update( $wpdb->posts, array( 'post_type' => 'pcle_week' ), array( 'ID' => $legacy_unit ) );
$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_pcle_week_id' ), array( 'post_id' => $legacy_module, 'meta_key' => '_pcle_unit_id' ) );
$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_pcle_week_id' ), array( 'post_id' => $legacy_event, 'meta_key' => '_pcle_unit_id' ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery
wp_cache_flush();

pcle_eq( count( pcle_get_units( $legacy_program ) ), 0, 'before migrating, the old rows are invisible to the new code' );
pcle_eq( count( pcle_get_modules( $legacy_unit ) ), 0, 'and its modules have lost their parent' );

$migrated = pcle_migrate_week_to_unit();

pcle_ok( $migrated['posts'] >= 1, 'the migration reports the units it moved' );
pcle_ok( $migrated['meta'] >= 2, 'and the relationship rows it moved' );
pcle_eq( get_post_type( $legacy_unit ), 'pcle_unit', 'the unit is now of the new type' );
pcle_eq( count( pcle_get_units( $legacy_program ) ), 1, 'the unit is reachable from its programme again' );
pcle_eq( count( pcle_get_modules( $legacy_unit ) ), 1, 'its module found its parent again' );
pcle_eq( count( pcle_get_events( $legacy_unit ) ), 1, 'and so did its session — both hang off the same key' );

// It runs on every version bump, so a second pass must be a no-op.
$again = pcle_migrate_week_to_unit();
pcle_eq( $again['posts'], 0, 'a second migration pass moves no posts' );
pcle_eq( $again['meta'], 0, 'and no relationship rows' );
pcle_eq( count( pcle_get_units( $legacy_program ) ), 1, 'and the hierarchy is still intact afterwards' );

/* ------------------------------------------------------------------ */
/* 26) Emails                                                         */
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

$rem_event       = pcle_make_post( 'pcle_event', 'TEST Session', array( '_pcle_unit_id' => $unit ) );
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
/* Quizzes                                                            */
/* ------------------------------------------------------------------ */
pcle_section( '# Quizzes' );

$quiz = pcle_make_post( 'pcle_quiz', 'TEST Quiz', array( '_pcle_module_id' => $module ) );
$created_posts[] = $quiz;

// A quiz is curriculum: it hangs off a module and is gated like everything else.
pcle_eq( pcle_get_program_for_post( $quiz ), $prog_a, 'quiz walks up to its programme' );
pcle_eq( in_array( 'pcle_quiz', pcle_allowed_child_types( 'pcle_module' ), true ), true, 'a module may contain a quiz' );
pcle_eq( in_array( 'pcle_quiz', pcle_protected_post_types(), true ), true, 'quizzes are REST-protected' );
pcle_eq( pcle_can_access_post( $quiz, $student ), true, 'enrolled student can access a quiz in their programme' );
pcle_eq( pcle_can_access_post( $quiz, $outsider ), false, 'unenrolled user cannot access a quiz' );

// The sanitiser is the only way questions get stored, so it carries the rules.
$stored = pcle_set_quiz_questions(
	$quiz,
	array(
		array(
			'prompt'   => 'Who is the proper respondent?',
			'type'     => 'single',
			'required' => true,
			'feedback' => 'The immediate custodian.',
			'choices'  => array(
				array( 'text' => 'The facility warden', 'correct' => true ),
				array( 'text' => 'The Attorney General', 'correct' => true ),
				array( 'text' => 'The sentencing judge' ),
			),
		),
		array(
			'prompt'  => 'Which of these are habeas grounds?',
			'type'    => 'multiple',
			'choices' => array(
				array( 'text' => 'Prolonged detention', 'correct' => true ),
				array( 'text' => 'Unlawful custody', 'correct' => true ),
				array( 'text' => 'Dislike of the venue' ),
			),
		),
		array(
			'prompt' => 'What would you argue first, and why?',
			'type'   => 'text',
		),
		// Dropped: no prompt at all.
		array( 'type' => 'single', 'choices' => array( array( 'text' => 'x' ), array( 'text' => 'y' ) ) ),
		// Dropped: a scored question needs something to choose between.
		array( 'prompt' => 'Only one option?', 'type' => 'single', 'choices' => array( array( 'text' => 'x' ) ) ),
	)
);

pcle_eq( count( $stored ), 3, 'sanitiser keeps the three usable questions' );
pcle_eq( $stored[0]['key'], 'q1', 'questions get a generated key' );
pcle_eq( $stored[0]['choices'][1]['correct'], false, '"one correct answer" keeps only the first correct choice' );
pcle_eq( $stored[1]['choices'][0]['correct'], true, 'multiple-answer questions keep every correct choice' );
pcle_eq( $stored[1]['choices'][1]['correct'], true, 'multiple-answer questions keep the second correct choice' );
pcle_eq( count( $stored[2]['choices'] ), 0, 'free-text questions have no choices' );

/*
 * The builder's "Add a question" button sends a placeholder question rather
 * than a blank one, because a blank one is dropped by the rule directly above
 * — which made adding the first question of a quiz impossible. This pins the
 * contract from the other side: whatever the editor sends for a new question
 * has to survive the save that created it.
 *
 * If this fails, check apps/web/src/app/actions/authoring.ts (blankQuestion).
 */
$fresh = pcle_sanitize_quiz_questions(
	array(
		array(
			'key'      => '',
			'type'     => 'single',
			'prompt'   => 'New question',
			'help'     => '',
			'feedback' => '',
			'required' => false,
			'choices'  => array(
				array( 'key' => '', 'text' => 'New answer', 'correct' => false ),
				array( 'key' => '', 'text' => 'New answer', 'correct' => false ),
			),
		),
	)
);
pcle_eq( count( $fresh ), 1, 'a newly added question survives its first save' );
pcle_eq( count( $fresh[0]['choices'] ), 2, 'and keeps both of its blank answers' );
pcle_eq( $fresh[0]['choices'][0]['key'] === $fresh[0]['choices'][1]['key'], false, 'whose keys are distinct despite identical text' );

// A scored question nobody can get right is an authoring slip, not a valid quiz.
$rescued = pcle_sanitize_quiz_questions(
	array(
		array(
			'prompt'  => 'Nothing marked correct',
			'type'    => 'single',
			'choices' => array( array( 'text' => 'a' ), array( 'text' => 'b' ) ),
		),
	)
);
pcle_eq( $rescued[0]['choices'][0]['correct'], true, 'a question with no correct answer gets one' );

// Duplicate keys would make two questions share a form field on submission.
$deduped = pcle_sanitize_quiz_questions(
	array(
		array( 'prompt' => 'First', 'type' => 'text', 'key' => 'same' ),
		array( 'prompt' => 'Second', 'type' => 'text', 'key' => 'same' ),
	)
);
pcle_eq( $deduped[0]['key'] === $deduped[1]['key'], false, 'duplicate question keys are made unique' );

// Free text is deliberately unscored, so it cannot be part of the maximum.
pcle_eq( pcle_quiz_max_score( $quiz ), 2, 'max score counts only the scored questions' );

/*
 * The one that matters. Everything above is correctness; this is the leak the
 * plugin has already had twice in another form.
 */
$taking = pcle_quiz_questions_for_taking( $quiz );
$leaked = 0;
foreach ( $taking as $question ) {
	$leaked += array_key_exists( 'feedback', $question ) ? 1 : 0;

	foreach ( $question['choices'] as $choice ) {
		$leaked += array_key_exists( 'correct', $choice ) ? 1 : 0;
	}
}
pcle_eq( count( $taking ), 3, 'the participant shape has every question' );
pcle_eq( $leaked, 0, 'the participant shape leaks no answers and no feedback' );
pcle_eq( $taking[0]['choices'][0]['text'], 'The facility warden', 'the participant shape keeps the choice text' );

// Gating and pass mark: stored from the start, enforced when scoring lands.
pcle_eq( pcle_quiz_gates_completion( $quiz ), false, 'a quiz does not gate its module by default' );
update_post_meta( $quiz, PCLE_QUIZ_GATES_META, 1 );
pcle_eq( pcle_quiz_gates_completion( $quiz ), true, 'gating can be switched on per quiz' );

/*
 * Switched back off before moving on. This fixture hangs off $module, which
 * later sections complete, and a quiz left gating would block them for the
 * rest of the run — the gating tests below build their own fixture for that.
 */
delete_post_meta( $quiz, PCLE_QUIZ_GATES_META );
pcle_eq( pcle_quiz_gates_completion( $quiz ), false, 'gating can be switched back off' );
pcle_eq( pcle_quiz_pass_mark( $quiz ), PCLE_QUIZ_DEFAULT_PASS_MARK, 'pass mark defaults when unset' );
pcle_eq( pcle_sanitize_quiz_pass_mark( 0 ), 1, 'pass mark cannot be zero' );
pcle_eq( pcle_sanitize_quiz_pass_mark( 250 ), 100, 'pass mark is capped at 100' );

/* ------------------------------------------------------------------ */
/* Quiz marking and attempts                                          */
/* ------------------------------------------------------------------ */
pcle_section( '# Quiz marking' );

$marked_quiz = pcle_make_post( 'pcle_quiz', 'TEST Marked Quiz', array( '_pcle_module_id' => $module2 ) );
$created_posts[] = $marked_quiz;

pcle_set_quiz_questions(
	$marked_quiz,
	array(
		array(
			'key'     => 'respondent',
			'prompt'  => 'Proper respondent?',
			'type'    => 'single',
			'choices' => array(
				array( 'key' => 'warden', 'text' => 'The facility warden', 'correct' => true ),
				array( 'key' => 'ag', 'text' => 'The Attorney General' ),
			),
		),
		array(
			'key'     => 'grounds',
			'prompt'  => 'Valid grounds?',
			'type'    => 'multiple',
			'choices' => array(
				array( 'key' => 'prolonged', 'text' => 'Prolonged detention', 'correct' => true ),
				array( 'key' => 'unlawful', 'text' => 'Unlawful custody', 'correct' => true ),
				array( 'key' => 'venue', 'text' => 'Venue dislike' ),
			),
		),
		array( 'key' => 'thoughts', 'prompt' => 'What would you argue first?', 'type' => 'text' ),
	)
);

// Marking is a pure function of the questions and the answers.
$all_right = pcle_mark_quiz( $marked_quiz, array(
	'respondent' => 'warden',
	'grounds'    => array( 'unlawful', 'prolonged' ), // order must not matter
	'thoughts'   => 'Custody first.',
) );
pcle_eq( $all_right['score'], 2, 'every scored question right gives full marks' );
pcle_eq( $all_right['max_score'], 2, 'the free-text question is not part of the maximum' );
pcle_eq( $all_right['percent'], 100, 'full marks is 100%' );
pcle_eq( $all_right['passed'], true, 'full marks passes' );
pcle_eq( $all_right['questions'][2]['scored'], false, 'free text is recorded but not scored' );
pcle_eq( $all_right['questions'][2]['response'], 'Custody first.', 'the free-text response is kept' );

// All-or-nothing on multiple answers: half right is not half a mark.
$half = pcle_mark_quiz( $marked_quiz, array( 'respondent' => 'warden', 'grounds' => array( 'prolonged' ) ) );
pcle_eq( $half['score'], 1, 'a partially correct multiple answer scores nothing' );
pcle_eq( $half['percent'], 50, 'one of two scored questions is 50%' );
pcle_eq( $half['passed'], false, '50% is below the default pass mark' );

// Choosing a wrong extra is just as wrong as missing one.
$extra = pcle_mark_quiz( $marked_quiz, array( 'grounds' => array( 'prolonged', 'unlawful', 'venue' ) ) );
pcle_eq( $extra['questions'][1]['correct'], false, 'an extra wrong choice fails the question' );

$unanswered = pcle_mark_quiz( $marked_quiz, array() );
pcle_eq( $unanswered['score'], 0, 'answering nothing scores nothing' );
pcle_eq( $unanswered['questions'][0]['answered'], false, 'an unanswered question is reported as such' );

// Recording an attempt.
$attempt = pcle_record_quiz_attempt( $marked_quiz, array( 'respondent' => 'warden', 'grounds' => array( 'prolonged', 'unlawful' ) ), $student );
pcle_eq( is_wp_error( $attempt ), false, 'an attempt can be recorded' );
pcle_eq( $attempt['passed'], true, 'the recorded attempt passed' );
pcle_eq( count( pcle_get_quiz_attempts( $marked_quiz, $student ) ), 1, 'the attempt is stored' );
pcle_eq( pcle_user_passed_quiz( $marked_quiz, $student ), true, 'the student has passed the quiz' );
pcle_eq( pcle_user_passed_quiz( $marked_quiz, $outsider ), false, 'another user has not' );

// Several sittings are all kept, and passing once is permanent.
pcle_record_quiz_attempt( $marked_quiz, array( 'respondent' => 'ag' ), $student );
pcle_eq( count( pcle_get_quiz_attempts( $marked_quiz, $student ) ), 2, 'a second attempt is kept alongside the first' );
pcle_eq( pcle_user_passed_quiz( $marked_quiz, $student ), true, 'a later worse attempt does not undo having passed' );

// A stored grade is a statement about a moment: editing the quiz afterwards
// must not rewrite it.
$before_edit = pcle_get_quiz_attempts( $marked_quiz, $student )[1]['score'];
pcle_set_quiz_questions( $marked_quiz, array_merge(
	pcle_get_quiz_questions( $marked_quiz ),
	array( array( 'key' => 'extra', 'prompt' => 'Added later', 'type' => 'single',
		'choices' => array( array( 'key' => 'a', 'text' => 'A', 'correct' => true ), array( 'key' => 'b', 'text' => 'B' ) ) ) )
) );
pcle_eq( pcle_get_quiz_attempts( $marked_quiz, $student )[1]['score'], $before_edit, 'editing the quiz does not change a recorded grade' );

// Required questions are enforced server-side, not just by the browser.
$req_quiz = pcle_make_post( 'pcle_quiz', 'TEST Required Quiz', array( '_pcle_module_id' => $module2 ) );
$created_posts[] = $req_quiz;
pcle_set_quiz_questions( $req_quiz, array(
	array( 'key' => 'must', 'prompt' => 'Must answer', 'type' => 'single', 'required' => true,
		'choices' => array( array( 'key' => 'a', 'text' => 'A', 'correct' => true ), array( 'key' => 'b', 'text' => 'B' ) ) ),
) );
$refused = pcle_record_quiz_attempt( $req_quiz, array(), $student );
pcle_eq( is_wp_error( $refused ), true, 'a submission missing a required answer is refused' );
pcle_eq( $refused->get_error_data()['status'], 400, 'the refusal is a 400' );
pcle_eq( $refused->get_error_data()['missing'], array( 'must' ), 'the refusal names the missing question' );
pcle_eq( count( pcle_get_quiz_attempts( $req_quiz, $student ) ), 0, 'a refused submission records nothing' );

/*
 * Attempts must not outlive what they point at. WordPress reuses
 * auto-increment IDs, so an orphan row is not merely invisible — it can end up
 * attached to a future quiz, or to a future user, as somebody else's grade.
 */
$doomed = pcle_make_post( 'pcle_quiz', 'TEST Doomed Quiz', array( '_pcle_module_id' => $module2 ) );
pcle_set_quiz_questions( $doomed, array(
	array( 'key' => 'a', 'prompt' => 'A?', 'type' => 'single',
		'choices' => array( array( 'key' => 'y', 'text' => 'Y', 'correct' => true ), array( 'key' => 'n', 'text' => 'N' ) ) ),
) );
pcle_record_quiz_attempt( $doomed, array( 'a' => 'y' ), $student );
pcle_eq( count( pcle_get_quiz_attempts( $doomed, $student ) ), 1, 'the doomed quiz has an attempt' );
wp_delete_post( $doomed, true );
pcle_eq( count( pcle_get_quiz_attempts( $doomed, $student ) ), 0, 'deleting a quiz removes its attempts' );

// An empty quiz cannot be sat at all.
$empty_quiz = pcle_make_post( 'pcle_quiz', 'TEST Empty Quiz', array( '_pcle_module_id' => $module2 ) );
$created_posts[] = $empty_quiz;
pcle_eq( is_wp_error( pcle_record_quiz_attempt( $empty_quiz, array(), $student ) ), true, 'a quiz with no questions cannot be sat' );

/* ------------------------------------------------------------------ */
/* Quiz gating of module completion                                   */
/* ------------------------------------------------------------------ */
pcle_section( '# Quiz gating' );

$gate_quiz = pcle_make_post( 'pcle_quiz', 'TEST Gate Quiz', array( '_pcle_module_id' => $module ) );
$created_posts[] = $gate_quiz;
pcle_set_quiz_questions( $gate_quiz, array(
	array( 'key' => 'q', 'prompt' => 'Gate question', 'type' => 'single',
		'choices' => array( array( 'key' => 'right', 'text' => 'Right', 'correct' => true ), array( 'key' => 'wrong', 'text' => 'Wrong' ) ) ),
) );

pcle_unmark_module_complete( $module, $student );

// Off by default: an ungated quiz changes nothing.
pcle_eq( pcle_module_completion_blockers( $module, $student ), array(), 'an ungated quiz does not block completion' );
pcle_eq( pcle_mark_module_complete( $module, $student ), true, 'the module completes while the quiz is ungated' );

// Switched on, it blocks until passed.
pcle_unmark_module_complete( $module, $student );
update_post_meta( $gate_quiz, PCLE_QUIZ_GATES_META, 1 );
pcle_eq( pcle_module_completion_blockers( $module, $student ), array( $gate_quiz ), 'a required unpassed quiz blocks completion' );
pcle_eq( pcle_mark_module_complete( $module, $student ), false, 'the module refuses to complete' );
pcle_eq( pcle_is_module_complete( $module, $student ), false, 'and nothing was recorded' );

// A draft quiz must not be able to freeze a cohort.
wp_update_post( array( 'ID' => $gate_quiz, 'post_status' => 'draft' ) );
pcle_eq( pcle_module_completion_blockers( $module, $student ), array(), 'an unpublished required quiz does not block' );
wp_update_post( array( 'ID' => $gate_quiz, 'post_status' => 'publish' ) );

// Passing clears it.
pcle_record_quiz_attempt( $gate_quiz, array( 'q' => 'right' ), $student );
pcle_eq( pcle_module_completion_blockers( $module, $student ), array(), 'passing clears the blocker' );
pcle_eq( pcle_mark_module_complete( $module, $student ), true, 'the module completes once the quiz is passed' );

// The gate reaches program-level progress, not just the one call.
pcle_eq( pcle_get_program_progress( $prog_a, $student )['completed'] > 0, true, 'programme progress reflects the completion' );

/* ------------------------------------------------------------------ */
/* Quiz results in the cohort report                                  */
/* ------------------------------------------------------------------ */
pcle_section( '# Quiz results in reports' );

/*
 * The report is built from grouped queries rather than the per-user helpers,
 * so — as with progress and attendance above — it has to be checked against
 * them, or the two drift apart without anything failing.
 */
$quiz_report = pcle_get_program_report( $prog_a )[ $student ];

$program_quizzes = pcle_get_program_quiz_ids( $prog_a );
pcle_eq( in_array( $gate_quiz, $program_quizzes, true ), true, 'a quiz is found from its programme' );
pcle_eq( $quiz_report['quizzes'], count( $program_quizzes ), 'the report counts every quiz in the programme' );
pcle_eq( $quiz_report['quizzes_passed'] >= 1, true, 'a passed quiz is counted' );
pcle_eq( $quiz_report['required_outstanding'], 0, 'nothing outstanding once the required quiz is passed' );

// Sitting a passed quiz again must not count twice.
pcle_record_quiz_attempt( $gate_quiz, array( 'q' => 'right' ), $student );
pcle_eq(
	pcle_get_program_report( $prog_a )[ $student ]['quizzes_passed'],
	$quiz_report['quizzes_passed'],
	'passing the same quiz twice still counts as one'
);

// A second required quiz, unpassed, is what the outstanding count is for.
$second_gate = pcle_make_post( 'pcle_quiz', 'TEST Second Gate', array( '_pcle_module_id' => $module2 ) );
$created_posts[] = $second_gate;
pcle_set_quiz_questions( $second_gate, array(
	array( 'key' => 'q', 'prompt' => 'Second gate', 'type' => 'single',
		'choices' => array( array( 'key' => 'a', 'text' => 'A', 'correct' => true ), array( 'key' => 'b', 'text' => 'B' ) ) ),
) );
update_post_meta( $second_gate, PCLE_QUIZ_GATES_META, 1 );

$with_outstanding = pcle_get_program_report( $prog_a )[ $student ];
pcle_eq( $with_outstanding['required_outstanding'], 1, 'an unpassed required quiz is reported as outstanding' );
pcle_eq(
	in_array( $second_gate, pcle_get_program_required_quiz_ids( $prog_a ), true ),
	true,
	'and it is listed among the programme required quizzes'
);

// An optional quiz left unsat is a different fact, and must not be counted.
$optional = pcle_make_post( 'pcle_quiz', 'TEST Optional', array( '_pcle_module_id' => $module2 ) );
$created_posts[] = $optional;
pcle_set_quiz_questions( $optional, array(
	array( 'key' => 'q', 'prompt' => 'Optional', 'type' => 'single',
		'choices' => array( array( 'key' => 'a', 'text' => 'A', 'correct' => true ), array( 'key' => 'b', 'text' => 'B' ) ) ),
) );
$with_optional = pcle_get_program_report( $prog_a )[ $student ];
pcle_eq( $with_optional['required_outstanding'], 1, 'an unsat optional quiz is not outstanding' );
pcle_eq( $with_optional['quizzes'], count( $program_quizzes ) + 2, 'but it does count towards the total' );

// A draft required quiz must not be chased, the same way it does not gate.
wp_update_post( array( 'ID' => $second_gate, 'post_status' => 'draft' ) );
pcle_eq(
	pcle_get_program_report( $prog_a )[ $student ]['required_outstanding'],
	0,
	'an unpublished required quiz is not reported as outstanding'
);
wp_update_post( array( 'ID' => $second_gate, 'post_status' => 'publish' ) );

// The CSV carries the same three facts, in the same order as the header.
$csv    = pcle_get_program_report_csv( $prog_a );
$header = $csv[0];
foreach ( array( 'Quizzes passed', 'Quizzes total', 'Required quizzes outstanding' ) as $column ) {
	pcle_ok( in_array( $column, $header, true ), "the CSV header carries \"{$column}\"" );
}
/*
 * Find the participant's own line rather than trusting the first data row:
 * rows are ordered by display name, so $csv[1] is whoever sorts first and the
 * assertion would pass for the wrong reason.
 */
$passed_col = array_search( 'Quizzes passed', $header, true );
$name_col   = array_search( 'Participant', $header, true );
$student_line = null;
foreach ( array_slice( $csv, 1 ) as $line ) {
	if ( get_userdata( $student )->display_name === $line[ $name_col ] ) {
		$student_line = $line;
	}
}
pcle_ok( null !== $student_line, 'the CSV has a line for the participant' );
pcle_eq(
	$student_line[ $passed_col ],
	(string) pcle_get_program_report( $prog_a )[ $student ]['quizzes_passed'],
	'and its quizzes-passed cell matches the report row'
);
pcle_eq( count( $csv[0] ), count( $csv[1] ), 'every CSV row is as wide as its header' );

/* ------------------------------------------------------------------ */
/* The report over REST                                               */
/* ------------------------------------------------------------------ */
pcle_section( '# Reports over REST' );

$report_route = "/platform-cle/v1/reports/programs/{$prog_a}";

/*
 * A cohort report is a list of other people's records, so it takes the
 * staff gate rather than the participant one. view_cle_content only says
 * somebody is enrolled somewhere.
 */
pcle_eq( pcle_rest_status( 0, $report_route ), 401, 'anonymous cannot read a report' );
pcle_eq( pcle_rest_status( $student, $report_route ), 403, 'a participant cannot read a report' );
pcle_eq( pcle_rest_status( $outsider, $report_route ), 403, 'nor can someone enrolled in nothing' );
pcle_eq( pcle_rest_status( $admin, $report_route ), 200, 'staff can' );
pcle_eq( pcle_rest_status( $admin, "/platform-cle/v1/reports/programs/{$module}" ), 404, 'a non-programme id is a 404' );

wp_set_current_user( $admin );
$report_body = rest_do_request( new WP_REST_Request( 'GET', $report_route ) )->get_data();

pcle_ok( isset( $report_body['participants'] ), 'the response carries participants' );
pcle_eq( $report_body['program']['id'], $prog_a, 'and names the programme' );

$rest_row = null;
foreach ( $report_body['participants'] as $participant ) {
	if ( (int) $participant['id'] === $student ) {
		$rest_row = $participant;
	}
}
pcle_ok( null !== $rest_row, 'the participant appears in the response' );

// The shaped row has to agree with the report it came from, or the API and
// the admin table will drift apart without anything failing.
$direct = pcle_get_program_report( $prog_a )[ $student ];
foreach ( array( 'completed', 'total', 'attended', 'sessions', 'quizzes_passed', 'quizzes', 'required_outstanding' ) as $field ) {
	pcle_eq( $rest_row[ $field ], (int) $direct[ $field ], "the API row agrees on {$field}" );
}

// The CSV route hands back rows, not a rendered file, so the columns are
// decided in one place only.
$csv_body = rest_do_request( new WP_REST_Request( 'GET', $report_route . '/csv' ) )->get_data();
pcle_eq( $csv_body['rows'][0], pcle_get_program_report_csv( $prog_a )[0], 'the CSV route returns the same header' );
pcle_ok( '' !== $csv_body['filename'], 'and suggests a filename' );
pcle_eq( pcle_rest_status( $student, $report_route . '/csv' ), 403, 'a participant cannot download the CSV either' );

wp_set_current_user( 0 );



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
