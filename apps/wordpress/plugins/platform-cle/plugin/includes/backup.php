<?php
/**
 * Programme backups — a whole programme as one file, and back again.
 *
 * What the builder makes lives as posts, post meta and parent links whose
 * shape is this plugin's business and will keep changing: a unit was a week
 * once. A backup that were a dump of those rows would only ever restore into
 * the plugin that wrote it. So the file describes the programme in its own
 * terms — a programme has units, a unit has modules and sessions — with
 * neutral type names, and every file says which version of that description
 * it is.
 *
 * That version is the promise. Reading a file runs it through
 * pcle_backup_migrate() first, which upgrades an older version step by step
 * to the current one; an import only ever sees the current shape. When the
 * builder changes what it stores, the exporter changes, PCLE_BACKUP_VERSION
 * goes up, and one migration step is written for the difference. Files made
 * before still restore.
 *
 * A restore always makes a NEW programme, as a draft. It never overwrites the
 * one it came from: a backup restored over live content by mistake is the
 * kind of loss a backup exists to prevent.
 *
 * Deliberately not in the file: enrolments, progress, attendance and quiz
 * attempts. Those are records about people, not the programme, and a copy of
 * a course must not carry a cohort's credit history with it.
 *
 * Files attached to an item are listed with their addresses, not embedded.
 * They stay in the site's uploads, which the host's backups cover; embedding
 * them would make a course with a few PDFs into a file too large to upload
 * back.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** What a backup file says it is. Anything else is refused. */
const PCLE_BACKUP_FORMAT = 'platform-cle/programme';

/**
 * The version of the file's shape this plugin writes and reads.
 *
 * Bump it whenever the exported shape changes, and add the step from the old
 * version to pcle_backup_migrations().
 */
const PCLE_BACKUP_VERSION = 1;

/** Upper bound on items in one file: generous for a course, a stop for a bad file. */
const PCLE_BACKUP_MAX_NODES = 5000;

/**
 * Post types and the neutral names the file uses for them.
 *
 * @return array<string,string> Post type => file type.
 */
function pcle_backup_type_names() {
	return array(
		'pcle_program'  => 'programme',
		'pcle_unit'     => 'unit',
		'pcle_module'   => 'module',
		'pcle_event'    => 'session',
		'pcle_scenario' => 'scenario',
		'pcle_quiz'     => 'quiz',
		'pcle_template' => 'template',
	);
}

/* =========================================================================
 * Export
 * ========================================================================= */

/**
 * One item and everything under it, as the file describes it.
 *
 * `content` is the stored block markup, not the builder's editable text: the
 * markup is what participants are shown, and the builder's text is a view of
 * it that leaves some regions out.
 *
 * Every status is included. A draft is work somebody did, and a backup that
 * silently dropped it would not restore what the builder showed.
 *
 * @param WP_Post $post Post.
 * @return array
 */
