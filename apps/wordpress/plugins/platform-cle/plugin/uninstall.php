<?php
/**
 * Cleanup when UNINSTALLING the plugin (not when deactivating).
 *
 * WordPress loads this file automatically when the plugin is deleted from the
 * admin. Here we remove the custom roles and capabilities.
 *
 * @package Platform_CLE
 */

// Safety: must only run in WP's uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/post-types.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/roles.php';

pcle_remove_roles();

/*
 * Note: we deliberately do NOT delete participant data here, to avoid
 * destroying it by accident. That now means three things, not one:
 *
 *   - the curriculum posts,
 *   - the `pcle_enrollments` and `pcle_progress` tables,
 *   - the legacy `_pcle_enrolled_programs` / `_pcle_completed_modules` user
 *     meta, which the plugin no longer reads but which is still the only
 *     record of anything predating the move to tables.
 *
 * Enrollment and completion records are the evidence behind a CLE credit
 * claim, so dropping them on an uninstall — which can be a mis-click — is not
 * a decision this file should make. Removing them stays explicit and manual.
 */
