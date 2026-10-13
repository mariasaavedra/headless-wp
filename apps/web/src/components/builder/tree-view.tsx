import { decodeEntities } from "@/lib/html";
import { NODE_LABELS } from "@/components/builder/node-labels";
import NodeRow from "@/components/builder/node-row";
import SortableGroup from "@/components/builder/sortable-group";
import type { Swatch } from "@/lib/colours";
import type { NodeType, TreeNode } from "@/lib/types";

/**
 * The children of a node, grouped by type.
 *
 * Grouping is not cosmetic: ordering is per type on the server — a unit's
 * modules and its sessions are two independent lists — so the groups here are
 * the same units the reorder endpoint works in. Rendering them mixed would
 * make "move up" mean something the API cannot express.
 */
function childrenOfType(node: TreeNode, type: NodeType): TreeNode[] {
  return node.children.filter((child) => child.type === type);
}

/** "programme", "unit": whose colour an item is showing, in words. */
function owner(type: NodeType): string {
  return NODE_LABELS[type].singular.toLowerCase();
}

/**
 * `inherited` is the colour this node's children show unless they have their
 * own, and `inheritedFrom` whose colour that is. Worked out here, walking down
 * the tree the page already has, rather than asked of the server per row.
 */
export default function TreeView({
  node,
  inherited,
  inheritedFrom,
}: {
  node: TreeNode;
  inherited: Swatch | null;
  inheritedFrom: string;
}) {
  const groups = node.allowed_children
    .map((type) => ({ type, items: childrenOfType(node, type) }))
    .filter((group) => group.items.length > 0);

  if (groups.length === 0) {
    return null;
  }

  return (
    <div className="ml-4 border-l border-zinc-200 pl-4">
      {groups.map((group) => (
        <section key={group.type} className="mt-3">
          <h3 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
            {/* The kind's colour from the Colours screen, as on its badge. */}
            {group.type !== "pcle_program" && (
              <span
                aria-hidden="true"
                className="size-2 rounded-sm"
                style={{ backgroundColor: `var(--kind-${group.type}-fg)` }}
              />
            )}
            {NODE_LABELS[group.type].plural}
          </h3>

          <SortableGroup
            parentId={node.id}
            childType={group.type}
            rows={group.items.map((child) => ({
              id: child.id,
              label: decodeEntities(child.title),
              content: (
                <NodeRow
                  node={child}
                  parentId={node.id}
                  siblingIds={group.items.map((item) => item.id)}
                  inherited={inherited}
                  inheritedFrom={inheritedFrom}
                >
                  <TreeView
                    node={child}
                    inherited={child.color ?? inherited}
                    // With nothing coloured above, "Use the unit's colour"
                    // names the parent, so the choice still reads.
                    inheritedFrom={
                      child.color || !inherited ? owner(child.type) : inheritedFrom
                    }
                  />
                </NodeRow>
              ),
            }))}
          />
        </section>
      ))}
    </div>
  );
}
