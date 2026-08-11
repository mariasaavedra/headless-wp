import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import CompleteToggle from "@/components/complete-toggle";
import PageShell from "@/components/page-shell";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { ModuleDetail, ModuleResource } from "@/lib/types";
import { getModule } from "@/lib/wordpress";

function ResourceList({
  title,
  description,
  resources,
}: {
  title: string;
  description: string;
  resources: ModuleResource[];
}) {
  if (resources.length === 0) {
    return null;
  }

  return (
    <section className="mt-10">
      <h2 className="text-xl font-semibold text-zinc-950">{title}</h2>
      <p className="mt-1 text-sm text-zinc-500">{description}</p>

      <div className="mt-4 space-y-4">
        {resources.map((resource) => (
          <article
            key={resource.id}
            className="rounded-lg border border-zinc-200 bg-white p-6"
          >
            <h3 className="text-lg font-medium text-zinc-900">
              {decodeEntities(resource.title)}
            </h3>

            <WpContent html={resource.content} className="mt-3" />
          </article>
        ))}
      </div>
    </section>
  );
}

export default async function ModulePage({
  params,
}: PageProps<"/modules/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let courseModule: ModuleDetail;

  try {
    courseModule = await getModule(Number(id));
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          ...(courseModule.program
            ? [
                {
                  label: courseModule.program.title,
                  href: `/programs/${courseModule.program.id}`,
                },
              ]
            : []),
          ...(courseModule.week
            ? [{ label: courseModule.week.title, href: `/weeks/${courseModule.week.id}` }]
            : []),
          { label: courseModule.title },
        ]}
      />

      <h1 className="mt-4 text-3xl font-semibold tracking-tight text-zinc-950">
        {decodeEntities(courseModule.title)}
      </h1>

      <div className="mt-6">
        <CompleteToggle moduleId={courseModule.id} completed={courseModule.completed} />
      </div>

      <WpContent html={courseModule.content} className="mt-8" />

      <ResourceList
        title="Practice scenarios"
        description="Work through these before the live session."
        resources={courseModule.scenarios}
      />

      <ResourceList
        title="Templates"
        description="Starting points you can adapt for a real filing."
        resources={courseModule.templates}
      />
    </PageShell>
  );
}
