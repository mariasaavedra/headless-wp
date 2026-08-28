"use server";

import { revalidatePath } from "next/cache";

import { setModuleCompletion, WordPressApiError } from "@/lib/wordpress";

type ProgressActionState = {
  error?: string;
  /** Quizzes standing between the reader and completing this module. */
  blockedBy?: { id: number; title: string }[];
};

/**
 * Toggles completion of a module for the signed-in user.
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

export { toggleModuleAction };
export type { ProgressActionState };
