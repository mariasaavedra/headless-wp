<?php
/**
 * Habeas CLE dynamic blocks.
 *
 * These blocks are server-rendered (render_callback in PHP, no build step).
 * The PLUGIN provides the data/logic; the THEME only places them in its
 * templates. This keeps the separation: logic here, presentation in the child
 * theme.
 *
 * Blocks:
 *   - habeas-cle/curriculum-children : lists the current post's children
 *     (Program→Weeks, Week→Modules+Events, Module→Scenarios+Templates).
 *   - habeas-cle/progress-bar        : progress bar for the current Program/Week.
 *   - habeas-cle/complete-button     : module "mark as complete" button.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the dynamic blocks.
 */
function hcle_register_blocks() {
	register_block_type(
		'habeas-cle/curriculum-children',
		array(
			'api_version'     => 2,
			'render_callback' => 'hcle_block_curriculum_children',
		)
	);

	register_block_type(
		'habeas-cle/progress-bar',
		array(
			'api_version'     => 2,
			'render_callback' => 'hcle_block_progress_bar',
		)
	);

	register_block_type(
		'habeas-cle/complete-button',
		array(
			'api_version'     => 2,
			'render_callback' => 'hcle_block_complete_button',
		)
	);

	register_block_type(
		'habeas-cle/event-datetime',
		array(
			'api_version'     => 2,
			'render_callback' => 'hcle_block_event_datetime',
		)
	);

	register_block_type(
		'habeas-cle/breadcrumbs',
		array(
			'api_version'     => 2,
			'render_callback' => 'hcle_block_breadcrumbs',
		)
	);
}

/**
 * URL of the "front door" page (My Training), or '' if it doesn't exist.
 *
 * @return string
 */
function hcle_get_front_door_url() {
	$pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_hcle_front_door',
			'meta_value'  => 1,
		)
	);
	return $pages ? get_permalink( $pages[0] ) : '';
}

/**
 * Ensures the "My Training" front-door page exists. Idempotent.
 *
 * Called on plugin activation so a fresh install has the dashboard page without
 * needing the CLI setup script. (Adding it to the nav menu stays a manual step,
 * since the theme's menu may not exist yet at activation.)
 *
 * @return int Page ID.
 */
function hcle_ensure_front_door_page() {
	$existing = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_hcle_front_door',
			'meta_value'  => 1,
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	$content = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">My Training</h1><!-- /wp:heading -->'
		. '<!-- wp:habeas-cle/my-programs /-->';

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'My Training',
			'post_name'    => 'my-training',
			'post_status'  => 'publish',
			'post_content' => $content,
		)
	);

	if ( $page_id && ! is_wp_error( $page_id ) ) {
		update_post_meta( $page_id, '_hcle_front_door', 1 );
		return (int) $page_id;
	}
	return 0;
}

/**
 * Render: breadcrumbs walking up the hierarchy to the current post.
 *
 * E.g.: My Training › Program › Week › Module (the last one without a link).
 *
 * @return string
 */
function hcle_block_breadcrumbs() {
	$id   = get_the_ID();
	$type = get_post_type( $id );

	$hierarchy = array( 'hcle_program', 'hcle_week', 'hcle_module', 'hcle_scenario', 'hcle_template', 'hcle_event' );
	if ( ! $id || ! in_array( $type, $hierarchy, true ) ) {
		return '';
	}

	// Chain of ancestors (the current one ends up last).
	$chain  = array();
	$cursor = $id;
	$guard  = 0;
	while ( $cursor && $guard < 10 ) {
		$chain[] = (int) $cursor;
		$cursor  = hcle_get_parent_id( $cursor );
		$guard++;
	}
	$chain = array_reverse( $chain );

	// Build the items: "My Training" root + each level.
	$items = array();
	$front = hcle_get_front_door_url();
	if ( $front ) {
		$items[] = array(
			'url'     => $front,
			'label'   => __( 'My Training', 'habeas-cle' ),
			'current' => false,
		);
	}

	$last = count( $chain ) - 1;
	foreach ( $chain as $i => $pid ) {
		$items[] = array(
			'url'     => get_permalink( $pid ),
			'label'   => get_the_title( $pid ),
			'current' => ( $i === $last ),
		);
	}

	// Render.
	$lis = '';
	foreach ( $items as $item ) {
		if ( $item['current'] ) {
			$lis .= sprintf(
				'<li class="hcle-breadcrumbs__item" aria-current="page"><span>%s</span></li>',
				esc_html( $item['label'] )
			);
		} else {
			$lis .= sprintf(
				'<li class="hcle-breadcrumbs__item"><a href="%s">%s</a></li>',
				esc_url( $item['url'] ),
				esc_html( $item['label'] )
			);
		}
	}

	return sprintf(
		'<nav class="hcle-breadcrumbs" aria-label="%s"><ol class="hcle-breadcrumbs__list">%s</ol></nav>',
		esc_attr__( 'Breadcrumb', 'habeas-cle' ),
		$lis
	);
}
add_action( 'init', 'hcle_register_blocks' );

