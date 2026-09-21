"use server";

import { revalidatePath } from "next/cache";

import type { EnrollmentResult } from "@/lib/types";
import {
  enrollByEmail,
  unenrollParticipant,
  WordPressApiError,
} from "@/lib/wordpress";

type EnrollActionState = {
  error?: string;
  /** The plugin's account of what happened to each address. */
  result?: EnrollmentResult;
};

type RemoveActionState = {
  error?: string;
};

/** Turns a refusal into words a reader can act on. */
function refusalMessage(error: unknown, fallback: string): string {
  if (error instanceof WordPressApiError) {
    if (error.status === 403) {
      return "You may not manage this programme's participants.";
    }
    if (error.status === 404) {
      return "That programme could not be found.";
    }
  }

  return fallback;
}

/**
 * Enrols whatever addresses were pasted into the box.
 *
 * Authorisation is WordPress's call — the endpoint asks the same question the
 * report does — so this action only has to report a refusal honestly. The
 * per-address outcomes come back with the response rather than being inferred
 * from the counts, because "nine of twelve" is not an answer to "which nine".
 */
async function enrollAction(
  _prevState: EnrollActionState,
  formData: FormData
): Promise<EnrollActionState> {
  const programId = Number(formData.get("program_id"));
  const emails = String(formData.get("emails") ?? "").trim();
  const create = formData.get("create") === "on";

  if (!Number.isInteger(programId) || programId <= 0) {
    return { error: "That programme could not be identified." };
  }

  if (emails === "") {
    return { error: "Paste at least one email address." };
  }

  let result: EnrollmentResult;

  try {
    result = await enrollByEmail(programId, emails, create);
  } catch (error) {
    return {
      error: refusalMessage(
        error,
        "Those participants could not be enrolled. Please try again."
      ),
    };
  }

  revalidatePath(`/reports/${programId}`);

  return { result };
}

/**
 * Removes one participant from a programme.
 *
 * The enrollment goes; the account and everything it has recorded stay. A
 * mistake here costs a row, not a term's work — which is why it needs no
 * confirmation step.
 */
async function removeParticipantAction(
  _prevState: RemoveActionState,
  formData: FormData
): Promise<RemoveActionState> {
  const programId = Number(formData.get("program_id"));
  const userId = Number(formData.get("user_id"));

  if (!Number.isInteger(programId) || !Number.isInteger(userId)) {
    return { error: "That participant could not be identified." };
  }

  try {
    await unenrollParticipant(programId, userId);
  } catch (error) {
    return {
      error: refusalMessage(
        error,
        "That participant could not be removed. Please try again."
      ),
    };
  }

  revalidatePath(`/reports/${programId}`);

  return {};
}

export { enrollAction, removeParticipantAction };
export type { EnrollActionState, RemoveActionState };
