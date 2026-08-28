<?php
/**
 * Platform CLE database schema.
 *
 * Enrollment and progress started life as serialized user meta — one array of
 * program IDs and one array of module IDs per user. That is fine for reading
 * "what is this user doing", which is all the site needed, but it cannot
 * answer the questions a CLE has to answer: who is enrolled in this program,
 * who completed this module, and — the one that matters for credit — WHEN.
 * A serialized array has no room for a timestamp and no way to be queried
 * except by loading every user and unserializing in PHP.
 *
 * So the two of them move into real tables. The public helpers in
 * enrollment.php and progress.php keep their signatures; only their storage
 * changes.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bumped whenever the schema below changes, so upgrades run.
 *
 * Compared against the `pcle_db_version` option on every load. Activation
 * hooks do not fire when a plugin is updated in place, and this plugin is
 * deployed by copying files, so waiting for activation would leave sites on
 * the old schema indefinitely.
 */
const PCLE_DB_VERSION = 4;

/** Option holding the installed schema version. */
const PCLE_DB_VERSION_OPTION = 'pcle_db_version';

/**
 * Fully-qualified name of the enrollments table.
 *
 * @return string
 */
function pcle_enrollments_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcle_enrollments';
}

/**
 * Fully-qualified name of the progress table.
 *
 * @return string
 */
function pcle_progress_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcle_progress';
}

/**
 * Fully-qualified name of the attendance table.
 *
 * @return string
 */
function pcle_attendance_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcle_attendance';
}

/**
 * Fully-qualified name of the quiz attempts table.
 *
 * @return string
 */
function pcle_quiz_attempts_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcle_quiz_attempts';
}

/**
 * Creates or updates the tables.
 *
 * Both carry a UNIQUE key on the natural pair, which is what makes
 * "enroll twice" and "complete twice" harmless at the storage level rather
 * than something every caller has to remember to check.
 *
 * The timestamps are NULLABLE on purpose. Rows migrated from the old user
 * meta genuinely have no known date — the previous model never recorded one —
 * and inventing one would put a fabricated completion date on what is meant
 * to become a compliance record. NULL says "before we started recording",
 * which is true, and a report can say so.
 */
function pcle_install_schema() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$enrollments     = pcle_enrollments_table();
	$progress        = pcle_progress_table();

	// dbDelta is particular: two spaces after PRIMARY KEY, lowercase `key`,
	// one field per line. It diffs this text against the live table.
	dbDelta(
		"CREATE TABLE {$enrollments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			program_id bigint(20) unsigned NOT NULL,
			enrolled_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_program (user_id,program_id),
			KEY program_id (program_id)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$progress} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			module_id bigint(20) unsigned NOT NULL,
			completed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_module (user_id,module_id),
			KEY module_id (module_id)
		) {$charset_collate};"
	);

	/*
	 * Attendance at live sessions. `marked_by` records WHICH instructor
	 * asserted it: unlike progress, which a participant records about
	 * themselves, this is one person vouching for another, and a credit
	 * record should be able to say who.
	 */
	$attendance = pcle_attendance_table();

	dbDelta(
		"CREATE TABLE {$attendance} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			marked_at datetime NULL DEFAULT NULL,
			marked_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_event (user_id,event_id),
			KEY event_id (event_id)
		) {$charset_collate};"
	);

	/*
	 * Quiz attempts. The odd one out among these tables: no UNIQUE key on the
	 * natural pair, because a participant may sit a quiz more than once and
	 * each sitting is its own record. "Did they pass" is therefore a question
	 * about the set, not about a row — see pcle_user_passed_quiz().
	 *
	 * `answers` is the submitted JSON, kept whole. Marking is recomputed from
	 * the questions at submission time and stored alongside, so a later edit
	 * to the quiz cannot retroactively change somebody's grade; and keeping
	 * the raw answers means free-text responses survive for an instructor to
	 * read, and item-level analysis stays possible without a second table.
	 */
	$attempts = pcle_quiz_attempts_table();

	dbDelta(
		"CREATE TABLE {$attempts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			quiz_id bigint(20) unsigned NOT NULL,
			submitted_at datetime NULL DEFAULT NULL,
			score smallint(5) unsigned NOT NULL DEFAULT 0,
			max_score smallint(5) unsigned NOT NULL DEFAULT 0,
			passed tinyint(1) NOT NULL DEFAULT 0,
			answers longtext NULL,
			PRIMARY KEY  (id),
			KEY user_quiz (user_id,quiz_id),
			KEY quiz_id (quiz_id)
		) {$charset_collate};"
	);
}

/**
 * Copies any legacy user meta into the tables.
 *
 * Idempotent twice over: it only reads users who still carry the meta, and
 * the inserts IGNORE duplicates against the UNIQUE keys. Running it again
 * changes nothing.
 *
 * The legacy meta is deliberately left in place. It is the only copy of this
 * data, the plugin no longer reads it, and deleting it in the same release
 * that stops using it would leave nothing to fall back on if the migration
 * turns out to be wrong. Removing it is a later, separate decision.
 *
 * @return array{enrollments:int, progress:int} Rows inserted.
 */
