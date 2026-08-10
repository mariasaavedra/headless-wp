<?php
/**
 * Habeas CLE hierarchical relationships.
 *
 * Links the CPTs in the curriculum hierarchy using post meta:
 *
 *   Program ─┬─ Week ─┬─ Module ─┬─ Practice Scenario
 *            │        │          └─ Template
 *            │        └─ Schedule Event
 *
 * Each "child" stores its "parent" ID in a meta. We provide:
 *   - Meta registration (REST + auth) for the block editor.
 *   - A meta box with a parent selector on the edit screen.
 *   - Secure save (nonce + capability + type validation).
 *   - Helper functions to traverse the hierarchy in both directions.
 *   - An admin list column that shows the parent.
 *
 * @package Habeas_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relationship map (SINGLE SOURCE OF TRUTH).
 *
 * Key   = child CPT.
 * Value = ['parent' => parent CPT, 'meta_key' => meta key, 'label' => label].
 *
 * Note: several children can reuse the same meta_key because it points to the
 * SAME parent type (e.g. module and event both hang off a Week).
 *
 * @return array<string, array{parent:string, meta_key:string, label:string}>
 */
function hcle_relationship_map() {
	return array(
		'hcle_week'     => array(
			'parent'   => 'hcle_program',
			'meta_key' => '_hcle_program_id',
			'label'    => __( 'Parent Program', 'habeas-cle' ),
		),
		'hcle_module'   => array(
			'parent'   => 'hcle_week',
			'meta_key' => '_hcle_week_id',
			'label'    => __( 'Parent Week', 'habeas-cle' ),
		),
		'hcle_event'    => array(
			'parent'   => 'hcle_week',
			'meta_key' => '_hcle_week_id',
			'label'    => __( 'Parent Week', 'habeas-cle' ),
		),
		'hcle_scenario' => array(
			'parent'   => 'hcle_module',
			'meta_key' => '_hcle_module_id',
			'label'    => __( 'Parent Module', 'habeas-cle' ),
		),
		'hcle_template' => array(
			'parent'   => 'hcle_module',
			'meta_key' => '_hcle_module_id',
			'label'    => __( 'Parent Module', 'habeas-cle' ),
		),
	);
}

/* =========================================================================
 * 1) META REGISTRATION (REST + permissions)
 * ========================================================================= */

/**
 * Registers each relationship meta as an integer, exposed in REST.
 *
 * show_in_rest allows reading/writing the link from the block editor.
 * auth_callback ensures only someone who can edit the post touches the meta.
 */
