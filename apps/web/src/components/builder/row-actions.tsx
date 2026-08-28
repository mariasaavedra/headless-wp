"use client";

import { useActionState, useState } from "react";
import { ChevronDownIcon, MoreHorizontalIcon } from "lucide-react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@pcle/ui/components/alert-dialog";
import { buttonVariants } from "@pcle/ui/components/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@pcle/ui/components/dropdown-menu";

import {
  createNodeAction,
  deleteNodeAction,
  setStatusAction,
} from "@/app/actions/authoring";
import { NODE_LABELS } from "@/components/builder/node-labels";
import type { NodeType } from "@/lib/types";

/**
 * Every mutation here is still a form posting to a server action.
 *
 * The menus are the only part that needs JavaScript, and what they replaced —
 * nine permanent controls on every row — was the actual problem: a programme
 * of eighteen rows rendered a hundred and thirty-two of them at once. Wrapping
 * the same forms in a menu costs a click and gives the row back to the thing
 * it is about.
 *
 * The forms live inside the menu content rather than around it because the
 * content is portalled: a form outside would not contain these buttons in the
 * DOM, and nothing would submit.
 */

const TRIGGER = buttonVariants({ variant: "outline", size: "sm" });

/** Adds one child of a chosen type, named for the reader to rename later. */
export function AddChildMenu({
  parentId,
  childTypes,
}: {
  parentId: number;
  childTypes: NodeType[];
}) {
  const [state, formAction] = useActionState(createNodeAction, {});

  if (childTypes.length === 0) {
    return null;
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger className={TRIGGER}>
        Add
        <ChevronDownIcon className="size-3.5" />
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end">
        {childTypes.map((childType) => (
          <form key={childType} action={formAction}>
            <input type="hidden" name="type" value={childType} />
            <input type="hidden" name="parent_id" value={parentId} />
            {/*
              Named on creation rather than asked for first. The old row made
              you type a title into a disclosure before anything existed; this
              creates the thing and lets it be renamed where it is edited,
              which is also how adding a quiz question now works.
            */}
            <input
              type="hidden"
              name="title"
              value={`New ${NODE_LABELS[childType].singular.toLowerCase()}`}
            />
            <DropdownMenuItem
              nativeButton
              render={<button type="submit" className="w-full" />}
            >
              {NODE_LABELS[childType].singular}
            </DropdownMenuItem>
          </form>
        ))}

        {state.error && (
          <p role="alert" className="px-2 py-1.5 text-xs text-red-700">
            {state.error}
          </p>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** Publishing and deleting, off the row and behind one control. */
export function NodeMenu({
  nodeId,
  title,
  isDraft,
  descendants,
}: {
  nodeId: number;
  title: string;
  isDraft: boolean;
  descendants: number;
}) {
  const [statusState, statusAction] = useActionState(setStatusAction, {});
  const [deleteState, deleteAction] = useActionState(deleteNodeAction, {});
  const [confirming, setConfirming] = useState(false);

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger
          className={TRIGGER}
          aria-label={`Actions for ${title}`}
        >
          <MoreHorizontalIcon className="size-4" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end">
          <form action={statusAction}>
            <input type="hidden" name="id" value={nodeId} />
            <input
              type="hidden"
              name="status"
              value={isDraft ? "publish" : "draft"}
            />
            <DropdownMenuItem
              nativeButton
              render={<button type="submit" className="w-full" />}
            >
              {isDraft ? "Publish" : "Return to draft"}
            </DropdownMenuItem>
          </form>

          {/*
            The dialog is a sibling of the menu, not a child: opening it from
            inside would race the menu closing itself.
          */}
          <DropdownMenuItem
            variant="destructive"
            onClick={() => setConfirming(true)}
          >
            Delete
          </DropdownMenuItem>

          {statusState.error && (
            <p role="alert" className="px-2 py-1.5 text-xs text-red-700">
              {statusState.error}
            </p>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      <AlertDialog open={confirming} onOpenChange={setConfirming}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete “{title}”?</AlertDialogTitle>
            <AlertDialogDescription>
              {descendants > 0
                ? `This also removes the ${descendants} ${
                    descendants === 1 ? "item" : "items"
                  } inside it. This cannot be undone.`
                : "This cannot be undone."}
            </AlertDialogDescription>
          </AlertDialogHeader>

          {deleteState.error && (
            <p role="alert" className="text-sm text-red-700">
              {deleteState.error}
            </p>
          )}

          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>

            <form action={deleteAction}>
              <input type="hidden" name="id" value={nodeId} />
              {/*
                The server refuses an unrequested cascade; saying yes here is
                what turns it on, so a stray click cannot take a unit's
                contents with it.
              */}
              <input
                type="hidden"
                name="cascade"
                value={descendants > 0 ? "true" : "false"}
              />
              <AlertDialogAction
                type="submit"
                className="bg-red-700 text-white hover:bg-red-800"
              >
                {descendants > 0
                  ? `Delete all ${descendants + 1} items`
                  : "Delete"}
              </AlertDialogAction>
            </form>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
