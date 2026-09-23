import { redirect } from "next/navigation";

import { Badge } from "@pcle/ui/components/badge";
import { Card, CardContent } from "@pcle/ui/components/card";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import MarkParticipantModule from "@/components/mark-participant-module";
import PageShell from "@/components/page-shell";
import ProgressBar from "@/components/progress-bar";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { ParticipantModule, ParticipantProgress } from "@/lib/types";
import { getParticipantProgress } from "@/lib/wordpress";

/**
 * How a completion came to be recorded — a credit record should be able to
 * say whose word it rests on.
 */
function CompletionNote({ module }: { module: ParticipantModule }) {
  if (!module.completed) {
    return module.blockers.length > 0 ? (
      <span className="text-amber-700">
        Required quiz not passed yet:{" "}
        {module.blockers.map((quiz) => decodeEntities(quiz.title)).join(", ")}
      </span>
    ) : null;
  }

  const when = module.completed_at ? module.completed_at.slice(0, 10) : null;
  const by = module.marked_by
    ? `marked by ${module.marked_by.name ?? "a former account"}`
    : "recorded by the participant";

  return (
    <span className="text-zinc-500">
      {when ? `${when}, ${by}` : `No date recorded, ${by}`}
    </span>
  );
}

export default async function ParticipantProgressPage({
  params,
}: PageProps<"/reports/[id]/participants/[userId]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id, userId } = await params;
  const programId = Number(id);

  let data: ParticipantProgress;

  try {
    data = await getParticipantProgress(programId, Number(userId));
  } catch (error) {
    return renderAccessError(error, {
      title: "You cannot see this participant",
      detail:
        "Marking progress is limited to instructors and administrators, and only for people enrolled in the programme.",
    });
  }

  const programTitle = data.program
    ? decodeEntities(data.program.title)
    : "Report";

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "Reports", href: "/reports" },
          { label: programTitle, href: `/reports/${programId}` },
          { label: data.participant.name },
        ]}
      />

      <h1 className="mt-4 text-3xl font-semibold tracking-tight text-zinc-950">
        {data.participant.name}
      </h1>
      <p className="mt-1 text-sm text-zinc-500">{data.participant.email}</p>

      <div className="mt-6">
        <ProgressBar progress={data.progress} label="Programme progress" />
      </div>

      <p className="mt-6 text-sm text-zinc-600">
        Participants no longer mark modules themselves. Mark each one here once
        they have finished it; the record keeps who marked it and when.
      </p>

      {data.units.length === 0 ? (
        <p className="mt-8 text-zinc-600">
          This programme has no published units yet.
        </p>
      ) : (
        data.units.map((unit) => (
          <Card key={unit.id} className="mt-6 p-6">
            <CardContent className="p-0">
              <h2 className="text-lg font-semibold text-zinc-900">
                {decodeEntities(unit.title)}
              </h2>

              {unit.modules.length === 0 ? (
                <p className="mt-2 text-sm text-zinc-500">No modules.</p>
              ) : (
                <ul className="mt-3 divide-y divide-zinc-100">
                  {unit.modules.map((module) => (
                    <li
                      key={module.id}
                      className="flex flex-wrap items-start gap-3 py-3"
                    >
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-medium text-zinc-900">
                            {decodeEntities(module.title)}
                          </span>
                          {module.completed && (
                            <Badge className="bg-emerald-100 text-emerald-800">
                              Completed
                            </Badge>
                          )}
                        </div>
                        <div className="mt-0.5 text-xs">
                          <CompletionNote module={module} />
                        </div>
                      </div>

                      <MarkParticipantModule
                        programId={programId}
                        userId={data.participant.id}
                        moduleId={module.id}
                        moduleTitle={decodeEntities(module.title)}
                        completed={module.completed}
                      />
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        ))
      )}
    </PageShell>
  );
}