/**
 * Render: list of the current post's children by type.
 *
 * @return string HTML.
 */
function hcle_block_curriculum_children() {
	$id   = get_the_ID();
	$type = get_post_type( $id );
	if ( ! $id ) {
		return '';
	}

	$sections = array();

	switch ( $type ) {
		case 'hcle_program':
			$sections[] = hcle_render_children_section(
				__( 'Weeks', 'habeas-cle' ),
				hcle_get_weeks( $id ),
				false,
				false,
				true // show each week's progress bar
			);
			break;

		case 'hcle_week':
			$sections[] = hcle_render_children_section(
				__( 'Modules', 'habeas-cle' ),
				hcle_get_modules( $id ),
				true // mark completed modules
			);
			$sections[] = hcle_render_children_section(
				__( 'Live Sessions', 'habeas-cle' ),
				hcle_get_events( $id ),
				false,
				true // show each session's date/time
			);
			break;

		case 'hcle_module':
			$sections[] = hcle_render_children_section(
				__( 'Practice Scenarios', 'habeas-cle' ),
				hcle_get_scenarios( $id )
			);
			$sections[] = hcle_render_children_section(
				__( 'Templates', 'habeas-cle' ),
				hcle_get_templates( $id )
			);
			break;
	}

	$html = implode( '', array_filter( $sections ) );
	return $html ? '<div class="hcle-curriculum">' . $html . '</div>' : '';
}

/**
 * Renders a "title + list of links" section.
 *
 * @param string    $title              Section heading.
 * @param WP_Post[] $items              Child posts.
 * @param bool      $show_complete      If true, shows ✓ on completed items (modules).
 * @param bool      $show_datetime      If true, shows the event date/time.
 * @param bool      $show_week_progress If true, adds each week's progress bar.
 * @return string
 */
function hcle_render_children_section( $title, $items, $show_complete = false, $show_datetime = false, $show_week_progress = false ) {
	if ( empty( $items ) ) {
		return '';
	}

	$rows = '';
	foreach ( $items as $item ) {
		$mark = '';
		if ( $show_complete && hcle_is_module_complete( $item->ID ) ) {
			$mark = '<span class="hcle-curriculum__check" aria-hidden="true">✓</span> ';
		}

		$meta = '';
		if ( $show_datetime ) {
			$when = hcle_format_event_datetime( $item->ID );
			if ( '' !== $when ) {
				$meta = '<span class="hcle-curriculum__when">' . esc_html( $when ) . '</span>';
			}
		}

		if ( $show_week_progress ) {
			$meta .= hcle_render_progress_bar( hcle_get_week_progress( $item->ID ) );
		}

		$rows .= sprintf(
			'<li class="hcle-curriculum__item">%s<a href="%s">%s</a>%s</li>',
			$mark,
			esc_url( get_permalink( $item->ID ) ),
			esc_html( get_the_title( $item->ID ) ),
			$meta
		);
	}

	return sprintf(
		'<section class="hcle-curriculum__section"><h2 class="hcle-curriculum__title">%s</h2><ul class="hcle-curriculum__list">%s</ul></section>',
		esc_html( $title ),
		$rows
	);
}

/**
 * Render: progress bar for the current Program or Week.
 *
 * Only shown to authenticated users with access to the program.
 *
 * @return string
 */
function hcle_block_progress_bar() {
	if ( ! is_user_logged_in() || ! current_user_can( 'view_cle_content' ) ) {
		return '';
	}

	$id   = get_the_ID();
	$type = get_post_type( $id );

	if ( 'hcle_program' === $type ) {
		$progress = hcle_get_program_progress( $id );
		$label    = __( 'Program progress:', 'habeas-cle' );
	} elseif ( 'hcle_week' === $type ) {
		$progress = hcle_get_week_progress( $id );
		$label    = __( 'Week progress:', 'habeas-cle' );
	} else {
		return '';
	}

	return hcle_render_progress_bar( $progress, $label );
}

/**
 * Render: "mark as complete" button for the current module.
 *
 * @return string
 */
