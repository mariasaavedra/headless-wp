<?php
/**
 * Habeas CLE — dependency-free smoke tests.
 *
 * Boots WordPress, creates isolated fixtures, asserts the critical paths
 * (access control, enrollment, progress, protected files, REST guard), then
 * cleans everything up. No PHPUnit / composer required.
 *
 * Exit code is non-zero on failure, so it can gate CI later.
 *
 * Usage (from the site root, with Local's socket):
 *   php -d mysqli.default_socket=<sock> wp-content/plugins/habeas-cle/tests/smoke-test.php
 *
 * @package Habeas_CLE
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

$GLOBALS['hcle_t'] = array(
	'pass' => 0,
	'fail' => 0,
);

function hcle_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['hcle_t']['pass']++;
		return;
	}
	$GLOBALS['hcle_t']['fail']++;
	echo "  \033[31mFAIL\033[0m  {$msg}\n";
}

function hcle_eq( $actual, $expected, $msg ) {
	hcle_ok(
		$actual === $expected,
		$msg . ' (got ' . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . ')'
	);
}

function hcle_section( $name ) {
	echo "\n{$name}\n";
}

// Capture outgoing mail instead of sending it (no MTA on dev; keeps tests quiet).
$GLOBALS['hcle_mail'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['hcle_mail'][] = $atts;
		return true;
	},
	10,
	2
);

/* ------------------------------------------------------------------ */
/* Fixtures                                                            */
/* ------------------------------------------------------------------ */

function hcle_make_post( $type, $title, $meta = array() ) {
	$id = wp_insert_post(
		array(
			'post_type'   => $type,
			'post_title'  => $title,
			'post_status' => 'publish',
		)
	);
	update_post_meta( $id, '_hcle_test', 1 );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return (int) $id;
}

$created_posts   = array();
$created_users   = array();
$created_files   = array();

// Program A (student enrolled) → Week → Module; plus a Case Update.
$prog_a  = hcle_make_post( 'hcle_program', 'TEST Program A' );
$prog_b  = hcle_make_post( 'hcle_program', 'TEST Program B' );
$week    = hcle_make_post( 'hcle_week', 'TEST Week 1', array( '_hcle_program_id' => $prog_a ) );
$module  = hcle_make_post( 'hcle_module', 'TEST Module 1', array( '_hcle_week_id' => $week ) );
$module2 = hcle_make_post( 'hcle_module', 'TEST Module 2', array( '_hcle_week_id' => $week ) );
$case    = hcle_make_post( 'hcle_case_update', 'TEST Case Update' );
$created_posts = array( $prog_a, $prog_b, $week, $module, $module2, $case );

// A student enrolled in Program A only, and an admin.
$student = wp_insert_user(
	array(
		'user_login' => 'hcle_test_student_' . wp_generate_password( 5, false ),
		'user_email' => 'test+' . wp_generate_password( 6, false ) . '@example.test',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'hcle_student',
	)
);
$created_users[] = $student;
hcle_enroll_user( $prog_a, $student );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = $admins ? (int) $admins[0] : 0;

echo "Habeas CLE smoke tests\n======================";

/* ------------------------------------------------------------------ */
/* 1) Access control (per program)                                    */
/* ------------------------------------------------------------------ */
hcle_section( '# Access control' );
hcle_eq( hcle_can_access_post( $prog_a, 0 ), false, 'anonymous cannot access a program' );
hcle_eq( hcle_can_access_post( $prog_a, $admin ), true, 'staff can access any program' );
hcle_eq( hcle_can_access_post( $prog_a, $student ), true, 'enrolled student can access their program' );
hcle_eq( hcle_can_access_post( $module, $student ), true, 'enrolled student can access a module in their program' );
hcle_eq( hcle_can_access_post( $prog_b, $student ), false, 'student cannot access a program they are NOT enrolled in' );
hcle_eq( hcle_can_access_post( $case, $student ), true, 'case updates are visible to any participant' );

/* ------------------------------------------------------------------ */
/* 2) Enrollment                                                      */
/* ------------------------------------------------------------------ */
hcle_section( '# Enrollment' );
hcle_eq( hcle_is_enrolled( $prog_a, $student ), true, 'is_enrolled true after enroll' );
hcle_eq( hcle_is_enrolled( $prog_b, $student ), false, 'is_enrolled false for other program' );
hcle_eq( in_array( $prog_a, hcle_get_enrolled_programs( $student ), true ), true, 'enrolled programs list contains program A' );
hcle_eq( hcle_enroll_user( $module, $student ), false, 'cannot enroll into a non-program id' );
hcle_unenroll_user( $prog_a, $student );
hcle_eq( hcle_is_enrolled( $prog_a, $student ), false, 'unenroll removes enrollment' );
hcle_enroll_user( $prog_a, $student ); // restore for later tests
hcle_eq( hcle_user_is_staff( $admin ), true, 'admin is staff' );
hcle_eq( hcle_user_is_staff( $student ), false, 'student is not staff' );

/* ------------------------------------------------------------------ */
/* 3) Progress                                                        */
/* ------------------------------------------------------------------ */
hcle_section( '# Progress' );
hcle_eq( hcle_mark_module_complete( $prog_a, $student ), false, 'cannot mark a non-module complete' );
hcle_mark_module_complete( $module, $student );
hcle_eq( hcle_is_module_complete( $module, $student ), true, 'module marked complete' );
$wp = hcle_get_week_progress( $week, $student );
hcle_eq( $wp['total'], 2, 'week has 2 modules' );
hcle_eq( $wp['completed'], 1, 'week shows 1 completed' );
hcle_eq( $wp['percent'], 50, 'week progress is 50%' );
$pp = hcle_get_program_progress( $prog_a, $student );
hcle_eq( $pp['completed'], 1, 'program shows 1 completed' );
hcle_eq( $pp['total'], 2, 'program total is 2' );
hcle_mark_module_complete( $module2, $student );
hcle_eq( hcle_get_week_progress( $week, $student )['percent'], 100, 'week is 100% after both modules' );
hcle_unmark_module_complete( $module, $student );
hcle_eq( hcle_is_module_complete( $module, $student ), false, 'unmark removes completion' );

