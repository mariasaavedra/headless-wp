<?php
/**
 * Platform CLE quizzes — the questions, and who is allowed to see the answers.
 *
 * A quiz hangs off a module, alongside practice scenarios and templates. It is
 * the first piece of content in this plugin that has a *right answer*, which
 * makes it the first piece with something to leak.
 *
 * Two decisions are worth stating plainly, because both of them are load-bearing
 * and neither is obvious from the code alone.
 *
 * 1) THE QUESTIONS LIVE IN POST META, NOT A TABLE.
 *
 *    schema.php moved enrollment and progress into real tables because those
 *    have to be *queried*: who is enrolled in this programme, who finished this
 *    module, when. A question is never queried. It has no URL, no permissions
 *    of its own, and is never read except as part of the whole quiz it belongs
 *    to. Meta is the right weight for that, and it keeps the relationship
 *    machinery at three levels instead of four.
 *
 *    Attempts are the opposite — those get a table, when scoring lands.
 *
 * 2) THE CORRECT ANSWERS NEVER GO IN A REGISTERED META.
 *
 *    This plugin has already shipped this class of bug twice: a REST guard
 *    hooked to a filter that did not exist, and then a guard that covered
 *    single items but not collection listings, which put every programme's
 *    model answers within reach of anyone with an account. See the audit notes
 *    in docs/ROADMAP.md.
 *
 *    So the questions meta is deliberately NOT passed through
 *    register_post_meta(). Unregistered meta is absent from the REST API by
 *    default, and the key is underscore-prefixed, so it is protected meta as
 *    well and stays out of the editor's custom-fields box. The way this data
 *    reaches a client is one function — pcle_quiz_questions_for_taking() —
 *    which strips the answers, and that function exists now, before there is a
 *    participant route to use it, precisely so the route cannot be written
 *    without it.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Every question, including which choices are correct. Never REST-registered. */
const PCLE_QUIZ_QUESTIONS_META = '_pcle_quiz_questions';

/** Whether passing this quiz is required to complete its module. */
const PCLE_QUIZ_GATES_META = '_pcle_quiz_gates_completion';

/** Percentage of the available score needed to pass. */
const PCLE_QUIZ_PASS_MARK_META = '_pcle_quiz_pass_mark';

/** Pass mark applied when the author has not set one. */
const PCLE_QUIZ_DEFAULT_PASS_MARK = 70;

/**
 * The kinds of question an author can write.
 *
 * `text` is deliberately present and deliberately unscored. A free-text answer
 * cannot be marked by this plugin, and pretending otherwise would either invent
 * a grade or require a marking queue for instructors — which is a feature in
 * its own right, not a detail of this one. So free text is for reflection: it
 * is recorded with the attempt and shown to instructors, and it contributes
 * nothing to the score.
 *
 * @return array<string,string> Type => human label.
 */
function pcle_quiz_question_types() {
	return array(
		'single'   => __( 'One correct answer', 'platform-cle' ),
		'multiple' => __( 'Several correct answers', 'platform-cle' ),
		'text'     => __( 'Free text (not scored)', 'platform-cle' ),
	);
}

/**
 * Is this question type one the server can mark?
 *
 * @param string $type Question type.
 * @return bool
 */
function pcle_quiz_type_is_scored( $type ) {
	return in_array( $type, array( 'single', 'multiple' ), true );
}

/**
 * Turns anything into a usable question key.
 *
 * Keys are the form field names the participant's browser will submit, so they
 * have to survive a round trip through HTML and be stable across edits — an
 * answer recorded against `q3` must still mean the same question after the
 * author rewords it. They are generated once, on first save, and preserved
 * thereafter.
 *
 * @param string $value    Proposed key.
 * @param string $fallback Used when the proposal sanitises to nothing.
 * @return string
 */
function pcle_quiz_sanitize_key( $value, $fallback ) {
	$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $value ) );

	return '' !== $key ? substr( $key, 0, 32 ) : $fallback;
}