function hcle_block_complete_button() {
	$id = get_the_ID();
	if ( 'hcle_module' !== get_post_type( $id ) ) {
		return '';
	}
	return hcle_render_complete_button( $id );
}

/**
 * Render: the session date/time on the Schedule Event page.
 *
 * @return string
 */
function hcle_block_event_datetime() {
	$id = get_the_ID();
	if ( 'hcle_event' !== get_post_type( $id ) ) {
		return '';
	}

	$when = hcle_format_event_datetime( $id );
	if ( '' === $when ) {
		return '';
	}

	return sprintf(
		'<p class="hcle-event-datetime"><span class="hcle-event-datetime__icon" aria-hidden="true">🗓️</span> %s</p>',
		esc_html( $when )
	);
}

/* =========================================================================
 * "FRONT DOOR": the user's program listing
 * ========================================================================= */

/**
 * Registers the `my-programs` block + shortcode (the front door).
 */
function hcle_register_my_programs() {
	register_block_type(
		'habeas-cle/my-programs',
		array(
			'api_version'     => 2,
			'render_callback' => 'hcle_render_my_programs',
		)
	);
	add_shortcode( 'hcle_my_programs', 'hcle_render_my_programs' );
}
add_action( 'init', 'hcle_register_my_programs' );

/**
 * Renders the list of programs accessible to the current user.
 *
 *   - Anonymous            → invitation to log in.
 *   - Logged in, no access → "not enrolled" notice.
 *   - Logged in with access → program cards with a progress bar.
 *
 * @return string HTML.
 */
function hcle_render_my_programs() {
	// Anonymous: invitation to log in (returns to this same page afterward).
	if ( ! is_user_logged_in() ) {
		$here  = get_permalink() ? get_permalink() : home_url( '/' );
		$login = wp_login_url( $here );
		return sprintf(
			'<div class="hcle-myprograms hcle-myprograms--guest"><p>%s</p><p><a class="hcle-myprograms__cta" href="%s">%s</a></p></div>',
			esc_html__( 'Please log in to access your training program.', 'habeas-cle' ),
			esc_url( $login ),
			esc_html__( 'Log in', 'habeas-cle' )
		);
	}

	// Logged in but without the program capability.
	if ( ! current_user_can( 'view_cle_content' ) ) {
		return sprintf(
			'<div class="hcle-myprograms hcle-myprograms--guest"><p>%s</p></div>',
			esc_html__( 'Your account is not enrolled in the training program. Please contact an administrator.', 'habeas-cle' )
		);
	}

	// Notice if they arrived here after trying to view a program without enrollment.
	$notice = '';
	if ( isset( $_GET['hcle_notice'] ) && 'not_enrolled' === $_GET['hcle_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = '<div class="hcle-myprograms__notice">'
			. esc_html__( 'You are not enrolled in that program. Below are the programs available to you.', 'habeas-cle' )
			. '</div>';
	}

	// Teaching staff see all programs; a student sees only their own.
	if ( hcle_user_is_staff() ) {
		$programs = get_posts(
			array(
				'post_type'   => 'hcle_program',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
	} else {
		$enrolled = hcle_get_enrolled_programs();
		$programs = $enrolled
			? get_posts(
				array(
					'post_type'   => 'hcle_program',
					'post_status' => 'publish',
					'numberposts' => -1,
					'post__in'    => $enrolled,
					'orderby'     => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			)
			: array();
	}

	if ( ! $programs ) {
		return $notice . '<div class="hcle-myprograms"><p>'
			. esc_html__( 'You are not enrolled in any program yet. Please contact an administrator.', 'habeas-cle' )
			. '</p></div>';
	}

	$out = $notice . '<div class="hcle-myprograms">';
	foreach ( $programs as $program ) {
		$progress = hcle_get_program_progress( $program->ID );
		$url      = get_permalink( $program->ID );

		$out .= '<article class="hcle-myprograms__card">';
		$out .= sprintf(
			'<h2 class="hcle-myprograms__title"><a href="%s">%s</a></h2>',
			esc_url( $url ),
			esc_html( get_the_title( $program ) )
		);
		if ( $program->post_excerpt ) {
			$out .= '<p class="hcle-myprograms__excerpt">' . esc_html( $program->post_excerpt ) . '</p>';
		}
		$out .= hcle_render_progress_bar( $progress );
		$out .= sprintf(
			'<a class="hcle-myprograms__cta" href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Open program', 'habeas-cle' )
		);
		$out .= '</article>';
	}
	$out .= '</div>';

	return $out;
}
