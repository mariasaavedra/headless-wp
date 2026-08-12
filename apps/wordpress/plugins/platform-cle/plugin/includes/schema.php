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
const PCLE_DB_VERSION = 2;

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

	update_option( PCLE_DB_VERSION_OPTION, PCLE_DB_VERSION );
}
add_action( 'plugins_loaded', 'pcle_maybe_upgrade_schema' );
