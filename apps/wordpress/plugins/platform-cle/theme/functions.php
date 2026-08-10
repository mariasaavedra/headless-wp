<?php
/**
 * Habeas CLE theme (child of Twenty Twenty-Five).
 *
 * Presentation only. The logic lives in the habeas-cle plugin.
 *
 * @package Habeas_CLE_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensures the child theme's style.css is loaded on the frontend.
 */
function hcle_theme_enqueue_styles() {
	wp_enqueue_style(
		'habeas-cle-theme',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'hcle_theme_enqueue_styles' );