function hcle_register_relationship_meta() {
	$registered = array();

	foreach ( hcle_relationship_map() as $child => $rel ) {
		// Avoid registering the same (child, meta_key) twice if it repeats.
		$signature = $child . '|' . $rel['meta_key'];
		if ( isset( $registered[ $signature ] ) ) {
			continue;
		}
		$registered[ $signature ] = true;

		register_post_meta(
			$child,
			$rel['meta_key'],
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
}
add_action( 'init', 'hcle_register_relationship_meta' );

/* =========================================================================
 * 2) META BOX (parent selector)
 * ========================================================================= */

/**
 * Adds the "parent" meta box to each child's edit screen.
 */
function hcle_add_relationship_metaboxes() {
	foreach ( hcle_relationship_map() as $child => $rel ) {
		add_meta_box(
			'hcle_relationship_' . $child,
			$rel['label'],
			'hcle_render_relationship_metabox',
			$child,
			'side',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'hcle_add_relationship_metaboxes' );

/**
 * Renders the meta box: a <select> with the possible parents.
 *
 * @param WP_Post $post Post being edited.
 */
function hcle_render_relationship_metabox( $post ) {
	$map = hcle_relationship_map();
	if ( ! isset( $map[ $post->post_type ] ) ) {
		return;
	}

	$rel      = $map[ $post->post_type ];
	$current  = (int) get_post_meta( $post->ID, $rel['meta_key'], true );
	$parents  = hcle_get_selectable_parents( $rel['parent'] );

	// Nonce to validate the save.
	wp_nonce_field( 'hcle_save_relationship', 'hcle_relationship_nonce' );

	echo '<p>';
	printf(
		'<label for="hcle_parent_select" class="screen-reader-text">%s</label>',
		esc_html( $rel['label'] )
	);
	echo '<select name="' . esc_attr( $rel['meta_key'] ) . '" id="hcle_parent_select" style="width:100%;">';
	echo '<option value="0">' . esc_html__( '— None —', 'habeas-cle' ) . '</option>';

	foreach ( $parents as $parent ) {
		printf(
			'<option value="%d"%s>%s</option>',
			(int) $parent->ID,
			selected( $current, $parent->ID, false ),
			esc_html( $parent->post_title ? $parent->post_title : sprintf( __( '(no title) #%d', 'habeas-cle' ), $parent->ID ) )
		);
	}

	echo '</select>';
	echo '</p>';

	if ( empty( $parents ) ) {
		$pt_obj = get_post_type_object( $rel['parent'] );
		$name   = $pt_obj ? $pt_obj->labels->singular_name : $rel['parent'];
		echo '<p class="description">';
		printf(
			/* translators: %s: parent post type singular name. */
			esc_html__( 'No %s exists yet. Create one first.', 'habeas-cle' ),
			esc_html( $name )
		);
		echo '</p>';
	}
}

/**
 * Returns the posts that can be parents (of a given type).
 *
 * Includes drafts/private posts so the hierarchy can be built while everything
 * is still in preparation. Ordered by menu_order then title.
 *
 * @param string $parent_type Parent CPT.
 * @return WP_Post[]
 */
function hcle_get_selectable_parents( $parent_type ) {
	return get_posts(
		array(
			'post_type'        => $parent_type,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => -1,
			'orderby'          => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'suppress_filters' => false,
		)
	);
}

/* =========================================================================
 * 3) SECURE SAVE
 * ========================================================================= */

/**
 * Saves the parent link when the child is saved.
 *
 * Checks: autosave, nonce, capability, and that the chosen parent is actually
 * of the correct type (prevents anyone from injecting an arbitrary ID).
 *
 * @param int     $post_id ID of the post being saved.
 * @param WP_Post $post    The post object.
 */
function hcle_save_relationship( $post_id, $post ) {
	$map = hcle_relationship_map();
	if ( ! isset( $map[ $post->post_type ] ) ) {
		return;
	}
	$rel = $map[ $post->post_type ];

	// 1. Skip autosaves and revisions.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// 2. Nonce. If the field isn't present, it's not our form → leave it alone.
	if ( ! isset( $_POST['hcle_relationship_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hcle_relationship_nonce'] ) ), 'hcle_save_relationship' )
	) {
		return;
	}

	// 3. Permissions.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// 4. Read and validate the submitted value.
	$parent_id = isset( $_POST[ $rel['meta_key'] ] ) ? absint( $_POST[ $rel['meta_key'] ] ) : 0;

	if ( $parent_id > 0 ) {
		// The parent must exist and be of the expected type.
		if ( get_post_type( $parent_id ) !== $rel['parent'] ) {
			$parent_id = 0;
		}
		// And it can't be itself (just in case).
		if ( $parent_id === $post_id ) {
			$parent_id = 0;
		}
	}

	if ( $parent_id > 0 ) {
		update_post_meta( $post_id, $rel['meta_key'], $parent_id );
	} else {
		delete_post_meta( $post_id, $rel['meta_key'] );
	}
}
add_action( 'save_post', 'hcle_save_relationship', 10, 2 );

/* =========================================================================
 * 4) QUERY HELPERS (the API used by the rest of the plugin/theme)
 * ========================================================================= */

/**
 * Returns a post's children for a specific child CPT.
 *
 * Ordered by menu_order then title. Published only by default.
 *
 * @param int    $parent_id  Parent ID.
 * @param string $child_type Child CPT (must exist in the relationship map).
 * @param array  $args       WP_Query overrides (e.g. post_status).
 * @return WP_Post[]
 */
function hcle_get_children( $parent_id, $child_type, $args = array() ) {
	$map = hcle_relationship_map();
	if ( ! isset( $map[ $child_type ] ) || $parent_id <= 0 ) {
		return array();
	}

	$rel      = $map[ $child_type ];
	$defaults = array(
		'post_type'   => $child_type,
		'post_status' => 'publish',
		'numberposts' => -1,
		'orderby'     => array(
			'menu_order' => 'ASC',
			'title'      => 'ASC',
		),
		'meta_key'    => $rel['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => (int) $parent_id, // phpcs:ignore WordPress.DB.SlowDBQuery
	);

	return get_posts( wp_parse_args( $args, $defaults ) );
}

/**
 * Returns a child's parent ID (0 if it has none).
 *
 * @param int $child_id Child ID.
 * @return int
 */
function hcle_get_parent_id( $child_id ) {
	$child_type = get_post_type( $child_id );
	$map        = hcle_relationship_map();
	if ( ! isset( $map[ $child_type ] ) ) {
		return 0;
	}
	return (int) get_post_meta( $child_id, $map[ $child_type ]['meta_key'], true );
}

/**
 * Walks up the hierarchy to find the Program a post belongs to.
 *
 * Works for any curriculum content (Week, Module, Scenario, Template, Event).
 * Returns 0 if it can't be determined (e.g. Case Update, which doesn't hang off
 * any program).
 *
 * @param int $post_id Post ID.
 * @return int Program ID, or 0.
 */
function hcle_get_program_for_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( 'hcle_program' === get_post_type( $post_id ) ) {
		return $post_id;
	}

	$cursor = $post_id;
	$guard  = 0;
	while ( $cursor && $guard < 10 ) {
		$parent = hcle_get_parent_id( $cursor );
		if ( ! $parent ) {
			return 0;
		}
		if ( 'hcle_program' === get_post_type( $parent ) ) {
			return (int) $parent;
		}
		$cursor = $parent;
		$guard++;
	}
	return 0;
}

// ---- Readable shortcuts for the concrete hierarchy ----

/**
 * Weeks of a program.
 *
 * @param int   $program_id Program ID.
 * @param array $args       WP_Query overrides.
 * @return WP_Post[]
 */
function hcle_get_weeks( $program_id, $args = array() ) {
	return hcle_get_children( $program_id, 'hcle_week', $args );
}

/**
 * Modules of a week.
 *
 * @param int   $week_id Week ID.
 * @param array $args    WP_Query overrides.
 * @return WP_Post[]
 */
function hcle_get_modules( $week_id, $args = array() ) {
	return hcle_get_children( $week_id, 'hcle_module', $args );
}

/**
 * Practice scenarios of a module.
 *
 * @param int   $module_id Module ID.
 * @param array $args      WP_Query overrides.
 * @return WP_Post[]
 */
function hcle_get_scenarios( $module_id, $args = array() ) {
	return hcle_get_children( $module_id, 'hcle_scenario', $args );
}

/**
 * Templates of a module.
 *
 * @param int   $module_id Module ID.
 * @param array $args      WP_Query overrides.
 * @return WP_Post[]
 */
function hcle_get_templates( $module_id, $args = array() ) {
	return hcle_get_children( $module_id, 'hcle_template', $args );
}

/**
 * Schedule events of a week.
 *
 * @param int   $week_id Week ID.
 * @param array $args    WP_Query overrides.
 * @return WP_Post[]
 */
function hcle_get_events( $week_id, $args = array() ) {
	return hcle_get_children( $week_id, 'hcle_event', $args );
}

/* =========================================================================
 * 5) ADMIN COLUMN (show the parent in the lists)
 * ========================================================================= */

/**
 * Adds the "parent" column to each child's table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function hcle_add_parent_admin_column( $columns ) {
	// Insert before the date column if it exists.
	$new = array();
	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			$new['hcle_parent'] = __( 'Parent', 'habeas-cle' );
		}
		$new[ $key ] = $label;
	}
	if ( ! isset( $new['hcle_parent'] ) ) {
		$new['hcle_parent'] = __( 'Parent', 'habeas-cle' );
	}
	return $new;
}

/**
 * Fills the "parent" column with an editable link.
 *
 * @param string $column  Column slug.
 * @param int    $post_id ID of the row's post.
 */
function hcle_render_parent_admin_column( $column, $post_id ) {
	if ( 'hcle_parent' !== $column ) {
		return;
	}

	$parent_id = hcle_get_parent_id( $post_id );
	if ( $parent_id <= 0 ) {
		echo '<span aria-hidden="true">—</span>';
		return;
	}

	$title = get_the_title( $parent_id );
	$link  = get_edit_post_link( $parent_id );

	if ( $link ) {
		printf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) );
	} else {
		echo esc_html( $title );
	}
}

/**
 * Hooks the column onto each child CPT.
 */
function hcle_register_parent_admin_columns() {
	foreach ( array_keys( hcle_relationship_map() ) as $child ) {
		add_filter( "manage_{$child}_posts_columns", 'hcle_add_parent_admin_column' );
		add_action( "manage_{$child}_posts_custom_column", 'hcle_render_parent_admin_column', 10, 2 );
	}
}
add_action( 'admin_init', 'hcle_register_parent_admin_columns' );
