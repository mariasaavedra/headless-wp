<?php
/**
 * Cleanup when UNINSTALLING the plugin (not when deactivating).
 *
 * WordPress loads this file automatically when the plugin is deleted from the
 * admin. Here we remove the custom roles and capabilities.
 *
 * @package Habeas_CLE
 */

// Safety: must only run in WP's uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/post-types.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/roles.php';

hcle_remove_roles();

// Note: we do NOT delete the curriculum posts or the progress user meta here,
// to avoid destroying data by accident. If desired, it would be done explicitly.
