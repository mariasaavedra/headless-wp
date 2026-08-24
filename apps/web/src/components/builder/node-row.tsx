import Link from "next/link";

import { Badge } from "@pcle/ui/components/badge";
import { Button } from "@pcle/ui/components/button";
import { Input } from "@pcle/ui/components/input";

import ActionForm from "@/components/builder/action-form";
import { NODE_BADGES, NODE_LABELS } from "@/components/builder/node-labels";
import {
  createNodeAction,
  deleteNodeAction,
  renameNodeAction,
  reorderAction,
  setStatusAction,
} from "@/app/actions/authoring";
import { decodeEntities } from "@/lib/html";
import type { NodeType, TreeNode } from "@/lib/types";

/**
 * Counts everything hanging off a node, so a delete can say what it will take.
 */
function countDescendants(node: TreeNode): number {
  return node.children.reduce(
    (total, child) => total + 1 + countDescendants(child),
    0
  );
}

/**
 * The ordering that results from moving one id one step in a direction.
 *
 * Computed here, on the server, and carried in the form. The reorder endpoint
 * wants the whole sibling list; the page already knows it, so there is no
 * reason to make the request twice.
 */
function reordered(ids: number[], id: number, direction: -1 | 1): number[] {
  const from = ids.indexOf(id);
  const to = from + direction;

  if (from < 0 || to < 0 || to >= ids.length) {
    return ids;
  }

  const next = [...ids];
  [next[from], next[to]] = [next[to], next[from]];
  return next;
}

function MoveButton({
  parentId,
  childType,
  siblingIds,
  id,
  direction,
}: {
  parentId: number;
  childType: NodeType;
  siblingIds: number[];
  id: number;
  direction: -1 | 1;
}) {
  const position = siblingIds.indexOf(id);
  const atEdge = direction === -1 ? position <= 0 : position >= siblingIds.length - 1;

  if (atEdge) {
    // Keep the space so rows do not jitter as things move.
    return <span aria-hidden="true" className="inline-block w-7" />;
  }

  return (
    <ActionForm action={reorderAction} className="inline">
      <input type="hidden" name="parent_id" value={parentId} />
      <input type="hidden" name="child_type" value={childType} />
      <input
        type="hidden"
        name="ids"
        value={reordered(siblingIds, id, direction).join(",")}
      />
      <Button
        type="submit"
        variant="outline"
        size="icon-sm"
        className="w-7 text-xs text-zinc-600"
        aria-label={direction === -1 ? "Move up" : "Move down"}
      >
        {direction === -1 ? "↑" : "↓"}
      </Button>
    </ActionForm>
  );
}

export default function NodeRow({
  node,
  parentId,
  siblingIds,
  children,
}: {
  node: TreeNode;
  parentId: number;
  siblingIds: number[];
  children?: React.ReactNode;
}) {
  const isDraft = node.status !== "publish";
  const descendants = countDescendants(node);
  const title = decodeEntities(node.title);

  return (
    <li className="border-t border-zinc-100 first:border-t-0">
      <div className="flex flex-wrap items-center gap-2 py-2">
        <Badge variant="secondary" className="uppercase tracking-wide">
          {NODE_BADGES[node.type]}
        </Badge>

        <Link
          href={`/builder/nodes/${node.id}`}
          className="font-medium text-zinc-900 hover:underline"
        >
          {title}
        </Link>

        {isDraft && (
          <Badge className="bg-amber-100 text-amber-800">Draft</Badge>
        )}

        {node.type === "pcle_event" && node.starts_at && (
          <span className="text-xs text-zinc-500">{node.starts_at.slice(0, 16).replace("T", " ")}</span>
        )}

        {node.type === "pcle_scenario" && !node.has_model_answer && (
          <span className="text-xs text-zinc-500">no model answer yet</span>
        )}

        <span className="ml-auto flex items-center gap-1">
          <MoveButton
            parentId={parentId}
            childType={node.type}
            siblingIds={siblingIds}
            id={node.id}
            direction={-1}
          />
          <MoveButton
            parentId={parentId}
            childType={node.type}
            siblingIds={siblingIds}
            id={node.id}
            direction={1}
          />

          <ActionForm action={setStatusAction} className="inline">
            <input type="hidden" name="id" value={node.id} />
            <input
              type="hidden"
              name="status"
              value={isDraft ? "publish" : "draft"}
            />
            <Button type="submit" variant="outline" size="sm" className="text-xs">
              {isDraft ? "Publish" : "Unpublish"}
            </Button>
          </ActionForm>
        </span>
      </div>

      <div className="flex flex-wrap gap-4 pb-2 text-xs">
        <details className="group">
          <summary className="cursor-pointer text-zinc-500 hover:text-zinc-900">
            Rename
          </summary>
          <ActionForm action={renameNodeAction} className="mt-2 flex gap-2">
            <input type="hidden" name="id" value={node.id} />
            <Input
              type="text"
              name="title"
              defaultValue={title}
              aria-label={`Title for ${title}`}
              className="w-64"
            />
            <Button type="submit">Save</Button>
          </ActionForm>
        </details>

        {node.allowed_children.map((childType) => (
          <details key={childType}>
            <summary className="cursor-pointer text-zinc-500 hover:text-zinc-900">
              + {NODE_LABELS[childType].singular}
            </summary>
            <ActionForm action={createNodeAction} className="mt-2 flex gap-2">
              <input type="hidden" name="type" value={childType} />
              <input type="hidden" name="parent_id" value={node.id} />
              <Input
                type="text"
                name="title"
                placeholder={`New ${NODE_LABELS[childType].singular.toLowerCase()}`}
                aria-label={`Title for the new ${NODE_LABELS[childType].singular.toLowerCase()}`}
                className="w-64"
              />
              <Button type="submit">Add</Button>
            </ActionForm>
          </details>
        ))}

        <details>
          <summary className="cursor-pointer text-zinc-500 hover:text-red-700">
            Delete
          </summary>
          <div className="mt-2 rounded border border-red-200 bg-red-50 p-3">
            {descendants > 0 ? (
              <p className="text-red-900">
                This also removes <strong>{descendants}</strong>{" "}
                {descendants === 1 ? "item" : "items"} inside it. This cannot be
                undone.
              </p>
            ) : (
              <p className="text-red-900">This cannot be undone.</p>
            )}

            <ActionForm action={deleteNodeAction} className="mt-2">
              <input type="hidden" name="id" value={node.id} />
              {/*
                The server refuses an unrequested cascade; confirming here is
                what turns it on, so a stray click cannot take a unit's
                contents with it.
              */}
              <input
                type="hidden"
                name="cascade"
                value={descendants > 0 ? "true" : "false"}
              />
              <Button
                type="submit"
                variant="destructive"
                className="bg-red-700 text-white hover:bg-red-800"
              >
                {descendants > 0
                  ? `Delete all ${descendants + 1} items`
                  : "Delete"}
              </Button>
            </ActionForm>
          </div>
        </details>
      </div>

      {children}
    </li>
  );
}
