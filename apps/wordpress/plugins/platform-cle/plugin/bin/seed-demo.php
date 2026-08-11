<?php
/**
 * CLI entry point for the sample data seeder.
 *
 * The actual seeding logic lives in includes/demo-data.php (pcle_seed_demo_data()),
 * shared with the "Seed Demo Data" button in the admin dashboard so it also works
 * on hosts without shell/WP-CLI access.
 *
 * Usage (from the site root, with Local's socket):
 *   php -d mysqli.default_socket=<sock> wp-content/plugins/platform-cle/bin/seed-demo.php
 *
 * @package Platform_CLE
 */

// CLI only.
if ( 'cli' !== php_sapi_name() ) {
	exit( 'Run from CLI only.' );
}

// Locate and load WordPress by walking up until we find wp-load.php.
$dir = __DIR__;
while ( '/' !== $dir && ! file_exists( $dir . '/wp-load.php' ) ) {
	$dir = dirname( $dir );
}
if ( ! file_exists( $dir . '/wp-load.php' ) ) {
	exit( "wp-load.php not found\n" );
}
require $dir . '/wp-load.php';

$counts = pcle_seed_demo_data();

echo 'Cleanup: ' . $counts['removed'] . " previous demos removed.\n\n";
echo "\n=== Summary ===\n";
echo "Program:        {$counts['program']}\n";
echo "Weeks:          {$counts['week']}\n";
echo "Modules:        {$counts['module']}\n";
echo "Scenarios:      {$counts['scenario']}\n";
echo "Templates:      {$counts['template']}\n";
echo "Events:         {$counts['event']}\n";
echo "Case Updates:   {$counts['case_update']}\n";

if ( ! empty( $counts['users'] ) ) {
	echo 'Demo accounts:  ' . implode( ', ', $counts['users'] ) . "\n";
	echo "                (password from PCLE_DEMO_USER_PASSWORD)\n";
} else {
	echo "Demo accounts:  skipped (PCLE_DEMO_USER_PASSWORD not set)\n";
}

echo "\nDone. Sample data seeded.\n";
