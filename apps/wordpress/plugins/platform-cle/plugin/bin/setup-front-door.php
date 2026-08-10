<?php
/**
 * Creates the CLE "front door": a Page with the my-programs block and a link to
 * it in the theme's navigation menu.
 *
 * Idempotent: the Page is identified by the `_hcle_front_door` meta. The
 * navigation link is not duplicated if it already exists.
 *
 * Usage: php -d mysqli.default_socket=<sock> wp-content/plugins/habeas-cle/bin/setup-front-door.php
 *
 * @package Habeas_CLE
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Run from CLI only.' );
}

$dir = __DIR__;
while ( '/' !== $dir && ! file_exists( $dir . '/wp-load.php' ) ) {
	$dir = dirname( $dir );
}
require $dir . '/wp-load.php';

// ---------------------------------------------------------------------------
// 1) "My Training" page.
// ---------------------------------------------------------------------------
$existing = get_posts(
	array(
		'post_type'   => 'page',
		'post_status' => 'any',
		'numberposts' => 1,
		'meta_key'    => '_hcle_front_door',
		'meta_value'  => 1,
	)
);

$page_content = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">My Training</h1><!-- /wp:heading -->'
	. '<!-- wp:habeas-cle/my-programs /-->';

if ( $existing ) {
	$page_id = $existing[0]->ID;
	wp_update_post(
		array(
			'ID'           => $page_id,
			'post_status'  => 'publish',
			'post_content' => $page_content,
		)
	);
	echo "'My Training' page already existed (#{$page_id}), updated.\n";
} else {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'My Training',
			'post_name'    => 'my-training',
			'post_status'  => 'publish',
			'post_content' => $page_content,
		)
	);
	update_post_meta( $page_id, '_hcle_front_door', 1 );
	echo "'My Training' page created (#{$page_id}).\n";
}

$page_url = get_permalink( $page_id );
echo "  URL: {$page_url}\n";

// ---------------------------------------------------------------------------
// 2) Navigation menu link (wp_navigation of the block theme).
// ---------------------------------------------------------------------------
$navs = get_posts(
	array(
		'post_type'   => 'wp_navigation',
		'post_status' => 'publish',
		'numberposts' => -1,
	)
);

if ( ! $navs ) {
	echo "NOTICE: there are no navigation menus (wp_navigation). Add 'My Training' to the menu from the Site Editor (Appearance → Editor → Navigation).\n";
} else {
	$link_block = sprintf(
		'<!-- wp:navigation-link {"label":"My Training","type":"page","kind":"post-type","id":%d,"url":%s} /-->',
		$page_id,
		wp_json_encode( $page_url )
	);

	foreach ( $navs as $nav ) {
		// Idempotency: don't duplicate if it already references this page.
		if ( false !== strpos( $nav->post_content, '"id":' . $page_id . ',' )
			|| false !== strpos( $nav->post_content, '"id":' . $page_id . '}' )
			|| false !== strpos( $nav->post_content, 'My Training' )
		) {
			echo "  Menu #{$nav->ID}: already contains 'My Training', no changes.\n";
			continue;
		}

		wp_update_post(
			array(
				'ID'           => $nav->ID,
				'post_content' => $nav->post_content . "\n" . $link_block,
			)
		);
		echo "  Menu #{$nav->ID}: 'My Training' link added.\n";
	}
}

echo "\nDone.\n";