/**
 * Validates and normalises a set of questions.
 *
 * Everything a client sends passes through here. Prompts and choice text are
 * plain text, never markup: authoring-content.php makes the same promise for
 * bodies, and for the same reason — instructors do not hold `unfiltered_html`,
 * and an authoring API must not become the way around that.
 *
 * Structural nonsense is dropped rather than rejected: a question with no
 * prompt, or a choice question with fewer than two choices, is not a question.
 * Refusing the whole save because one row is half-finished would lose the other
 * nine.
 *
 * @param mixed $raw Questions as submitted.
 * @return array Normalised questions, re-indexed.
 */
function pcle_sanitize_quiz_questions( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$questions = array();
	$seen_keys = array();
	$position  = 0;

	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		++$position;

		$prompt = sanitize_textarea_field( (string) ( $item['prompt'] ?? '' ) );

		if ( '' === $prompt ) {
			continue;
		}

		$type = (string) ( $item['type'] ?? 'single' );

		if ( ! isset( pcle_quiz_question_types()[ $type ] ) ) {
			$type = 'single';
		}

		$key = pcle_quiz_sanitize_key( $item['key'] ?? '', 'q' . $position );

		// A duplicate key would make two questions share one form field, and
		// the second would silently overwrite the first on submission.
		while ( isset( $seen_keys[ $key ] ) ) {
			$key .= '_' . $position;
		}
		$seen_keys[ $key ] = true;

		$question = array(
			'key'      => $key,
			'type'     => $type,
			'prompt'   => $prompt,
			'help'     => sanitize_textarea_field( (string) ( $item['help'] ?? '' ) ),
			'feedback' => sanitize_textarea_field( (string) ( $item['feedback'] ?? '' ) ),
			'required' => ! empty( $item['required'] ),
			'choices'  => array(),
		);

		if ( pcle_quiz_type_is_scored( $type ) ) {
			$question['choices'] = pcle_sanitize_quiz_choices( $item['choices'] ?? array() );

			// One option is not a question, and none is not answerable at all.
			if ( count( $question['choices'] ) < 2 ) {
				continue;
			}

			// A scored question nobody can get right is an authoring mistake
			// that would only surface as every participant failing.
			$correct = 0;
			foreach ( $question['choices'] as $choice ) {
				$correct += $choice['correct'] ? 1 : 0;
			}

			if ( 0 === $correct ) {
				$question['choices'][0]['correct'] = true;
			}

			// "One correct answer" has to mean one, or scoring it is undefined.
			if ( 'single' === $type && $correct > 1 ) {
				$kept = false;
				foreach ( $question['choices'] as $index => $choice ) {
					if ( ! $choice['correct'] ) {
						continue;
					}
					if ( $kept ) {
						$question['choices'][ $index ]['correct'] = false;
					}
					$kept = true;
				}
			}
		}

		$questions[] = $question;
	}

	return $questions;
}

/**
 * Validates the choices of one question.
 *
 * @param mixed $raw Choices as submitted.
 * @return array
 */
function pcle_sanitize_quiz_choices( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$choices  = array();
	$seen     = array();
	$position = 0;

	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		++$position;

		$text = sanitize_text_field( (string) ( $item['text'] ?? '' ) );

		if ( '' === $text ) {
			continue;
		}

		$key = pcle_quiz_sanitize_key( $item['key'] ?? '', 'c' . $position );

		while ( isset( $seen[ $key ] ) ) {
			$key .= '_' . $position;
		}
		$seen[ $key ] = true;

		$choices[] = array(
			'key'     => $key,
			'text'    => $text,
			'correct' => ! empty( $item['correct'] ),
		);
	}

	return $choices;
}

/**
 * Every question of a quiz, answers included.
 *
 * This is the authoring view. Callers are responsible for having established
 * that the user may edit the programme — which, for the REST layer, means
 * pcle_authoring_guard_node(). Nothing that serves a participant may call it;
 * use pcle_quiz_questions_for_taking().
 *
 * @param int $quiz_id Quiz post ID.
 * @return array
 */
