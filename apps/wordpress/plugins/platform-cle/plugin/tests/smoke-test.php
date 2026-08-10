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

function pcle_make_post( $type, $title, $meta = array() ) {
	$id = wp_insert_post(
		array(
			'post_type'   => $type,
			'post_title'  => $title,
			'post_status' => 'publish',
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
$created_posts = array( $prog_a, $prog_b, $week, $module, $module2, $case );

// A student enrolled in Program A only, and an admin.
$student = wp_insert_user(
	array(
		'user_login' => 'pcle_test_student_' . wp_generate_password( 5, false ),
		'user_email' => 'test+' . wp_generate_password( 6, false ) . '@example.test',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'pcle_student',
	)
);
$created_users[] = $student;
pcle_enroll_user( $prog_a, $student );

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
/* 8) Emails                                                          */
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

$before = count( $GLOBALS['pcle_mail'] );
pcle_send_session_reminders();
pcle_eq( count( $GLOBALS['pcle_mail'] ) - $before, 1, 'session reminder emails the enrolled student' );

$before = count( $GLOBALS['pcle_mail'] );
pcle_send_session_reminders();
pcle_eq( count( $GLOBALS['pcle_mail'] ) - $before, 0, 'reminder is de-duplicated on re-run' );

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
