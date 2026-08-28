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
