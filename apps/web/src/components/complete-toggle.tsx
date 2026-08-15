"use client";

import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";

import { toggleModuleAction } from "@/app/actions/progress";

/**
 * "Mark as complete" / "Mark as not complete" for a module.
 *
 * A form rather than an onClick handler, so the control works without
 * JavaScript; useActionState only adds the pending state and the error.
 */
export default function CompleteToggle({
  moduleId,
  completed,
}: {
  moduleId: number;
  completed: boolean;
}) {
  const [state, formAction, pending] = useActionState(toggleModuleAction, {});

  return (
    <form action={formAction} className="flex flex-wrap items-center gap-3">
      <input type="hidden" name="module_id" value={moduleId} />
      <input type="hidden" name="completed" value={String(!completed)} />

      <Button
        type="submit"
        disabled={pending}
        variant={completed ? "outline" : "default"}
        className={completed ? "border-emerald-600 text-emerald-700 hover:bg-emerald-50" : undefined}
      >
        {pending
          ? "Saving…"
          : completed
            ? "✓ Completed — undo"
            : "Mark as complete"}
      </Button>

      {state.error && (
        <p role="alert" className="text-sm text-red-600">
          {state.error}
        </p>
      )}
    </form>
  );
}
