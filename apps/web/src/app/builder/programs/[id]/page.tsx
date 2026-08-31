import { redirect } from "next/navigation";
import { PlusIcon } from "lucide-react";

import { Badge } from "@pcle/ui/components/badge";
import { Button } from "@pcle/ui/components/button";
import { Card } from "@pcle/ui/components/card";
import { Input } from "@pcle/ui/components/input";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import ActionForm from "@/components/builder/action-form";
import EditableTitle from "@/components/builder/editable-title";
import { NODE_LABELS } from "@/components/builder/node-labels";
import TreeView from "@/components/builder/tree-view";
import PageShell from "@/components/page-shell";
import {
  createNodeAction,
  saveCreditsAction,
  setStatusAction,
} from "@/app/actions/authoring";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { TreeNode } from "@/lib/types";
import { getProgramTree } from "@/lib/wordpress";

export default async function BuilderProgramPage({
  params,
}: PageProps<"/builder/programs/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let tree: TreeNode;

  try {
    tree = await getProgramTree(Number(id));
  } catch (error) {
    return renderAccessError(error, {
      title: "You do not have access to the builder",
      detail:
        "Building courses is limited to instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  const isDraft = tree.status !== "publish";
  const units = tree.children.filter((child) => child.type === "pcle_unit");

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "Build", href: "/builder" },
          { label: tree.title },
        ]}
      />

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <EditableTitle nodeId={tree.id} title={decodeEntities(tree.title)} />

        {isDraft && (
          <Badge className="bg-amber-100 text-amber-800">Draft</Badge>
        )}

        <ActionForm action={setStatusAction} className="ml-auto">
          <input type="hidden" name="id" value={tree.id} />
          <input
            type="hidden"
            name="status"
            value={isDraft ? "publish" : "draft"}
          />
          <Button type="submit" variant="outline">
            {isDraft ? "Publish programme" : "Unpublish programme"}
          </Button>
        </ActionForm>
      </div>

      {/*
        Hours are entered from the accreditation paperwork, not calculated —
        and the two figures are never added together: an attorney admitted in
        both states reports the same seat time to each bar.
      */}
      <details className="mt-4 text-sm">
        <summary className="cursor-pointer text-zinc-500 hover:text-zinc-900">
          {tree.credits
            ?.map((credit) =>
              credit.hours > 0
                ? `${credit.label}: ${credit.hours} h`
                : `${credit.label}: not accredited`
            )
            .join(" · ")}
        </summary>

        <ActionForm
          action={saveCreditsAction}
          className="mt-3 flex flex-wrap items-end gap-4"
        >
          <input type="hidden" name="id" value={tree.id} />

          {tree.credits?.map((credit) => (
            <span key={credit.jurisdiction}>
              <label
                htmlFor={`credit-${credit.jurisdiction}`}
                className="block text-xs font-medium text-zinc-600"
              >
                {credit.label}
              </label>
              <Input
                id={`credit-${credit.jurisdiction}`}
                type="number"
                name={`credit_${credit.jurisdiction}`}
                defaultValue={credit.hours > 0 ? credit.hours : ""}
                min="0"
                step="0.25"
                placeholder="—"
                className="mt-1 w-24"
              />
            </span>
          ))}

          <Button type="submit">Save hours</Button>

          <span className="w-full text-xs text-zinc-500">
            Approved hours per jurisdiction, in quarter-hour steps. Leave blank
            where the programme is not accredited. Certificates cannot be
            issued for a programme with no hours.
          </span>
        </ActionForm>
      </details>

      <Card className="mt-8 p-6">
        {units.length === 0 ? (
          <p className="text-zinc-600">
            Nothing in this programme yet. A unit is a stage of the course —
            add one to start.
          </p>
        ) : (
          <TreeView node={tree} />
        )}

        {/*
          The top-level add sits with the tree rather than in a toolbar: a unit
          belongs to this programme, and the whole point of building here is
          that you never pick a parent from a list — it is wherever you clicked.
        */}
        {/*
          One click, named for you. Asking for a title before the thing exists
          was a question with an obvious answer, and it stood between the
          instructor and the only action this page is for.
        */}
        <ActionForm
          action={createNodeAction}
          className="mt-6 border-t border-zinc-100 pt-4"
        >
          <input type="hidden" name="type" value="pcle_unit" />
          <input type="hidden" name="parent_id" value={tree.id} />
          <input type="hidden" name="title" value="New unit" />
          <Button type="submit" variant="outline">
            <PlusIcon className="size-4" />
            Add {NODE_LABELS.pcle_unit.singular.toLowerCase()}
          </Button>
        </ActionForm>
      </Card>

      <p className="mt-6 text-sm text-zinc-500">
        Click any title to write its body. Attaching files and embedding video
        are still done in WordPress for now.
      </p>
    </PageShell>
  );
}