function pcle_get_quiz_questions( $quiz_id ) {
	$stored = get_post_meta( (int) $quiz_id, PCLE_QUIZ_QUESTIONS_META, true );

	return is_array( $stored ) ? $stored : array();
}

/**
 * The same questions with everything a participant must not see removed.
 *
 * Strips which choices are correct, and the per-question feedback, which is
 * written to be read *after* answering and frequently gives the answer away.
 *
 * This is the only shape that may cross the wire to somebody taking the quiz.
 * It exists before the route that needs it so that writing that route is a
 * matter of calling this rather than remembering to.
 *
 * @param int $quiz_id Quiz post ID.
 * @return array
 */
function pcle_quiz_questions_for_taking( $quiz_id ) {
	$out = array();

	foreach ( pcle_get_quiz_questions( $quiz_id ) as $question ) {
		$shaped = array(
			'key'      => $question['key'],
			'type'     => $question['type'],
			'prompt'   => $question['prompt'],
			'help'     => $question['help'],
			'required' => ! empty( $question['required'] ),
			'choices'  => array(),
		);

		foreach ( $question['choices'] as $choice ) {
			$shaped['choices'][] = array(
				'key'  => $choice['key'],
				'text' => $choice['text'],
			);
		}

		$out[] = $shaped;
	}

	return $out;
}

/**
 * Replaces a quiz's questions.
 *
 * @param int   $quiz_id   Quiz post ID.
 * @param mixed $questions Questions to store (sanitised here).
 * @return array What was stored.
 */
function pcle_set_quiz_questions( $quiz_id, $questions ) {
	$clean = pcle_sanitize_quiz_questions( $questions );

	update_post_meta( (int) $quiz_id, PCLE_QUIZ_QUESTIONS_META, $clean );

	return $clean;
}

/**
 * Must this quiz be passed before its module counts as complete?
 *
 * False by default, and that default is a product decision rather than a
 * placeholder. credits.php already refuses to derive credit from attendance on
 * the grounds that it would be "inventing a credit rule nobody signed off on";
 * making a quiz block completion everywhere would be the same move. Whether an
 * assessment is required is an accreditation question, answered per course by
 * whoever holds the paperwork — so it is a switch on the quiz.
 *
 * Nothing enforces this yet: progress.php will read it when scoring lands. It
 * is stored from the start so that authors are not asked to revisit every quiz
 * they wrote once it does.
 *
 * @param int $quiz_id Quiz post ID.
 * @return bool
 */
function pcle_quiz_gates_completion( $quiz_id ) {
	return (bool) get_post_meta( (int) $quiz_id, PCLE_QUIZ_GATES_META, true );
}

/**
 * The percentage needed to pass.
 *
 * @param int $quiz_id Quiz post ID.
 * @return int 1–100.
 */
function pcle_quiz_pass_mark( $quiz_id ) {
	$stored = get_post_meta( (int) $quiz_id, PCLE_QUIZ_PASS_MARK_META, true );

	return '' === $stored ? PCLE_QUIZ_DEFAULT_PASS_MARK : pcle_sanitize_quiz_pass_mark( $stored );
}

/**
 * Clamps a pass mark to something meaningful.
 *
 * Zero would mean "passing is not a thing this quiz can fail at", which is what
 * the gating switch is for, so the floor is 1.
 *
 * @param mixed $value Proposed pass mark.
 * @return int 1–100.
 */
function pcle_sanitize_quiz_pass_mark( $value ) {
	return max( 1, min( 100, (int) $value ) );
}

/**
 * The highest score obtainable on a quiz.
 *
 * One point per scored question. Free-text questions are worth nothing, by
 * design — see pcle_quiz_question_types(). A quiz of nothing but free text has
 * a maximum of zero, which is what makes it unscorable, and callers have to
 * handle that rather than divide by it.
 *
 * @param int $quiz_id Quiz post ID.
 * @return int
 */
