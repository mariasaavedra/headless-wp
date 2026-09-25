import Link from "next/link";
import { redirect } from "next/navigation";

import { Badge } from "@pcle/ui/components/badge";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import CompleteToggle from "@/components/complete-toggle";
import PageShell from "@/components/page-shell";
import PreviewBanner from "@/components/preview-banner";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import { isPreview, keepPreview } from "@/lib/preview";
import { decodeEntities } from "@/lib/html";
import type { ModuleDetail, ModuleResource, QuizSummary } from "@/lib/types";
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

/**
 * The module's quizzes.
 *
 * A link and a state, never the questions — a listing has no business
 * carrying anything answerable.
 */
function QuizList({
  quizzes,
  preview = false,
}: {
  quizzes: QuizSummary[];
  preview?: boolean;
}) {
  if (quizzes.length === 0) {
    return null;
  }

  return (
    <section className="mt-10">
      <h2 className="text-xl font-semibold text-zinc-950">Quizzes</h2>
      <p className="mt-1 text-sm text-zinc-500">
        Check what you have taken in before moving on.
      </p>

      <ul className="mt-4 space-y-3">
        {quizzes.map((quiz) => (
          <li key={quiz.id}>
            <Link
              href={keepPreview(`/quizzes/${quiz.id}`, preview)}
              className="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-zinc-300"
            >
              <span className="font-medium text-zinc-900">
                {decodeEntities(quiz.title)}
              </span>

              <span className="text-sm text-zinc-500">
                {quiz.questions}{" "}
                {quiz.questions === 1 ? "question" : "questions"}
              </span>

              {quiz.passed ? (
                <Badge className="ml-auto bg-emerald-100 text-emerald-800">
                  Passed
                </Badge>
              ) : quiz.required ? (
                <Badge className="ml-auto bg-amber-100 text-amber-800">
                  Required
                </Badge>
              ) : null}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

export default async function ModulePage({
  params,
  searchParams,
}: PageProps<"/modules/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;
  const preview = isPreview(await searchParams);

  let courseModule: ModuleDetail;

  try {
    courseModule = await getModule(Number(id), preview);
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      {preview && <PreviewBanner programmeId={courseModule.program?.id} />}

      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          // A webinar's programme and unit screens only lead back here.
          ...(courseModule.format === "webinar"
            ? []
            : courseModule.program
            ? [
                {
                  label: courseModule.program.title,
                  href: keepPreview(
                    `/programs/${courseModule.program.id}`,
                    preview
                  ),
                },
              ]
            : []),
          ...(courseModule.unit && courseModule.format !== "webinar"
            ? [
                {
                  label: courseModule.unit.title,
                  href: keepPreview(`/units/${courseModule.unit.id}`, preview),
                },
              ]
            : []),
          { label: courseModule.title },
        ]}
      />

      <h1 className="mt-4 text-3xl font-semibold tracking-tight text-zinc-950">
        {decodeEntities(courseModule.title)}
      </h1>

      <div className="mt-6">
        {courseModule.can_mark ? (
          <CompleteToggle
            moduleId={courseModule.id}
            completed={courseModule.completed}
          />
        ) : courseModule.completed ? (
          <Badge className="bg-emerald-100 text-emerald-800">✓ Completed</Badge>
        ) : (
          /*
           * Said, not left blank: without it a participant looks for the
           * button they used to have and concludes something is broken.
           */
          <p className="text-sm text-zinc-500">
            Your instructor marks this module complete once you have finished
            it.
          </p>
        )}
      </div>

      <WpContent html={courseModule.content} className="mt-8" />

      <ResourceList
        title="Practice scenarios"
        description="Work through these before the live session."
        resources={courseModule.scenarios}
      />

      <QuizList quizzes={courseModule.quizzes} preview={preview} />

      <ResourceList
        title="Templates"
        description="Starting points you can adapt for a real filing."
        resources={courseModule.templates}
      />
    </PageShell>
  );
}
