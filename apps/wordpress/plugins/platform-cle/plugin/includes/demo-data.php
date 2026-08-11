<?php
/**
 * Sample data seeder for Platform CLE.
 *
 * Creates a full 4-week program with modules, scenarios, templates, events, and
 * case updates, all linked through the plugin's relationships.
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
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_content' => $content,
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
 * Seeds (or re-seeds) the sample program, returning a summary of what was created.
 *
 * @return array{removed:int, program:int, week:int, module:int, scenario:int, template:int, event:int, case_update:int}
 */
function pcle_seed_demo_data() {
	$admins    = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$author_id = $admins ? (int) $admins[0] : 1;

	// 1) Clean up previous demos (idempotency).
	$all_types = array( 'pcle_program', 'pcle_week', 'pcle_module', 'pcle_scenario', 'pcle_template', 'pcle_event', 'pcle_case_update' );
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

	// 2) Program.
	$program_id = pcle_seed_post(
		'pcle_program',
		'Immigration Habeas Corpus — Spring 2026',
		'<p>A four-week virtual CLE program on litigating immigration habeas corpus petitions in federal court.</p>',
		1,
		$author_id
	);

	// 3) Weeks, modules, scenarios, templates, and events.
	$weeks = array(
		array(
			'title'   => 'Week 1 — Foundations of the Great Writ',
			'desc'    => 'History and statutory basis of habeas corpus in the immigration context (28 U.S.C. § 2241).',
			'modules' => array(
				'The Suspension Clause and § 2241',
				'Habeas vs. the REAL ID Act channeling',
			),
		),
		array(
			'title'   => 'Week 2 — Jurisdiction and Custody',
			'desc'    => 'Who is the proper respondent, where to file, and what "in custody" means.',
			'modules' => array(
				'Immediate Custodian Rule & Proper Respondent',
				'District of Confinement and Venue',
				'Establishing "In Custody" Status',
			),
		),
		array(
			'title'   => 'Week 3 — Drafting the Petition',
			'desc'    => 'Building a persuasive § 2241 petition and supporting record.',
			'modules' => array(
				'Anatomy of a Habeas Petition',
				'Exhaustion and Procedural Posture',
			),
		),
		array(
			'title'   => 'Week 4 — Litigation and Hearings',
			'desc'    => 'Briefing, the return, traverse, and bond/release remedies.',
			'modules' => array(
				'The Government Return and Your Traverse',
				'Remedies: Release, Bond Hearings, and Stays',
			),
		),
	);

	// Reusable meta keys (must match relationships.php).
	$meta_program = '_pcle_program_id';
	$meta_week    = '_pcle_week_id';
	$meta_module  = '_pcle_module_id';

	$counts = array(
		'removed'     => count( $old ),
		'program'     => $program_id ? 1 : 0,
		'week'        => 0,
		'module'      => 0,
		'scenario'    => 0,
		'template'    => 0,
		'event'       => 0,
		'case_update' => 0,
	);

	foreach ( $weeks as $w => $week ) {
		$week_id = pcle_seed_post(
			'pcle_week',
			$week['title'],
			'<p>' . esc_html( $week['desc'] ) . '</p>',
			$w + 1,
			$author_id,
			$meta_program,
			$program_id
		);
		$counts['week']++;

		// The week's live session.
		$event_id = pcle_seed_post(
			'pcle_event',
			'Live Session — ' . $week['title'],
			'<p>Weekly live discussion and Q&amp;A with faculty.</p>',
			$w + 1,
			$author_id,
			$meta_week,
			$week_id
		);
		if ( $event_id ) {
			// Event date: consecutive Tuesdays at 18:00.
			update_post_meta( $event_id, '_pcle_event_datetime', gmdate( 'Y-m-d 18:00:00', strtotime( "2026-03-03 +{$w} week" ) ) );
			$counts['event']++;
		}

		foreach ( $week['modules'] as $m => $module_title ) {
			$module_id = pcle_seed_post(
				'pcle_module',
				$module_title,
				'<p>Module content for: ' . esc_html( $module_title ) . '.</p>',
				$m + 1,
				$author_id,
				$meta_week,
				$week_id
			);
			$counts['module']++;

			// Add a scenario and a template to the first module of each week.
			if ( 0 === $m ) {
				$scenario_body = "<p>Your client has been detained for 7 months without a bond hearing. "
					. "Draft the core jurisdictional argument for a § 2241 petition.</p>\n"
					. "[pcle_model_answer]<p><strong>Model answer:</strong> Frame the prolonged detention "
					. "as raising due-process concerns and establish jurisdiction under § 2241 in the district "
					. "of confinement, naming the immediate custodian (the facility warden) as respondent.</p>[/pcle_model_answer]";

				pcle_seed_post(
					'pcle_scenario',
					'Scenario: Prolonged Detention Without a Bond Hearing',
					$scenario_body,
					1,
					$author_id,
					$meta_module,
					$module_id
				);
				$counts['scenario']++;

				pcle_seed_post(
					'pcle_template',
					'Template: § 2241 Petition Skeleton',
					'<p>Fill-in-the-blank skeleton for a federal habeas petition under 28 U.S.C. § 2241.</p>',
					1,
					$author_id,
					$meta_module,
					$module_id
				);
				$counts['template']++;
			}
		}
	}

	// 4) Case Updates (independent of the hierarchy).
	pcle_seed_post(
		'pcle_case_update',
		'Case Update: Circuit Split on Immediate Custodian Rule',
		'<p>A recent decision deepens the circuit split over who counts as the immediate custodian in transferred-detainee cases.</p>',
		1,
		$author_id
	);
	pcle_seed_post(
		'pcle_case_update',
		'Case Update: New Guidance on Prolonged Detention',
		'<p>Updated district court guidance on what constitutes "prolonged" detention triggering a bond hearing.</p>',
		2,
		$author_id
	);
	$counts['case_update'] = 2;

	// 5) Demo accounts (opt-in; see pcle_seed_demo_users()).
	$counts['users'] = pcle_seed_demo_users( $program_id );

	return $counts;
}

