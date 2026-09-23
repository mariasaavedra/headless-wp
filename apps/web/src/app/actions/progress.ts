"use server";

import { revalidatePath } from "next/cache";

import {
  setModuleCompletion,
  setParticipantModuleCompletion,
  WordPressApiError,
} from "@/lib/wordpress";

type ProgressActionState = {
  error?: string;
  /** Quizzes standing between the reader and completing this module. */
  blockedBy?: { id: number; title: string }[];
};

/**
 * Toggles completion of a module for the signed-in user — staff only now.
 * A participant's completions are marked for them from the cohort report.
 *
 * Driven by a plain form, so it works before any JavaScript has loaded. The
 * desired state is submitted rather than "flip whatever is stored", which
 * keeps a double submit idempotent.
 *
 * Authorisation is WordPress's call: the endpoint requires access to the
 * module's programme, so this action does not need to re-check enrollment —
 * it only has to report a refusal honestly.
 */
async function toggleModuleAction(
  _prevState: ProgressActionState,
  formData: FormData
): Promise<ProgressActionState> {
  const moduleId = Number(formData.get("module_id"));
  const completed = formData.get("completed") === "true";

  if (!Number.isInteger(moduleId) || moduleId <= 0) {
    return { error: "That module could not be identified." };
  }

  try {
    await setModuleCompletion(moduleId, completed);
  } catch (error) {
    /*
     * A required quiz that has not been passed is a refusal with a reason, not
     * a failure to save. Reporting it as "try again" would send the reader
     * round a loop that cannot succeed.
     */
    if (
      error instanceof WordPressApiError &&
      error.code === "pcle_marked_by_instructor"
    ) {
      return { error: "Your instructor marks modules complete." };
    }

    if (
      error instanceof WordPressApiError &&
      error.code === "pcle_quiz_required"
    ) {
      const data = error.data as
        | { quizzes?: { id: number; title: string }[] }
        | undefined;

      return {
        error: "You need to pass this module's quiz first.",
        blockedBy: data?.quizzes ?? [],
      };
    }

    return { error: "Your progress could not be saved. Please try again." };
  }

  // Progress appears on the module, its unit and its programme, and none of
  // those pages know the others' ids from here.
  revalidatePath("/", "layout");

  return {};
}

/**
 * An instructor marking — or unmarking — one participant's module.
 *
 * The plugin decides everything: that the reader may report on this
 * programme, that the person is enrolled in it, that the module belongs to
 * it, and that no required quiz is still unpassed. This only reports a
 * refusal in words an instructor can act on.
 */
async function markParticipantModuleAction(
  _prevState: ProgressActionState,
  formData: FormData
): Promise<ProgressActionState> {
  const programId = Number(formData.get("program_id"));
  const userId = Number(formData.get("user_id"));
  const moduleId = Number(formData.get("module_id"));
  const completed = formData.get("completed") === "true";

  if (![programId, userId, moduleId].every((n) => Number.isInteger(n) && n > 0)) {
    return { error: "That module could not be identified." };
  }

  try {
    await setParticipantModuleCompletion(programId, userId, moduleId, completed);
  } catch (error) {
    if (
      error instanceof WordPressApiError &&
      error.code === "pcle_quiz_required"
    ) {
      const data = error.data as
        | { quizzes?: { id: number; title: string }[] }
        | undefined;

      return {
        error: "They have not passed the quiz this module requires:",
        blockedBy: data?.quizzes ?? [],
      };
    }

    return { error: "That could not be saved. Please try again." };
  }

  // The participant's page, the cohort report's counts, and the
  // participant's own screens all read this.
  revalidatePath("/", "layout");

  return {};
}

export { markParticipantModuleAction, toggleModuleAction };
export type { ProgressActionState };
