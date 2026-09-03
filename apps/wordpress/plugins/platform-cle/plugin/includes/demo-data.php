<?php
/**
 * Sample data seeder for Platform CLE.
 *
 * Builds a populated site rather than a single example: three programmes at
 * different stages and a roster of participants at different points in them.
 * That matters because most of this platform's screens only say anything with
 * a cohort behind them — a report with one row is not a report, and "enrolled
 * in this programme but not that one" cannot be shown at all with a single
 * programme.
 *
 * Dates are relative to seeding time, not fixed, so a demo run months from now
 * still shows a finished course, a course in progress, and sessions that have
 * not happened yet.
 *
 * It is IDEMPOTENT: every created post is marked with the `_pcle_demo` meta. On
 * re-run, it first deletes the previous demos and recreates them, without
 * touching the user's real content.
 *
 * This logic backs both the CLI script (bin/seed-demo.php) and the "Seed Demo
 * Data" button in the admin dashboard, so it works on hosts without shell/WP-CLI
 * access (e.g. shared hosting like SiteGround).
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inserts a demo post, marks it, and assigns its parent.
 */
function pcle_seed_post( $type, $title, $content, $order, $author_id, $parent_meta = null, $parent_id = 0 ) {
	/*
	 * Seeded bodies go in as the builder's own authored text, converted here
	 * to block markup. They used to be written as bare `<p>` HTML, which
	 * `pcle_authoring_text_from_content()` cannot round-trip: every seeded
	 * node therefore opened in the builder read-only, behind the "written in
	 * WordPress" notice, and demo content that cannot be edited teaches the
	 * wrong thing about the builder on the first click.
	 */
	$body = pcle_authoring_content_from_text( $content );

	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_content' => $body,
			'post_status'  => 'publish',
			'post_author'  => $author_id,
			'menu_order'   => $order,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, '_pcle_demo', 1 );
	if ( $parent_meta && $parent_id ) {
		update_post_meta( $id, $parent_meta, (int) $parent_id );
	}
	return (int) $id;
}

/**
 * A timestamp N weeks from now, where N may be negative.
 *
 * One unit of a programme is paced a week apart, so every demo date — the
 * programme's year, its live sessions, its backdated completions — is derived
 * from a week offset. This exists so that arithmetic happens in exactly one
 * place: it was previously spelled `strtotime( "{$n} units" )`, and because
 * "units" is not an interval strtotime understands, the offset was silently
 * dropped and every date collapsed onto the moment of seeding.
 *
 * @param int $weeks Offset in weeks; negative is in the past.
 * @return int Unix timestamp.
 */
function pcle_demo_weeks_from_now( $weeks ) {
	return time() + ( (int) $weeks * WEEK_IN_SECONDS );
}

/**
 * The programmes to build.
 *
 * `starts_in_weeks` is relative to now: negative is a course that already ran,
 * zero or positive one that is running or about to. Each carries the credit
 * hours it is approved for, so certificates and reports have something real to
 * show.
 *
 * @return array<string,array>
 */
