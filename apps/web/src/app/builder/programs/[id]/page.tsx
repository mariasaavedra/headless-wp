import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import ActionForm from "@/components/builder/action-form";
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
        <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
          {decodeEntities(tree.title)}
        </h1>

        {isDraft && (
          <span className="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
            Draft
          </span>
        )}

        <ActionForm action={setStatusAction} className="ml-auto">
          <input type="hidden" name="id" value={tree.id} />
          <input
            type="hidden"
            name="status"
            value={isDraft ? "publish" : "draft"}
          />
          <button
            type="submit"
            className="rounded border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100"
          >
            {isDraft ? "Publish programme" : "Unpublish programme"}
          </button>
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
              <input
                id={`credit-${credit.jurisdiction}`}
                type="number"
                name={`credit_${credit.jurisdiction}`}
                defaultValue={credit.hours > 0 ? credit.hours : ""}
                min="0"
                step="0.25"
                placeholder="—"
                className="mt-1 w-24 rounded border border-zinc-300 px-2 py-1"
              />
            </span>
          ))}

          <button
            type="submit"
            className="rounded bg-zinc-950 px-3 py-1 text-white"
          >
            Save hours
          </button>

          <span className="w-full text-xs text-zinc-500">
            Approved hours per jurisdiction, in quarter-hour steps. Leave blank
            where the programme is not accredited. Certificates cannot be
            issued for a programme with no hours.
          </span>
        </ActionForm>
      </details>

      <div className="mt-8 rounded-lg border border-zinc-200 bg-white p-6">
        {units.length === 0 ? (
          <p className="text-zinc-600">
            Nothing in this programme yet. Add the first unit below.
          </p>
        ) : (
          <TreeView node={tree} />
        )}

        {/*
          The top-level add sits with the tree rather than in a toolbar: a unit
          belongs to this programme, and the whole point of building here is
          that you never pick a parent from a list — it is wherever you clicked.
        */}
        <details className="mt-6 border-t border-zinc-100 pt-4 text-sm">
          <summary className="cursor-pointer text-zinc-500 hover:text-zinc-900">
            + {NODE_LABELS.pcle_unit.singular}
          </summary>
          <ActionForm action={createNodeAction} className="mt-2 flex gap-2">
            <input type="hidden" name="type" value="pcle_unit" />
            <input type="hidden" name="parent_id" value={tree.id} />
            <input
              type="text"
              name="title"
              placeholder="New unit"
              aria-label="Title for the new unit"
              className="w-64 rounded border border-zinc-300 px-2 py-1"
            />
            <button
              type="submit"
              className="rounded bg-zinc-950 px-2 py-1 text-white"
            >
              Add
            </button>
          </ActionForm>
        </details>
      </div>

      <p className="mt-6 text-sm text-zinc-500">
        Click any title to write its body. Attaching files and embedding video
        are still done in WordPress for now.
      </p>
    </PageShell>
  );
}
