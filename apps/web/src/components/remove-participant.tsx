"use client";

import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";

import { removeParticipantAction } from "@/app/actions/enrollment";

/**
 * Removes one participant from a programme.
 *
 * No confirmation dialog, deliberately: this deletes an enrollment row and
 * nothing else — the account, the progress, the attendance and the quiz
 * attempts all survive — so the cost of a misclick is re-pasting one address.
 * A dialog here would be the same number of clicks as the undo.
 */
export default function RemoveParticipant({
  programId,
  userId,
  name,
}: {
  programId: number;
  userId: number;
  name: string;
}) {
  const [state, formAction, pending] = useActionState(
    removeParticipantAction,
    {}
  );

  return (
    <form action={formAction}>
      <input type="hidden" name="program_id" value={programId} />
      <input type="hidden" name="user_id" value={userId} />

      <Button
        type="submit"
        variant="outline"
        disabled={pending}
        aria-label={`Remove ${name} from this programme`}
      >
        {pending ? "Removing…" : "Remove"}
      </Button>

      {state.error && (
        <p role="alert" className="mt-1 text-xs text-red-600">
          {state.error}
        </p>
      )}
    </form>
  );
}