function pcle_backup_export_node( $post ) {
	$names = pcle_backup_type_names();

	$node = array(
		'type'      => $names[ $post->post_type ],
		'title'     => $post->post_title,
		'status'    => $post->post_status,
		'excerpt'   => $post->post_excerpt,
		'content'   => $post->post_content,
		'source_id' => (int) $post->ID,
	);

	switch ( $post->post_type ) {
		case 'pcle_program':
			$node['credits'] = pcle_get_credit_hours( $post->ID );
			break;

		case 'pcle_quiz':
			$node['questions']        = pcle_get_quiz_questions( $post->ID );
			$node['pass_mark']        = pcle_quiz_pass_mark( $post->ID );
			$node['gates_completion'] = pcle_quiz_gates_completion( $post->ID );
			break;

		case 'pcle_event':
			$node['starts_at'] = pcle_get_event_datetime( $post->ID );
			break;
	}

	$attachments = get_children(
		array(
			'post_parent' => $post->ID,
			'post_type'   => 'attachment',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);

	$node['attachments'] = array_values(
		array_map(
			function ( $attachment ) {
				return array(
					'source_id' => (int) $attachment->ID,
					'filename'  => wp_basename( (string) get_attached_file( $attachment->ID ) ),
					'mime'      => $attachment->post_mime_type,
					'url'       => wp_get_attachment_url( $attachment->ID ),
				);
			},
			$attachments
		)
	);

	/*
	 * Children in curriculum order, one list rather than one per type. The
	 * order in the file is the order to restore, so nothing needs to carry a
	 * menu_order that only means something on this site.
	 */
	$node['children'] = array();

	foreach ( pcle_allowed_child_types( $post->post_type ) as $child_type ) {
		foreach ( pcle_authoring_get_children( $post->ID, $child_type ) as $child ) {
			$node['children'][] = pcle_backup_export_node( $child );
		}
	}

	return $node;
}

/**
 * A whole programme, as a backup file's contents.
 *
 * @param int $program_id Programme ID.
 * @return array
 */
function pcle_backup_export_program( $program_id ) {
	$user = wp_get_current_user();

	return array(
		'format'      => PCLE_BACKUP_FORMAT,
		'version'     => PCLE_BACKUP_VERSION,
		'exported_at' => gmdate( DATE_ATOM ),
		'exported_by' => $user->ID ? $user->display_name : null,
		'site'        => home_url(),
		'programme'   => pcle_backup_export_node( get_post( $program_id ) ),
	);
}

/* =========================================================================
 * Reading a file: migrate, then validate
 * ========================================================================= */

/**
 * The steps from each older version to the next.
 *
 * Empty today: version 1 is the first. When the shape changes, add
 * `1 => function ( $data ) { ...; return $data; }` and bump
 * PCLE_BACKUP_VERSION to 2. Each step only ever has to know about two
 * adjacent versions.
 *
 * @return array<int, callable> From-version => step to the next version.
 */
function pcle_backup_migrations() {
	return array();
}

/**
 * Brings a file's contents up to the current version.
 *
 * A file newer than this plugin is refused rather than read as far as it
 * goes: its meaning may have changed in ways an older reader cannot see, and
 * a half-understood restore is worse than none.
 *
 * @param mixed $data Decoded file.
 * @return array|WP_Error
 */
function pcle_backup_migrate( $data ) {
	if ( ! is_array( $data ) || ( $data['format'] ?? null ) !== PCLE_BACKUP_FORMAT ) {
		return new WP_Error( 'pcle_backup_invalid', __( 'That file is not a programme backup.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	$version = $data['version'] ?? null;

	if ( ! is_int( $version ) || $version < 1 ) {
		return new WP_Error( 'pcle_backup_invalid', __( 'That backup does not say which version it is.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	if ( $version > PCLE_BACKUP_VERSION ) {
		return new WP_Error(
			'pcle_backup_too_new',
			__( 'That backup was made by a newer version of the platform than this one. Update the platform, then restore it.', 'platform-cle' ),
			array( 'status' => 400 )
		);
	}

	$steps = pcle_backup_migrations();

	while ( $version < PCLE_BACKUP_VERSION ) {
		if ( ! isset( $steps[ $version ] ) ) {
			return new WP_Error( 'pcle_backup_invalid', __( 'That backup is from a version this platform cannot read.', 'platform-cle' ), array( 'status' => 400 ) );
		}

		$data = call_user_func( $steps[ $version ], $data );
		++$version;
		$data['version'] = $version;
	}

	return $data;
}

/**
 * Checks one item and everything under it before anything is written.
 *
 * Structure only — types, nesting, the fields being the right kind of thing.
 * Values are cleaned on the way in by the same sanitisers the builder uses,
 * so a file cannot put into a quiz what the quiz editor could not.
 *
 * @param mixed  $node        Item from the file.
 * @param string $parent_type Post type of its parent, or '' at the top.
 * @param int    $count       Items seen so far, carried across the walk.
 * @return true|WP_Error
 */
function pcle_backup_validate_node( $node, $parent_type, &$count ) {
	if ( ! is_array( $node ) ) {
		return new WP_Error( 'pcle_backup_invalid', __( 'That backup has an item that is not readable.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	++$count;

	if ( $count > PCLE_BACKUP_MAX_NODES ) {
		return new WP_Error( 'pcle_backup_invalid', __( 'That backup has more items than a programme can.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	$type = array_search( $node['type'] ?? null, pcle_backup_type_names(), true );

	if ( false === $type ) {
		return new WP_Error( 'pcle_backup_invalid', __( 'That backup has an item of a kind this platform does not know.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	$map = pcle_relationship_map();

	$fits = '' === $parent_type
		? 'pcle_program' === $type
		: ( isset( $map[ $type ] ) && $map[ $type ]['parent'] === $parent_type );

	if ( ! $fits ) {
		return new WP_Error(
			'pcle_backup_invalid',
			/* translators: %s: item title. */
			sprintf( __( 'In that backup, "%s" sits somewhere it cannot.', 'platform-cle' ), (string) ( $node['title'] ?? '' ) ),
			array( 'status' => 400 )
		);
	}

	foreach ( array( 'title', 'content', 'excerpt', 'status' ) as $field ) {
		if ( isset( $node[ $field ] ) && ! is_string( $node[ $field ] ) ) {
			return new WP_Error( 'pcle_backup_invalid', __( 'That backup has an item with an unreadable field.', 'platform-cle' ), array( 'status' => 400 ) );
		}
	}

	if ( isset( $node['children'] ) && ! is_array( $node['children'] ) ) {
		return new WP_Error( 'pcle_backup_invalid', __( 'That backup has an item with an unreadable field.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	foreach ( $node['children'] ?? array() as $child ) {
		$valid = pcle_backup_validate_node( $child, $type, $count );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}

	return true;
}

/* =========================================================================
 * Restore
 * ========================================================================= */

/**
 * Creates one item and everything under it.
 *
 * Content goes through wp_kses_post() unless the restorer could have written
 * unfiltered HTML anyway. A backup is a file anyone could have edited, and
 * restoring one must not be a way round the filtering the builder applies.
 *
 * @param array $node      Validated item.
 * @param int   $parent_id Parent post, or 0 for the programme.
 * @param int   $order     Position among its siblings.
 * @param int[] $created   IDs created so far, for rollback.
 * @return int|WP_Error New post ID.
 */
function pcle_backup_restore_node( $node, $parent_id, $order, &$created ) {
	$type    = array_search( $node['type'], pcle_backup_type_names(), true );
	$status  = in_array( $node['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $node['status'] : 'draft';
	$content = (string) ( $node['content'] ?? '' );

	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$content = wp_kses_post( $content );
	}

	$title = sanitize_text_field( (string) ( $node['title'] ?? '' ) );

	$post_id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => '' !== $title ? $title : __( '(untitled)', 'platform-cle' ),
			// The programme itself always comes back as a draft: a restore
			// should not put a course in front of anybody until someone has
			// looked at it.
			'post_status'  => 'pcle_program' === $type ? 'draft' : $status,
			'post_content' => $content,
			'post_excerpt' => sanitize_textarea_field( (string) ( $node['excerpt'] ?? '' ) ),
			'menu_order'   => $order,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$created[] = (int) $post_id;

	if ( $parent_id ) {
		$map = pcle_relationship_map();
		update_post_meta( $post_id, $map[ $type ]['meta_key'], $parent_id );
	}

	switch ( $type ) {
		case 'pcle_program':
			foreach ( (array) ( $node['credits'] ?? array() ) as $code => $hours ) {
				if ( isset( pcle_jurisdictions()[ $code ] ) && pcle_sanitize_credit_hours( $hours ) > 0 ) {
					update_post_meta( $post_id, pcle_credit_hours_meta_key( $code ), pcle_sanitize_credit_hours( $hours ) );
				}
			}
			break;

		case 'pcle_quiz':
			pcle_set_quiz_questions( $post_id, $node['questions'] ?? array() );

			if ( isset( $node['pass_mark'] ) ) {
				update_post_meta( $post_id, PCLE_QUIZ_PASS_MARK_META, pcle_sanitize_quiz_pass_mark( $node['pass_mark'] ) );
			}

			update_post_meta( $post_id, PCLE_QUIZ_GATES_META, ! empty( $node['gates_completion'] ) ? 1 : 0 );
			break;

		case 'pcle_event':
			if ( ! empty( $node['starts_at'] ) ) {
				update_post_meta( $post_id, PCLE_EVENT_DATETIME_META, pcle_sanitize_event_datetime( (string) $node['starts_at'] ) );
			}
			break;
	}

	foreach ( array_values( $node['children'] ?? array() ) as $position => $child ) {
		$child_id = pcle_backup_restore_node( $child, $post_id, $position + 1, $created );

		if ( is_wp_error( $child_id ) ) {
			return $child_id;
		}
	}

	return (int) $post_id;
}

/**
 * Restores a backup file's contents as a new, draft programme.
 *
 * All or nothing. Everything is checked before the first write, and if a
 * write still fails part-way, what was created is deleted again: half a
 * programme is harder to notice than none.
 *
 * @param mixed $data Decoded file.
 * @return array{id:int, title:string, items:int}|WP_Error
 */
function pcle_backup_import( $data ) {
	$data = pcle_backup_migrate( $data );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$count = 0;
	$valid = pcle_backup_validate_node( $data['programme'] ?? null, '', $count );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$created    = array();
	$program_id = pcle_backup_restore_node( $data['programme'], 0, 0, $created );

	if ( is_wp_error( $program_id ) ) {
		foreach ( array_reverse( $created ) as $id ) {
			wp_delete_post( $id, true );
		}

		return new WP_Error( 'pcle_backup_failed', __( 'The backup could not be restored. Nothing was kept.', 'platform-cle' ), array( 'status' => 500 ) );
	}

	return array(
		'id'    => (int) $program_id,
		'title' => get_the_title( $program_id ),
		'items' => count( $created ),
	);
}

/* =========================================================================
 * Routes
 * ========================================================================= */

/**
 * GET /authoring/programs/<id>/export — the backup file's contents.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_export_program( $request ) {
	return rest_ensure_response( pcle_backup_export_program( (int) $request['id'] ) );
}

/**
 * POST /authoring/programs/import — restore a backup as a new programme.
 *
 * The file's contents are the JSON body, exactly as the export route gave
 * them.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_import_program( $request ) {
	$result = pcle_backup_import( $request->get_json_params() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( $result, 201 );
}

/**
 * Registers the backup routes.
 *
 * Export uses the same guard as reading the programme's tree: whoever may
 * edit it may take a copy. Import makes a new programme, so it is gated as
 * creating one is.
 */
function pcle_register_backup_routes() {
	register_rest_route(
		'platform-cle/v1',
		'/authoring/programs/(?P<id>\d+)/export',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_export_program',
			'permission_callback' => function ( $request ) {
				return pcle_authoring_guard_node( $request, 'pcle_program' );
			},
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/authoring/programs/import',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_rest_import_program',
			'permission_callback' => function () {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
				}

				return pcle_user_is_staff()
					? true
					: new WP_Error( 'pcle_cannot_edit', __( 'You may not create programmes.', 'platform-cle' ), array( 'status' => 403 ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_backup_routes' );
