import Link from "next/link";

import { ChevronDownIcon, ChevronUpIcon } from "lucide-react";

import { Badge } from "@pcle/ui/components/badge";
import { cn } from "@pcle/ui/lib/utils";
import { Button } from "@pcle/ui/components/button";

import ActionForm from "@/components/builder/action-form";
import CollapsibleNode from "@/components/builder/collapsible-node";
import { AddChildMenu, NodeMenu } from "@/components/builder/row-actions";
import { reorderAction } from "@/app/actions/authoring";
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
        className="text-zinc-600"
        aria-label={direction === -1 ? "Move up" : "Move down"}
      >
        {direction === -1 ? (
          <ChevronUpIcon className="size-4" />
        ) : (
          <ChevronDownIcon className="size-4" />
        )}
      </Button>
    </ActionForm>
  );
}

/**
 * How prominent a row is, by what it is.
 *
 * Every row used to render identically — same badge, same size — so the only
 * thing separating a unit from a template was how far right it sat, four
 * levels deep. Weight carries the hierarchy now, and indentation only
 * confirms it.
 */
const TITLE_STYLES: Record<NodeType, string> = {
  pcle_program: "text-base font-semibold text-zinc-950",
  pcle_unit: "text-base font-semibold text-zinc-900",
  pcle_module: "text-sm font-medium text-zinc-900",
  pcle_scenario: "text-sm text-zinc-600",
  pcle_quiz: "text-sm text-zinc-600",
  pcle_template: "text-sm text-zinc-600",
  pcle_event: "text-sm text-zinc-600",
};

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

  const hasChildren = node.children.length > 0;

  const row = (
    <div className="flex flex-wrap items-center gap-2 py-2">
      <Link
        href={`/builder/nodes/${node.id}`}
        className={cn("hover:underline", TITLE_STYLES[node.type])}
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

      {node.type === "pcle_quiz" && (
        <span className="text-xs text-zinc-500">
          {node.questions
            ? `${node.questions} ${node.questions === 1 ? "question" : "questions"}`
            : "no questions yet"}
          {node.gates_completion ? " · required to complete" : ""}
        </span>
      )}

      <span className="ml-auto flex items-center gap-1.5">
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

        <AddChildMenu parentId={node.id} childTypes={node.allowed_children} />

        <NodeMenu
          nodeId={node.id}
          title={title}
          isDraft={isDraft}
          descendants={descendants}
        />
      </span>
    </div>
  );

  return (
    <li className="border-t border-zinc-100 first:border-t-0">
      {hasChildren ? (
        /*
         * Units arrive folded. Anything deeper arrives open: having opened a
         * unit you have already asked to see inside it, and a second click
         * would only move the problem down a level.
         */
        <CollapsibleNode
          row={row}
          label={title}
          defaultOpen={node.type !== "pcle_unit"}
        >
          {children}
        </CollapsibleNode>
      ) : (
        <div className="flex items-start gap-1.5">
          {/* Keeps childless rows aligned with the ones that have a chevron. */}
          <span aria-hidden="true" className="size-5 shrink-0" />
          <div className="min-w-0 flex-1">{row}</div>
        </div>
      )}
    </li>
  );
}
