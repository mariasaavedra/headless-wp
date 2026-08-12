<?php
/**
 * Plugin Name: Platform CLE
 * Plugin URI:  https://github.com/sharma-crawford/platform-cle
 * Description: Immigration Habeas Corpus CLE Training Platform
 * Version:     0.1.0
 * Author:      Sharma-Crawford Attorneys at Law
 * License:     GPL-2.0+
 * Text Domain: platform-cle
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PLATFORM_CLE_VERSION', '0.1.0' );
define( 'PLATFORM_CLE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLATFORM_CLE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * --------------------------------------------------------------------------
 * Plugin module loading
 * --------------------------------------------------------------------------
 * Each file has a single responsibility:
 *   - post-types.php     : registers the Custom Post Types (the curriculum).
 *   - roles.php          : defines roles and capabilities (who can do what).
 *   - access-control.php : protects the content (who can SEE what).
 */
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/schema.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/post-types.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/roles.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/access-control.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/relationships.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/enrollment.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/progress.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/blocks.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/rest.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/event-meta.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/protected-files.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/emails.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/health.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/credits.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/attendance.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/certificates.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/reports.php';
require_once PLATFORM_CLE_PLUGIN_DIR . 'includes/demo-data.php';

/*
 * --------------------------------------------------------------------------
 * Activation / Deactivation
 * --------------------------------------------------------------------------
 * On activation: create the roles, register the CPTs, and flush the rewrite
 * rules so the CPT URLs work immediately. `flush_rewrite_rules()` is expensive,
 * which is why it ONLY runs on activation, never on every page load.
 */
function pcle_activate() {
	pcle_maybe_upgrade_schema();    // defined in includes/schema.php
	pcle_register_roles();          // defined in includes/roles.php
	pcle_register_post_types();     // defined in includes/post-types.php
	pcle_ensure_protected_dir();    // defined in includes/protected-files.php
	pcle_ensure_front_door_page();  // defined in includes/blocks.php
	pcle_schedule_reminder_cron();  // defined in includes/emails.php
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pcle_activate' );

/*
 * On deactivation we flush the rewrite rules. We do NOT remove the roles here:
 * if the user only deactivates temporarily, we don't want to lose the
 * participants' role assignments. That happens in uninstall.php.
 */
function pcle_deactivate() {
	pcle_clear_reminder_cron(); // defined in includes/emails.php
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pcle_deactivate' );