/* ------------------------------------------------------------------ */
/* 4) Relationships                                                   */
/* ------------------------------------------------------------------ */
hcle_section( '# Relationships' );
hcle_eq( hcle_get_program_for_post( $module ), $prog_a, 'get_program_for_post walks module → program' );
hcle_eq( hcle_get_program_for_post( $week ), $prog_a, 'get_program_for_post walks week → program' );
hcle_eq( hcle_get_program_for_post( $case ), 0, 'case update has no program' );
hcle_eq( count( hcle_get_modules( $week ) ), 2, 'week has 2 child modules' );
hcle_eq( count( hcle_get_weeks( $prog_a ) ), 1, 'program A has 1 week' );

/* ------------------------------------------------------------------ */
/* 5) Protected files                                                 */
/* ------------------------------------------------------------------ */
hcle_section( '# Protected files' );
$up = wp_get_upload_dir();

// Protected attachment.
$pdir = $up['basedir'] . '/hcle-protected/testfix';
wp_mkdir_p( $pdir );
$pfile = $pdir . '/brief.txt';
file_put_contents( $pfile, 'secret' );
$created_files[] = $pfile;
$patt = wp_insert_attachment(
	array( 'post_mime_type' => 'text/plain', 'post_status' => 'inherit', 'post_parent' => $module ),
	$pfile,
	$module
);
update_post_meta( $patt, '_wp_attached_file', 'hcle-protected/testfix/brief.txt' );
$created_posts[] = $patt;

// Public attachment.
$ufile = $up['basedir'] . '/hcle-test-public.txt';
file_put_contents( $ufile, 'public' );
$created_files[] = $ufile;
$uatt = wp_insert_attachment(
	array( 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ),
	$ufile
);
update_post_meta( $uatt, '_wp_attached_file', 'hcle-test-public.txt' );
$created_posts[] = $uatt;

hcle_eq( hcle_is_protected_attachment( $patt ), true, 'file in hcle-protected/ is detected as protected' );
hcle_eq( hcle_is_protected_attachment( $uatt ), false, 'file in uploads root is NOT protected' );
hcle_ok( false !== strpos( hcle_protected_file_url( $patt ), 'hcle_download=' . $patt ), 'protected file URL uses the guarded endpoint' );
hcle_ok( false !== strpos( wp_get_attachment_url( $patt ), 'hcle_download=' ), 'wp_get_attachment_url is rewritten for protected files' );
hcle_eq( strpos( wp_get_attachment_url( $uatt ), 'hcle_download=' ), false, 'wp_get_attachment_url is untouched for public files' );

/* ------------------------------------------------------------------ */
/* 6) REST guard (per program)                                        */
/* ------------------------------------------------------------------ */
hcle_section( '# REST guard' );
function hcle_rest_status( $uid, $route ) {
	wp_set_current_user( $uid );
	return rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
}
hcle_eq( hcle_rest_status( 0, "/wp/v2/hcle_program/{$prog_a}" ), 401, 'anon REST read → 401' );
hcle_eq( hcle_rest_status( $student, "/wp/v2/hcle_program/{$prog_a}" ), 200, 'enrolled student REST read → 200' );
hcle_eq( hcle_rest_status( $student, "/wp/v2/hcle_program/{$prog_b}" ), 403, 'non-enrolled student REST read → 403' );
hcle_eq( hcle_rest_status( $admin, "/wp/v2/hcle_program/{$prog_b}" ), 200, 'staff REST read → 200' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
/* 7) Emails                                                          */
/* ------------------------------------------------------------------ */
hcle_section( '# Emails' );
$before = count( $GLOBALS['hcle_mail'] );
hcle_enroll_user( $prog_b, $student ); // student not yet in program B
hcle_eq( count( $GLOBALS['hcle_mail'] ) - $before, 1, 'new enrollment fires one confirmation email' );
$last_mail = end( $GLOBALS['hcle_mail'] );
hcle_ok( false !== strpos( $last_mail['subject'], 'TEST Program B' ), 'enrollment email subject names the program' );

$before = count( $GLOBALS['hcle_mail'] );
hcle_enroll_user( $prog_b, $student ); // already enrolled
hcle_eq( count( $GLOBALS['hcle_mail'] ) - $before, 0, 're-enroll does not resend' );

$rem_event       = hcle_make_post( 'hcle_event', 'TEST Session', array( '_hcle_week_id' => $week ) );
$created_posts[] = $rem_event;
$rdt = new DateTime( 'now', wp_timezone() );
$rdt->modify( '+2 hours' );
update_post_meta( $rem_event, '_hcle_event_datetime', $rdt->format( 'Y-m-d H:i:s' ) );

$before = count( $GLOBALS['hcle_mail'] );
hcle_send_session_reminders();
hcle_eq( count( $GLOBALS['hcle_mail'] ) - $before, 1, 'session reminder emails the enrolled student' );

$before = count( $GLOBALS['hcle_mail'] );
hcle_send_session_reminders();
hcle_eq( count( $GLOBALS['hcle_mail'] ) - $before, 0, 'reminder is de-duplicated on re-run' );

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
$pass = $GLOBALS['hcle_t']['pass'];
$fail = $GLOBALS['hcle_t']['fail'];
echo "\n======================\n";
echo "Result: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