function pcle_quiz_max_score( $quiz_id ) {
	$max = 0;

	foreach ( pcle_get_quiz_questions( $quiz_id ) as $question ) {
		$max += pcle_quiz_type_is_scored( $question['type'] ) ? 1 : 0;
	}

	return $max;
}

/* =========================================================================
 * MARKING AND ATTEMPTS
 * ========================================================================= */

/**
 * Normalises submitted answers into question key => value.
 *
 * A choice question answers with one or more choice keys; free text answers
 * with a string. Anything else is discarded rather than coerced — a value of
 * an unexpected shape is a client bug, and guessing at what it meant would put
 * an invented answer into what becomes a compliance record.
 *
 * @param mixed $raw Answers as submitted.
 * @return array<string,string|string[]>
 */
function pcle_sanitize_quiz_answers( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$answers = array();

	foreach ( $raw as $key => $value ) {
		$key = pcle_quiz_sanitize_key( $key, '' );

		if ( '' === $key ) {
			continue;
		}

		if ( is_array( $value ) ) {
			$keys = array();

			foreach ( $value as $item ) {
				$item = pcle_quiz_sanitize_key( $item, '' );

				if ( '' !== $item ) {
					$keys[] = $item;
				}
			}

			$answers[ $key ] = array_values( array_unique( $keys ) );
			continue;
		}

		$answers[ $key ] = sanitize_textarea_field( (string) $value );
	}

	return $answers;
}

/**
 * The required questions a submission has left unanswered.
 *
 * Checked on the server because the browser's own `required` is a convenience,
 * not a guarantee: this endpoint is reachable without going through the form.
 *
 * @param int   $quiz_id Quiz post ID.
 * @param array $answers Sanitised answers.
 * @return string[] Question keys.
 */
function pcle_quiz_missing_required( $quiz_id, $answers ) {
	$missing = array();

	foreach ( pcle_get_quiz_questions( $quiz_id ) as $question ) {
		if ( empty( $question['required'] ) ) {
			continue;
		}

		$given = $answers[ $question['key'] ] ?? null;

		if ( null === $given || '' === $given || array() === $given ) {
			$missing[] = $question['key'];
		}
	}

	return $missing;
}

/**
 * Marks a submission against the quiz as it stands.
 *
 * Choice questions are all-or-nothing: a multiple-answer question is right
 * when the set chosen is exactly the set that is correct. Partial credit was
 * considered and left out, because "half the answers is half a mark" is a
 * grading rule somebody has to decide on, and inventing one here would put a
 * number nobody agreed to on a record that may support CLE credit. If a
 * jurisdiction asks for partial credit, it belongs here, once, deliberately.
 *
 * Free text is recorded and returned but never scored — see
 * pcle_quiz_question_types().
 *
 * @param int   $quiz_id Quiz post ID.
 * @param array $answers Sanitised answers.
 * @return array{score:int,max_score:int,percent:int,passed:bool,questions:array}
 */
function pcle_mark_quiz( $quiz_id, $answers ) {
	$score   = 0;
	$max     = 0;
	$results = array();

	foreach ( pcle_get_quiz_questions( $quiz_id ) as $question ) {
		$key   = $question['key'];
		$given = $answers[ $key ] ?? null;

		$result = array(
			'key'      => $key,
			'type'     => $question['type'],
			'prompt'   => $question['prompt'],
			'answered' => null !== $given && '' !== $given && array() !== $given,
			'feedback' => $question['feedback'],
		);

		if ( ! pcle_quiz_type_is_scored( $question['type'] ) ) {
			// Recorded, shown back, and worth nothing.
			$result['scored']   = false;
			$result['response'] = is_string( $given ) ? $given : '';
			$results[]          = $result;
			continue;
		}

		++$max;

		$correct_keys = array();
		foreach ( $question['choices'] as $choice ) {
			if ( $choice['correct'] ) {
				$correct_keys[] = $choice['key'];
			}
		}

		$chosen = is_array( $given ) ? $given : ( null === $given ? array() : array( (string) $given ) );

		// Order is not part of an answer, so compare as sets.
		sort( $chosen );
		sort( $correct_keys );

		$is_correct = $chosen === $correct_keys;
		$score     += $is_correct ? 1 : 0;

		$result['scored']       = true;
		$result['correct']      = $is_correct;
		$result['chosen']       = $chosen;
		$result['correct_keys'] = $correct_keys;

		$results[] = $result;
	}

	/*
	 * A quiz of nothing but free text has no maximum, so there is nothing to
	 * be below. Treating that as a pass is what stops such a quiz from
	 * blocking a module forever when its author also ticked the gate — the
	 * alternative is an unsatisfiable condition, which is worse than a
	 * generous one.
	 */
	$percent = $max > 0 ? (int) floor( ( $score / $max ) * 100 ) : 100;

	return array(
		'score'     => $score,
		'max_score' => $max,
		'percent'   => $percent,
		'passed'    => $percent >= pcle_quiz_pass_mark( $quiz_id ),
		'questions' => $results,
	);
}