function pcle_migrate_legacy_meta() {
	global $wpdb;

	$inserted = array(
		'enrollments' => 0,
		'progress'    => 0,
	);

	$legacy = array(
		array(
			'meta'   => PCLE_ENROLLMENT_META,
			'table'  => pcle_enrollments_table(),
			'column' => 'program_id',
			'count'  => 'enrollments',
			'stamp'  => 'enrolled_at',
		),
		array(
			'meta'   => PCLE_PROGRESS_META,
			'table'  => pcle_progress_table(),
			'column' => 'module_id',
			'count'  => 'progress',
			'stamp'  => 'completed_at',
		),
	);

	foreach ( $legacy as $source ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- migration, one-off.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$source['meta']
			)
		);

		foreach ( $rows as $row ) {
			$ids = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $ids ) ) {
				continue;
			}

			foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
				if ( $id <= 0 ) {
					continue;
				}

				// INSERT IGNORE rather than $wpdb->insert: re-running must not
				// error on the unique key, and the timestamp stays NULL.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$done = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$source['table']} (user_id, {$source['column']}, {$source['stamp']})
						 VALUES (%d, %d, NULL)",
						(int) $row->user_id,
						$id
					)
				);

				$inserted[ $source['count'] ] += (int) $done;
			}
		}
	}

	return $inserted;
}

/**
 * Removes a deleted user's rows from the plugin's tables.
 *
 * WordPress cleans up its own usermeta when a user is deleted, which is why
 * the old serialized model needed nothing here. Custom tables are not part of
 * that, so without this a deleted account leaves enrollment, progress and
 * attendance rows behind — and those rows would keep turning up in cohort
 * reports as participants who no longer exist.
 *
 * Hooked on `deleted_user`, which fires after the deletion has succeeded.
 *
 * @param int $user_id Deleted user.
 */
function pcle_delete_user_records( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}

	foreach ( array( pcle_enrollments_table(), pcle_progress_table(), pcle_attendance_table(), pcle_quiz_attempts_table() ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/*
	 * `marked_by` points at the instructor who vouched, not the participant.
	 * Their leaving does not undo the assertion, so the row stays; the
	 * reference is zeroed so it cannot later resolve to a recycled ID.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->update( pcle_attendance_table(), array( 'marked_by' => 0 ), array( 'marked_by' => $user_id ), array( '%d' ), array( '%d' ) );
}
add_action( 'deleted_user', 'pcle_delete_user_records' );

/**
 * Removes a deleted post's rows from the plugin's tables.
 *
 * Deleting a programme, module or session used to leave enrollment, progress
 * and attendance rows pointing at an ID that no longer exists. Those rows are
 * invisible — nothing lists them — but they are not harmless: WordPress
 * reuses auto-increment IDs, so a future post can inherit somebody else's
 * completion history, and re-seeding demo content quietly accumulated
 * thousands of them.
 *
 * An ID identifies exactly one post, so clearing it from all three tables is
 * safe without knowing which type it was — which matters because the post has
 * already gone by the time this runs.
 *
 * @param int $post_id Deleted post.
 */
function pcle_delete_post_records( $post_id ) {
	global $wpdb;

	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return;
	}

	$targets = array(
		array( pcle_enrollments_table(), 'program_id' ),
		array( pcle_progress_table(), 'module_id' ),
		array( pcle_attendance_table(), 'event_id' ),
		array( pcle_quiz_attempts_table(), 'quiz_id' ),
	);

	foreach ( $targets as $target ) {
		list( $table, $column ) = $target;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->delete( $table, array( $column => $post_id ), array( '%d' ) );
	}
}
add_action( 'deleted_post', 'pcle_delete_post_records' );

/**
 * Renames the "week" concept to "unit" in existing data.
 *
 * The level between a programme and its modules was called a Week, which
 * promised a shape the content does not have: a stage of a course is rarely
 * exactly seven days. Renaming only the labels would have left the code saying
 * one thing and the screen another — this project has already paid for that
 * kind of drift once, in documentation.
 *
 * So the post type and the relationship meta key move too, which means the
 * rows have to move with them. Without this, an existing install would keep
 * its units as an unregistered `pcle_week` type — invisible in the admin — and
 * every module and session would lose its parent, because the code would be
 * reading `_pcle_unit_id` from rows that still said `_pcle_week_id`.
 *
 * Idempotent: after the first run there is nothing left matching the old
 * names, so a second run updates nothing.
 *
 * @return array{posts:int, meta:int} Rows updated.
 */
function pcle_migrate_week_to_unit() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off migration.
	$posts = (int) $wpdb->update(
		$wpdb->posts,
		array( 'post_type' => 'pcle_unit' ),
		array( 'post_type' => 'pcle_week' )
	);

	// Modules AND sessions both hang off this key.
	$meta = (int) $wpdb->update(
		$wpdb->postmeta,
		array( 'meta_key' => '_pcle_unit_id' ),
		array( 'meta_key' => '_pcle_week_id' )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	if ( $posts || $meta ) {
		// Post types and meta are cached per post; stale entries would keep
		// serving the old values for the rest of the request.
		wp_cache_flush();
	}

	return array(
		'posts' => $posts,
		'meta'  => $meta,
	);
}

/**
 * Brings the database up to PCLE_DB_VERSION if it isn't already.
 *
 * Runs on every load but costs one option read in the normal case.
 */
function pcle_maybe_upgrade_schema() {
	if ( (int) get_option( PCLE_DB_VERSION_OPTION, 0 ) === PCLE_DB_VERSION ) {
		return;
	}

	pcle_install_schema();
	pcle_migrate_legacy_meta();
	pcle_migrate_week_to_unit();

	update_option( PCLE_DB_VERSION_OPTION, PCLE_DB_VERSION );
}
add_action( 'plugins_loaded', 'pcle_maybe_upgrade_schema' );
