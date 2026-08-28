"use server";

import { revalidatePath } from "next/cache";

import type { QuizResult } from "@/lib/types";
import { submitQuizAttempt, WordPressApiError } from "@/lib/wordpress";

type QuizActionState = {
  error?: string;
  /** The questions the server refused the submission over. */
  missing?: string[];
  result?: QuizResult;
};

/**
 * Sits a quiz.
 *
 * The answers are read out of the form rather than held in React state, which
 * is what lets the questionnaire be a real <form>: each question's field is
 * named for its question key, and a hidden `type.<key>` field says how to read
 * it back. Several answers to one question arrive as repeated fields, so
 * `getAll` is the difference between a multiple-answer question and a
 * single-answer one — not a guess about the value's shape.
 *
 * Nothing here marks anything. The client is never sent the correct answers,
 * so it could not mark them if it wanted to; the result comes back from
 * WordPress.
 */
async function submitQuizAction(
  _prev: QuizActionState,
  formData: FormData
): Promise<QuizActionState> {
  const quizId = Number(formData.get("quiz_id"));

  if (!Number.isInteger(quizId) || quizId <= 0) {
    return { error: "That quiz could not be identified." };
  }

  const answers: Record<string, string | string[]> = {};

  for (const [field, value] of formData.entries()) {
    if (!field.startsWith("type.")) {
      continue;
    }

    const key = field.slice("type.".length);

    answers[key] =
      String(value) === "multiple"
        ? formData.getAll(key).map(String)
        : String(formData.get(key) ?? "");
  }

  let result: QuizResult;

  try {
    result = await submitQuizAttempt(quizId, answers);
  } catch (error) {
    if (error instanceof WordPressApiError) {
      if (error.code === "pcle_quiz_incomplete") {
        const data = error.data as { missing?: string[] } | undefined;

        return {
          error: "Some required questions have not been answered.",
          missing: data?.missing ?? [],
        };
      }

      if (error.code === "pcle_quiz_empty") {
        return { error: "This quiz has no questions yet. Ask your instructor." };
      }

      if (error.status === 403) {
        return { error: "You must be enrolled in this programme to sit this quiz." };
      }
    }

    return { error: "Your answers could not be submitted. Please try again." };
  }

  /*
   * Passing can be what makes a module completable, and the module and unit
   * pages both show that state. Neither knows this page exists, so the whole
   * layout is revalidated rather than trying to name them.
   */
  revalidatePath("/", "layout");

  return { result };
}

export { submitQuizAction };
export type { QuizActionState };