/**
 * Records one sitting of a quiz.
 *
 * The marking is stored with the attempt rather than recomputed on read. A
 * quiz is editable, and an author who fixes a wrong answer next month must not
 * silently change what somebody scored last month — a stored grade is a
 * statement about a moment, and this is the table a credit audit would read.
 *
 * The number of attempts is not capped. A retry limit is a policy somebody has
 * to set, not a default worth guessing at; every attempt is kept, so a limit
 * can be applied later without having lost the history it would need.
 *
 * @param int      $quiz_id Quiz post ID.
 * @param mixed    $raw     Answers as submitted.
 * @param int|null $user_id User (defaults to the current one).
 * @return array|WP_Error The marking, plus the attempt id.
 */
function pcle_record_quiz_attempt( $quiz_id, $raw, $user_id = null ) {
	global $wpdb;

	$user_id = pcle_resolve_user_id( $user_id );
	$quiz_id = (int) $quiz_id;

	if ( ! $user_id || 'pcle_quiz' !== get_post_type( $quiz_id ) ) {
		return new WP_Error( 'pcle_invalid_quiz', __( 'Invalid quiz.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	if ( ! pcle_get_quiz_questions( $quiz_id ) ) {
		return new WP_Error( 'pcle_quiz_empty', __( 'This quiz has no questions yet.', 'platform-cle' ), array( 'status' => 409 ) );
	}

	$answers = pcle_sanitize_quiz_answers( $raw );
	$missing = pcle_quiz_missing_required( $quiz_id, $answers );

	if ( $missing ) {
		return new WP_Error(
			'pcle_quiz_incomplete',
			__( 'Some required questions have not been answered.', 'platform-cle' ),
			array(
				'status'  => 400,
				'missing' => $missing,
			)
		);
	}

	$marked = pcle_mark_quiz( $quiz_id, $answers );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->insert(
		pcle_quiz_attempts_table(),
		array(
			'user_id'      => $user_id,
			'quiz_id'      => $quiz_id,
			'submitted_at' => current_time( 'mysql' ),
			'score'        => $marked['score'],
			'max_score'    => $marked['max_score'],
			'passed'       => $marked['passed'] ? 1 : 0,
			'answers'      => wp_json_encode( $answers ),
		),
		array( '%d', '%d', '%s', '%d', '%d', '%d', '%s' )
	);

	$marked['attempt_id'] = (int) $wpdb->insert_id;

	return $marked;
}

/**
 * Every attempt a user has made at a quiz, newest first.
 *
 * @param int      $quiz_id Quiz post ID.
 * @param int|null $user_id User (defaults to the current one).
 * @return array<int,array>
 */
function pcle_get_quiz_attempts( $quiz_id, $user_id = null ) {
	global $wpdb;

	$user_id = pcle_resolve_user_id( $user_id );

	if ( ! $user_id ) {
		return array();
	}

	$table = pcle_quiz_attempts_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, submitted_at, score, max_score, passed FROM {$table}
			 WHERE user_id = %d AND quiz_id = %d ORDER BY id DESC",
			$user_id,
			(int) $quiz_id
		),
		ARRAY_A
	);

	return array_map(
		function ( $row ) {
			return array(
				'id'           => (int) $row['id'],
				'submitted_at' => $row['submitted_at'],
				'score'        => (int) $row['score'],
				'max_score'    => (int) $row['max_score'],
				'passed'       => (bool) (int) $row['passed'],
			);
		},
		$rows ? $rows : array()
	);
}