function pcle_demo_programs() {
	return array(
		// Already finished: the only way to demonstrate a completed
		// participant, a completion date, and an issuable certificate.
		'asylum' => array(
			'title'           => 'Asylum Merits Hearings',
			'summary'         => 'Preparing and presenting an asylum case before the immigration court.',
			'starts_in_weeks' => -20,
			'credits'         => array( 'ks' => 3.0, 'mo' => 3.0 ),
			'units'           => array(
				array(
					'title'   => 'Building the Record',
					'desc'    => 'Declarations, country conditions, and corroboration.',
					'modules' => array( 'The Client Declaration', 'Country Conditions Evidence' ),
				),
				array(
					'title'   => 'The Hearing',
					'desc'    => 'Direct, cross, and preserving issues for appeal.',
					'modules' => array( 'Direct Examination', 'Preserving the Record for Appeal' ),
				),
			),
		),

		// The flagship course, mid-flight.
		'habeas' => array(
			'title'           => 'Immigration Habeas Corpus',
			'summary'         => 'A four-week virtual CLE program on litigating immigration habeas corpus petitions in federal court.',
			'starts_in_weeks' => -2,
			'credits'         => array( 'ks' => 6.0, 'mo' => 6.0 ),
			'units'           => array(
				array(
					'title'   => 'Foundations of the Great Writ',
					'desc'    => 'History and statutory basis of habeas corpus in the immigration context (28 U.S.C. § 2241).',
					'modules' => array(
						'The Suspension Clause and § 2241',
						'Habeas vs. the REAL ID Act channeling',
					),
				),
				array(
					'title'   => 'Jurisdiction and Custody',
					'desc'    => 'Who is the proper respondent, where to file, and what "in custody" means.',
					'modules' => array(
						'Immediate Custodian Rule & Proper Respondent',
						'District of Confinement and Venue',
						'Establishing "In Custody" Status',
					),
				),
				array(
					'title'   => 'Drafting the Petition',
					'desc'    => 'Building a persuasive § 2241 petition and supporting record.',
					'modules' => array(
						'Anatomy of a Habeas Petition',
						'Exhaustion and Procedural Posture',
					),
				),
				array(
					'title'   => 'Litigation and Hearings',
					'desc'    => 'Briefing, the return, traverse, and bond/release remedies.',
					'modules' => array(
						'The Government Return and Your Traverse',
						'Remedies: Release, Bond Hearings, and Stays',
					),
				),
			),
		),

		// A second live programme, so per-programme access is demonstrable
		// with a real participant rather than only with someone enrolled in
		// nothing.
		'bond'   => array(
			'title'           => 'Bond & Detention Practice',
			'summary'         => 'Custody redeterminations, bond hearings, and release advocacy.',
			'starts_in_weeks' => 1,
			'credits'         => array( 'ks' => 4.5, 'mo' => 4.0 ),
			'units'           => array(
				array(
					'title'   => 'Custody Determinations',
					'desc'    => 'Mandatory detention, discretionary custody, and the initial decision.',
					'modules' => array( 'Mandatory Detention under § 1226(c)', 'Discretionary Custody Decisions' ),
				),
				array(
					'title'   => 'The Bond Hearing',
					'desc'    => 'Burden, evidence, and presenting a release plan.',
					'modules' => array( 'Burden and Standard of Proof', 'Building a Release Plan' ),
				),
				array(
					'title'   => 'After the Decision',
					'desc'    => 'Appeals, redeterminations, and changed circumstances.',
					'modules' => array( 'Appealing to the BIA', 'Changed Circumstances and Re-filing' ),
				),
			),
		),
	);
}

/**
 * The participants to create, and where each one stands.
 *
 * Between them these cover the states the screens are meant to distinguish:
 * finished, mid-way, barely started, enrolled in two programmes, enrolled in
 * one but not another, strong and weak attendance — and one whose completions
 * carry no date, which is what a real site looks like after migrating from the
 * old storage.
 *
 * `progress` and `attendance` are fractions of each programme, 0.0 to 1.0.
 *
 * @return array<string,array>
 */
function pcle_demo_participants() {
	return array(
		'demo.student'   => array(
			'name'       => 'Demo Student (enrolled)',
			'progress'   => array( 'habeas' => 0.0 ),
			'attendance' => array( 'habeas' => 0.0 ),
		),
		'demo.outsider'  => array(
			'name'       => 'Demo Student (not enrolled)',
			'progress'   => array(),
			'attendance' => array(),
		),
		'ana.delgado'    => array(
			'name'       => 'Ana Delgado',
			'progress'   => array( 'asylum' => 1.0, 'habeas' => 0.6 ),
			'attendance' => array( 'asylum' => 1.0, 'habeas' => 0.5 ),
		),
		'marcus.webb'    => array(
			'name'       => 'Marcus Webb',
			'progress'   => array( 'habeas' => 1.0 ),
			'attendance' => array( 'habeas' => 1.0 ),
		),
		'priya.raman'    => array(
			'name'       => 'Priya Raman',
			'progress'   => array( 'habeas' => 0.3 ),
			'attendance' => array( 'habeas' => 0.25 ),
		),
		'tom.okafor'     => array(
			'name'       => 'Tom Okafor',
			'progress'   => array( 'bond' => 0.0 ),
			'attendance' => array( 'bond' => 0.0 ),
		),
		'sofia.nunes'    => array(
			'name'       => 'Sofia Nunes',
			'progress'   => array( 'habeas' => 0.45, 'bond' => 0.15 ),
			'attendance' => array( 'habeas' => 0.75, 'bond' => 0.0 ),
		),
		'lena.fischer'   => array(
			'name'       => 'Lena Fischer',
			'progress'   => array( 'habeas' => 0.8 ),
			'attendance' => array( 'habeas' => 0.75 ),
		),
		// Finished the course, but before completion dates were recorded.
		// This is the row that makes the reports caveat visible.
		'james.boyd'     => array(
			'name'       => 'James Boyd',
			'progress'   => array( 'asylum' => 1.0 ),
			'attendance' => array( 'asylum' => 0.5 ),
			'undated'    => true,
		),
	);
}

