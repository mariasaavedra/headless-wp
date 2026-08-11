"use client";

import { useActionState } from "react";

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

      <button
        type="submit"
        disabled={pending}
        className={
          completed
            ? "rounded border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-50"
            : "rounded bg-zinc-950 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 disabled:opacity-50"
        }
      >
        {pending
          ? "Saving…"
          : completed
            ? "✓ Completed — undo"
            : "Mark as complete"}
      </button>

      {state.error && (
        <p role="alert" className="text-sm text-red-600">
          {state.error}
        </p>
      )}
    </form>
  );
}