/**
 * Has this user ever passed this quiz?
 *
 * Ever, not most recently. Passing is something that happened; sitting it
 * again out of interest and doing worse does not un-happen it.
 *
 * @param int      $quiz_id Quiz post ID.
 * @param int|null $user_id User (defaults to the current one).
 * @return bool
 */
function pcle_user_passed_quiz( $quiz_id, $user_id = null ) {
	global $wpdb;

	$user_id = pcle_resolve_user_id( $user_id );

	if ( ! $user_id ) {
		return false;
	}

	$table = pcle_quiz_attempts_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE user_id = %d AND quiz_id = %d AND passed = 1 LIMIT 1",
			$user_id,
			(int) $quiz_id
		)
	);
}

/**
 * The published quizzes of a module that must be passed to complete it.
 *
 * Drafts are excluded: an unpublished quiz is not in front of anybody, and
 * letting one block completion would mean an author could freeze a cohort's
 * progress by starting to write an assessment.
 *
 * @param int $module_id Module ID.
 * @return int[] Quiz post IDs.
 */
function pcle_module_required_quizzes( $module_id ) {
	$required = array();

	foreach ( pcle_get_children( (int) $module_id, 'pcle_quiz' ) as $quiz ) {
		if ( pcle_quiz_gates_completion( $quiz->ID ) && pcle_get_quiz_questions( $quiz->ID ) ) {
			$required[] = (int) $quiz->ID;
		}
	}

	return $required;
}

/**
 * Required quizzes of a module this user has not passed yet.
 *
 * @param int      $module_id Module ID.
 * @param int|null $user_id   User (defaults to the current one).
 * @return int[] Quiz post IDs.
 */
function pcle_module_completion_blockers( $module_id, $user_id = null ) {
	$blockers = array();

	foreach ( pcle_module_required_quizzes( $module_id ) as $quiz_id ) {
		if ( ! pcle_user_passed_quiz( $quiz_id, $user_id ) ) {
			$blockers[] = $quiz_id;
		}
	}

	return $blockers;
}

/**
 * Every published quiz in a programme.
 *
 * Walks the hierarchy the way pcle_get_program_module_ids() does, so a quiz
 * counts for reporting on exactly the terms a module does — published, and
 * reachable from the programme.
 *
 * @param int $program_id Programme ID.
 * @return int[]
 */
function pcle_get_program_quiz_ids( $program_id ) {
	$ids = array();

	foreach ( pcle_get_units( $program_id ) as $unit ) {
		foreach ( pcle_get_modules( $unit->ID ) as $module ) {
			foreach ( pcle_get_children( $module->ID, 'pcle_quiz' ) as $quiz ) {
				$ids[] = (int) $quiz->ID;
			}
		}
	}

	return $ids;
}

/**
 * The quizzes in a programme that must be passed to complete their module.
 *
 * The subset a report has to treat differently: an unpassed optional quiz is
 * a gap in someone's practice, while an unpassed required one is the reason
 * their module — and therefore their certificate — is not finished.
 *
 * @param int $program_id Programme ID.
 * @return int[]
 */
function pcle_get_program_required_quiz_ids( $program_id ) {
	$required = array();

	foreach ( pcle_get_program_quiz_ids( $program_id ) as $quiz_id ) {
		if ( pcle_quiz_gates_completion( $quiz_id ) && pcle_get_quiz_questions( $quiz_id ) ) {
			$required[] = $quiz_id;
		}
	}

	return $required;
}
