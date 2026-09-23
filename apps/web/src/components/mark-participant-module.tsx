"use client";

import Link from "next/link";
import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";

import { markParticipantModuleAction } from "@/app/actions/progress";

/**
 * An instructor's "mark complete" for one participant's module.
 *
 * A form, like the participant's own toggle was, so it works before any
 * JavaScript has loaded; the desired state is submitted rather than "flip
 * it", so a double submit is harmless.
 */
export default function MarkParticipantModule({
  programId,
  userId,
  moduleId,
  moduleTitle,
  completed,
}: {
  programId: number;
  userId: number;
  moduleId: number;
  moduleTitle: string;
  completed: boolean;
}) {
  const [state, formAction, pending] = useActionState(
    markParticipantModuleAction,
    {}
  );

  return (
    <form action={formAction} className="flex max-w-60 shrink-0 flex-col items-end gap-1">
      <input type="hidden" name="program_id" value={programId} />
      <input type="hidden" name="user_id" value={userId} />
      <input type="hidden" name="module_id" value={moduleId} />
      <input type="hidden" name="completed" value={String(!completed)} />

      <Button
        type="submit"
        size="sm"
        disabled={pending}
        variant={completed ? "outline" : "default"}
        aria-label={`${completed ? "Unmark" : "Mark"} ${moduleTitle} ${completed ? "as not complete" : "complete"}`}
      >
        {pending ? "Saving…" : completed ? "Undo" : "Mark complete"}
      </Button>

      {state.error && (
        <p role="alert" className="text-right text-xs text-red-600">
          {state.error}
          {state.blockedBy?.map((quiz) => (
            <Link
              key={quiz.id}
              href={`/quizzes/${quiz.id}`}
              className="ml-1 underline"
            >
              {quiz.title}
            </Link>
          ))}
        </p>
      )}
    </form>
  );
}