/**
 * Backdates a demo progress row to a plausible moment.
 *
 * The date is derived from the programme's own calendar — roughly when that
 * unit ran — rather than from "now", so a course that finished in April does
 * not show completions dated August. A per-participant jitter keeps a cohort
 * from finishing in lockstep.
 *
 * Only ever applied to demo content: every record here is invented, so a
 * plausible spread of dates is the honest representation. Real completions are
 * stamped when they happen and migrated ones are left NULL — see schema.php.
 *
 * @param int $user_id      Participant.
 * @param int $module_id    Module.
 * @param int $week_offset  Offset in weeks of the unit this module sits in, relative to now.
 * @param int $jitter_days  Per-participant spread, in days.
 * @return void
 */
function pcle_seed_backdate_progress( $user_id, $module_id, $week_offset, $jitter_days ) {
	global $wpdb;

	$when = pcle_demo_weeks_from_now( $week_offset ) + ( $jitter_days * DAY_IN_SECONDS );

	// Nobody completed anything tomorrow. Programmes that have not started yet
	// still allow a little pre-reading, so clamp to just before now rather
	// than refusing.
	$latest = time() - HOUR_IN_SECONDS;
	if ( $when > $latest ) {
		$when = $latest;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- seeding demo content.
	$wpdb->update(
		pcle_progress_table(),
		array( 'completed_at' => gmdate( 'Y-m-d H:i:s', $when ) ),
		array(
			'user_id'   => (int) $user_id,
			'module_id' => (int) $module_id,
		),
		array( '%s' ),
		array( '%d', '%d' )
	);
}

/**
 * Clears the completion date on a demo progress row.
 *
 * Reproduces what a site looks like after migrating from the old serialized
 * storage, so the reports screen has something to show its "completions
 * without a date" column for.
 *
 * @param int $user_id   Participant.
 * @param int $module_id Module.
 * @return void
 */
function pcle_seed_undate_progress( $user_id, $module_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- seeding demo content.
	$wpdb->update(
		pcle_progress_table(),
		array( 'completed_at' => null ),
		array(
			'user_id'   => (int) $user_id,
			'module_id' => (int) $module_id,
		),
		array( '%s' ),
		array( '%d', '%d' )
	);
}

/**
 * Seeds (or re-seeds) the sample programmes, returning a summary.
 *
 * @return array{removed:int, program:int, unit:int, module:int, scenario:int, template:int, event:int, case_update:int, users:string[]}
 */
function pcle_seed_demo_data() {
	$admins    = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$author_id = $admins ? (int) $admins[0] : 1;

	// 1) Clean up previous demos (idempotency).
	$all_types = array( 'pcle_program', 'pcle_unit', 'pcle_module', 'pcle_scenario', 'pcle_template', 'pcle_event', 'pcle_case_update' );
	$old       = get_posts(
		array(
			'post_type'   => $all_types,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_pcle_demo',
			'meta_value'  => 1,
		)
	);
	foreach ( $old as $oid ) {
		wp_delete_post( $oid, true );
	}

	$meta_program = '_pcle_program_id';
	$meta_unit    = '_pcle_unit_id';
	$meta_module  = '_pcle_module_id';

	$counts = array(
		'removed'     => count( $old ),
		'program'     => 0,
		'unit'        => 0,
		'module'      => 0,
		'scenario'    => 0,
		'template'    => 0,
		'event'       => 0,
		'case_update' => 0,
		'users'       => array(),
	);

	// Programme key => built IDs, for the enrollment pass below.
	$built = array();

	foreach ( pcle_demo_programs() as $key => $program ) {
		$year = (int) gmdate( 'Y', pcle_demo_weeks_from_now( $program['starts_in_weeks'] ) );

		$program_id = pcle_seed_post(
			'pcle_program',
			sprintf( '%s — %s', $program['title'], $year ),
			$program['summary'],
			$counts['program'] + 1,
			$author_id
		);

		if ( ! $program_id ) {
			continue;
		}

		$counts['program']++;

		foreach ( $program['credits'] as $code => $hours ) {
			update_post_meta( $program_id, pcle_credit_hours_meta_key( $code ), (float) $hours );
		}

		$built[ $key ] = array(
			'id'      => $program_id,
			'starts'  => (int) $program['starts_in_weeks'],
			'modules' => array(),
			'events'  => array(),
		);

		foreach ( $program['units'] as $w => $unit ) {
			$unit_id = pcle_seed_post(
				'pcle_unit',
				sprintf( 'Unit %d — %s', $w + 1, $unit['title'] ),
				$unit['desc'],
				$w + 1,
				$author_id,
				$meta_program,
				$program_id
			);
			$counts['unit']++;

			/*
			 * Titles here do not name their own type. The builder shows what a
			 * thing is beside it, and demo content is the first thing an
			 * instructor copies — "Scenario: X" taught everyone to say it twice.
			 */
			$event_id = pcle_seed_post(
				'pcle_event',
				sprintf( 'Unit %d — Live Discussion', $w + 1 ),
				'Weekly live discussion and Q&A with faculty.',
				$w + 1,
				$author_id,
				$meta_unit,
				$unit_id
			);

			if ( $event_id ) {
				// One session per unit from the programme's start date.
				$offset = $program['starts_in_weeks'] + $w;
				update_post_meta(
					$event_id,
					'_pcle_event_datetime',
					gmdate( 'Y-m-d 18:00:00', pcle_demo_weeks_from_now( $offset ) )
				);
				$counts['event']++;
				$built[ $key ]['events'][] = $event_id;
			}

			foreach ( $unit['modules'] as $m => $module_title ) {
				$module_id = pcle_seed_post(
					'pcle_module',
					$module_title,
					'Module content for: ' . $module_title . '.',
					$m + 1,
					$author_id,
					$meta_unit,
					$unit_id
				);
				$counts['module']++;
				// Keep the unit each module sits in: the seeded completion
				// dates are derived from the programme calendar, not from now.
				$built[ $key ]['modules'][] = array(
					'id'   => $module_id,
					'unit' => $w,
				);

				// A scenario and a template on the first module of each unit.
				if ( 0 === $m ) {
					$scenario_body = "Your client has been detained for 7 months without a bond hearing. "
						. "Draft the core jurisdictional argument for a § 2241 petition.\n\n"
						. "! **Model answer:** Frame the prolonged detention "
						. "as raising due-process concerns and establish jurisdiction under § 2241 in the district "
						. "of confinement, naming the immediate custodian (the facility warden) as respondent.";

					pcle_seed_post(
						'pcle_scenario',
						sprintf( '%s in Practice', $unit['title'] ),
						$scenario_body,
						1,
						$author_id,
						$meta_module,
						$module_id
					);
					$counts['scenario']++;

					pcle_seed_post(
						'pcle_template',
						sprintf( '%s Checklist', $unit['title'] ),
						'Fill-in-the-blank starting point you can adapt for a real filing.',
						1,
						$author_id,
						$meta_module,
						$module_id
					);
					$counts['template']++;
				}
			}
		}
	}

	// 2) Case Updates (independent of the hierarchy).
	pcle_seed_post(
		'pcle_case_update',
		'Case Update: Circuit Split on Immediate Custodian Rule',
		'A recent decision deepens the circuit split over who counts as the immediate custodian in transferred-detainee cases.',
		1,
		$author_id
	);
	pcle_seed_post(
		'pcle_case_update',
		'Case Update: New Guidance on Prolonged Detention',
		'Updated district court guidance on what constitutes "prolonged" detention triggering a bond hearing.',
		2,
		$author_id
	);
	$counts['case_update'] = 2;

	// 3) Demo accounts and their standing (opt-in; see pcle_seed_demo_users()).
	$counts['users'] = pcle_seed_demo_users( $built );

	return $counts;
}

/**
 * Creates the demo accounts and puts each one where its profile says.
 *
 * Opt-in via PCLE_DEMO_USER_PASSWORD. The sample content is harmless
 * anywhere; accounts with a password anyone can read out of a repo are not, so
 * with that variable unset — any real host — this does nothing at all.
 *
 * Idempotent: existing accounts are reused, with role, password, enrollment
 * and standing re-applied.
 *
 * @param array<string,array> $built Programme key => {id, modules, events}.
 * @return string[] Logins that exist as a result (empty when opted out).
 */
function pcle_seed_demo_users( $built ) {
	$password = getenv( 'PCLE_DEMO_USER_PASSWORD' );

	if ( ! is_string( $password ) || '' === $password ) {
		return array();
	}

	// Seeding is not a reason to email anybody: enrolling fires the
	// confirmation notice, and a demo run would otherwise send one per
	// participant per programme.
	$silence = function () {
		return true;
	};
	add_filter( 'pre_wp_mail', $silence, 99 );

	$created = array();

	// Staff first, so there is somebody to attribute attendance to.
	$instructor_id = pcle_seed_demo_user( 'demo.instructor', 'Demo Instructor', 'pcle_instructor', $password );
	if ( $instructor_id ) {
		$created[] = 'demo.instructor';
	}

	foreach ( pcle_demo_participants() as $login => $profile ) {
		$user_id = pcle_seed_demo_user( $login, $profile['name'], 'pcle_student', $password );

		if ( ! $user_id ) {
			continue;
		}

		$created[] = $login;

		// A stable per-participant offset so a cohort does not finish in
		// lockstep. Derived from the login, so re-seeding reproduces it.
		$jitter = ( crc32( $login ) % 5 ) + 1;

		foreach ( $profile['progress'] as $program_key => $fraction ) {
			if ( ! isset( $built[ $program_key ] ) ) {
				continue;
			}

			$program = $built[ $program_key ];
			pcle_enroll_user( $program['id'], $user_id );

			// Complete the first N modules, so progress reads as someone
			// working through the course in order rather than at random.
			$modules = $program['modules'];
			$take    = (int) round( count( $modules ) * (float) $fraction );

			foreach ( array_slice( $modules, 0, $take ) as $module ) {
				pcle_mark_module_complete( $module['id'], $user_id );

				if ( ! empty( $profile['undated'] ) ) {
					pcle_seed_undate_progress( $user_id, $module['id'] );
					continue;
				}

				pcle_seed_backdate_progress(
					$user_id,
					$module['id'],
					$program['starts'] + $module['unit'],
					$jitter
				);
			}

			$attendance_fraction = isset( $profile['attendance'][ $program_key ] )
				? (float) $profile['attendance'][ $program_key ]
				: 0.0;

			$events = $program['events'];
			$attend = (int) round( count( $events ) * $attendance_fraction );

			foreach ( array_slice( $events, 0, $attend ) as $event_id ) {
				pcle_mark_attendance( $event_id, $user_id, $instructor_id );
			}
		}
	}

	remove_filter( 'pre_wp_mail', $silence, 99 );

	return $created;
}

/**
 * Creates or refreshes one demo account.
 *
 * @param string $login    Login name.
 * @param string $name     Display name.
 * @param string $role     Role slug.
 * @param string $password Shared demo password.
 * @return int User ID, or 0 on failure.
 */
function pcle_seed_demo_user( $login, $name, $role, $password ) {
	$user = get_user_by( 'login', $login );

	if ( $user ) {
		wp_update_user(
			array(
				'ID'           => $user->ID,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => $name,
			)
		);

		return (int) $user->ID;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $login . '@example.test',
			'user_pass'    => $password,
			'display_name' => $name,
			'role'         => $role,
		)
	);

	return is_wp_error( $user_id ) ? 0 : (int) $user_id;
}

/**
 * Handles the "Seed Demo Data" button submission from the admin dashboard.
 *
 * Restricted to administrators (`manage_options`) since this deletes and
 * recreates content site-wide, unlike the per-program capabilities used
 * elsewhere in the plugin.
 */
function pcle_handle_seed_demo_data_request() {
	if ( ! isset( $_POST['pcle_seed_demo_data'] ) ) {
		return;
	}

	if ( ! isset( $_POST['pcle_seed_demo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcle_seed_demo_nonce'] ) ), 'pcle_seed_demo_data' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'platform-cle' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'platform-cle' ) );
	}

	$counts = pcle_seed_demo_data();

	set_transient( 'pcle_seed_demo_result', $counts, MINUTE_IN_SECONDS );

	wp_safe_redirect( add_query_arg( array( 'page' => 'platform-cle', 'pcle_seeded' => '1' ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_pcle_seed_demo_data', 'pcle_handle_seed_demo_data_request' );
