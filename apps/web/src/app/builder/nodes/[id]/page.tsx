import Link from "next/link";
import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import ActionForm from "@/components/builder/action-form";
import { NODE_BADGES } from "@/components/builder/node-labels";
import PageShell from "@/components/page-shell";
import WpContent from "@/components/wp-content";
import { saveBodyAction } from "@/app/actions/authoring";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { NodeDetail } from "@/lib/types";
import { getNode } from "@/lib/wordpress";

/**
 * The authoring syntax, stated where it is used.
 *
 * There is deliberately no separate help page: an author should not have to
 * remember this, and the plugin's existing documentation problem is that the
 * one thing they did have to remember — the model-answer shortcode — was
 * documented in a markdown file nobody opens.
 */
function SyntaxHelp() {
  return (
    <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs text-zinc-500">
      <dt className="font-mono">## Heading</dt>
      <dd>a section heading</dd>
      <dt className="font-mono">### Heading</dt>
      <dd>a smaller heading</dd>
      <dt className="font-mono">- item</dt>
      <dd>a bulleted list</dd>
      <dt className="font-mono">&gt; text</dt>
      <dd>a quotation</dd>
      <dt className="font-mono">**bold** *italic*</dt>
      <dd>emphasis</dd>
      <dt className="font-mono">[label](https://…)</dt>
      <dd>a link</dd>
    </dl>
  );
}

export default async function BuilderNodePage({
  params,
}: PageProps<"/builder/nodes/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let node: NodeDetail;

  try {
    node = await getNode(Number(id));
  } catch (error) {
    return renderAccessError(error, {
      title: "You do not have access to the builder",
      detail:
        "Building courses is limited to instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  const title = decodeEntities(node.title);

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "Build", href: "/builder" },
          ...(node.program
            ? [
                {
                  label: node.program.title,
                  href: `/builder/programs/${node.program.id}`,
                },
              ]
            : []),
          { label: node.title },
        ]}
      />

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium uppercase tracking-wide text-zinc-500">
          {NODE_BADGES[node.type]}
        </span>

        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">
          {title}
        </h1>

        {node.status !== "publish" && (
          <span className="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
            Draft
          </span>
        )}
      </div>

      {node.editable ? (
        <ActionForm
          action={saveBodyAction}
          className="mt-8 rounded-lg border border-zinc-200 bg-white p-6"
        >
          <input type="hidden" name="id" value={node.id} />

          <label
            htmlFor="node-title"
            className="block text-sm font-medium text-zinc-700"
          >
            Title
          </label>
          <input
            id="node-title"
            type="text"
            name="title"
            defaultValue={title}
            className="mt-1 w-full rounded border border-zinc-300 px-3 py-2"
          />

          <label
            htmlFor="node-body"
            className="mt-6 block text-sm font-medium text-zinc-700"
          >
            Body
          </label>
          <textarea
            id="node-body"
            name="body"
            rows={18}
            defaultValue={node.body}
            spellCheck
            className="mt-1 w-full rounded border border-zinc-300 px-3 py-2 font-mono text-sm leading-relaxed"
          />

          <SyntaxHelp />

          <button
            type="submit"
            className="mt-6 rounded bg-zinc-950 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800"
          >
            Save
          </button>
        </ActionForm>
      ) : (
        <div className="mt-8 rounded-lg border border-amber-200 bg-amber-50 p-6">
          <h2 className="font-medium text-amber-900">
            This was written in WordPress
          </h2>
          <p className="mt-2 text-sm text-amber-900">
            It contains formatting the builder cannot represent — an image, an
            embed, or content from before the builder existed. Editing it here
            would mean rewriting it into something simpler, so the builder
            leaves it alone. Open it in WordPress to change it.
          </p>
        </div>
      )}

      <section className="mt-10">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-400">
          As a participant sees it
        </h2>

        {/*
          The preview is the server's own rendering of the stored content — the
          same call the participant screens make. Rendering it a second way
          here would make it a preview of something else the moment the two
          drift.
        */}
        <div className="mt-3 rounded-lg border border-zinc-200 bg-white p-6">
          {node.rendered.trim() ? (
            <WpContent html={node.rendered} />
          ) : (
            <p className="text-sm text-zinc-500">Nothing written yet.</p>
          )}
        </div>
      </section>

      {node.program && (
        <Link
          href={`/builder/programs/${node.program.id}`}
          className="mt-8 inline-block text-sm text-zinc-500 underline hover:text-zinc-900"
        >
          Back to the programme
        </Link>
      )}
    </PageShell>
  );
}
