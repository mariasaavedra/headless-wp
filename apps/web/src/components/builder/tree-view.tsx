import { NODE_LABELS } from "@/components/builder/node-labels";
import NodeRow from "@/components/builder/node-row";
import type { NodeType, TreeNode } from "@/lib/types";

/**
 * The children of a node, grouped by type.
 *
 * Grouping is not cosmetic: ordering is per type on the server — a week's
 * modules and its sessions are two independent lists — so the groups here are
 * the same units the reorder endpoint works in. Rendering them mixed would
 * make "move up" mean something the API cannot express.
 */
function childrenOfType(node: TreeNode, type: NodeType): TreeNode[] {
  return node.children.filter((child) => child.type === type);
}

export default function TreeView({ node }: { node: TreeNode }) {
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
          <h3 className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
            {NODE_LABELS[group.type].plural}
          </h3>

          <ul>
            {group.items.map((child) => (
              <NodeRow
                key={child.id}
                node={child}
                parentId={node.id}
                siblingIds={group.items.map((item) => item.id)}
              >
                <TreeView node={child} />
              </NodeRow>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