/**
 * Creates the demo accounts, when — and only when — a demo password is set.
 *
 * The sample content is harmless anywhere; accounts with a password anyone
 * can read out of a repo are not. So this is opt-in via the environment:
 * with PCLE_DEMO_USER_PASSWORD unset (any real host) it does nothing, and
 * the seeder behaves exactly as before. docker-compose sets it for local
 * development only.
 *
 * Idempotent: existing demo accounts are reused, with their role, password
 * and enrollment re-applied.
 *
 * Three accounts, because the interesting bugs live in the differences
 * between them: enrolled vs. holding an account but enrolled in nothing
 * (the "hasn't paid" case), plus staff.
 *
 * @param int $program_id Program to enroll the enrolled student into.
 * @return string[] Logins that exist as a result (empty when opted out).
 */
function pcle_seed_demo_users( $program_id ) {
	$password = getenv( 'PCLE_DEMO_USER_PASSWORD' );

	if ( ! is_string( $password ) || '' === $password ) {
		return array();
	}

	$accounts = array(
		array(
			'login'  => 'demo.student',
			'email'  => 'demo.student@example.test',
			'name'   => 'Demo Student (enrolled)',
			'role'   => 'pcle_student',
			'enroll' => true,
		),
		array(
			'login'  => 'demo.outsider',
			'email'  => 'demo.outsider@example.test',
			'name'   => 'Demo Student (not enrolled)',
			'role'   => 'pcle_student',
			'enroll' => false,
		),
		array(
			'login'  => 'demo.instructor',
			'email'  => 'demo.instructor@example.test',
			'name'   => 'Demo Instructor',
			'role'   => 'pcle_instructor',
			'enroll' => false,
		),
	);

	$created = array();

	foreach ( $accounts as $account ) {
		$user = get_user_by( 'login', $account['login'] );

		if ( $user ) {
			$user_id = (int) $user->ID;
			wp_update_user(
				array(
					'ID'        => $user_id,
					'user_pass' => $password,
					'role'      => $account['role'],
				)
			);
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login'   => $account['login'],
					'user_email'   => $account['email'],
					'user_pass'    => $password,
					'display_name' => $account['name'],
					'role'         => $account['role'],
				)
			);

			if ( is_wp_error( $user_id ) ) {
				continue;
			}
		}

		if ( $account['enroll'] && $program_id ) {
			pcle_enroll_user( $program_id, $user_id );
		}

		$created[] = $account['login'];
	}

	return $created;
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
