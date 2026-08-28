"use client";

import { useActionState, useEffect, useRef, useState } from "react";
import { PencilIcon } from "lucide-react";

import { Button } from "@pcle/ui/components/button";
import { Input } from "@pcle/ui/components/input";

import { renameNodeAction } from "@/app/actions/authoring";

/**
 * A heading you can rename by clicking it.
 *
 * A programme is the one thing in the builder with nowhere else to be renamed:
 * everything under it links to a node page with a title field, and a programme
 * has only this screen. It had no rename at all, which went unnoticed while
 * every row carried its own — and became obvious once those went away.
 *
 * Editing is explicit on both ends: clicking opens it, and it closes on Save,
 * Cancel or Escape. Saving on blur would be fewer clicks and would eventually
 * rename a programme because somebody clicked the title and then clicked away.
 */
export default function EditableTitle({
  nodeId,
  title,
}: {
  nodeId: number;
  title: string;
}) {
  const [state, formAction, pending] = useActionState(renameNodeAction, {});
  const [editing, setEditing] = useState(false);
  const wasPending = useRef(false);

  // Close once the server has taken it. The action revalidates, so the heading
  // that comes back is the saved one rather than what was typed.
  useEffect(() => {
    if (wasPending.current && !pending && !state.error) {
      setEditing(false);
    }
    wasPending.current = pending;
  }, [pending, state.error]);

  if (!editing) {
    return (
      <div className="flex items-center gap-2">
        <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
          {title}
        </h1>

        <Button
          variant="ghost"
          size="icon-sm"
          onClick={() => setEditing(true)}
          aria-label={`Rename ${title}`}
          className="text-zinc-400 hover:text-zinc-900"
        >
          <PencilIcon className="size-4" />
        </Button>
      </div>
    );
  }

  return (
    <form action={formAction} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="id" value={nodeId} />

      <Input
        name="title"
        defaultValue={title}
        autoFocus
        aria-label="Programme name"
        className="h-11 w-96 max-w-full text-2xl font-semibold"
        onKeyDown={(event) => {
          if (event.key === "Escape") {
            setEditing(false);
          }
        }}
      />

      <Button type="submit" disabled={pending}>
        {pending ? "Saving…" : "Save"}
      </Button>

      <Button
        type="button"
        variant="ghost"
        onClick={() => setEditing(false)}
        disabled={pending}
      >
        Cancel
      </Button>

      {state.error && (
        <p role="alert" className="w-full text-sm text-red-700">
          {state.error}
        </p>
      )}
    </form>
  );
}
