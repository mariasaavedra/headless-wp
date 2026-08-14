"use server";

import { revalidatePath } from "next/cache";

import { setModuleCompletion } from "@/lib/wordpress";

type ProgressActionState = {
  error?: string;
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
  } catch {
    return { error: "Your progress could not be saved. Please try again." };
  }

  // Progress appears on the module, its unit and its programme, and none of
  // those pages know the others' ids from here.
  revalidatePath("/", "layout");

  return {};
}

export { toggleModuleAction };
export type { ProgressActionState };
