import { redirect } from "next/navigation";

import { Badge } from "@pcle/ui/components/badge";
import { Button } from "@pcle/ui/components/button";
import { Card, CardContent } from "@pcle/ui/components/card";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import PageShell from "@/components/page-shell";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { ProgramReport, ReportParticipant } from "@/lib/types";
import { getProgramReport } from "@/lib/wordpress";

/** A date the site recorded, or an honest admission that it did not. */
function RecordedDate({ value }: { value: string | null }) {
  if (!value) {
    return <span className="text-zinc-400">not recorded</span>;
  }

  return <>{value.slice(0, 10)}</>;
}

function ParticipantRow({ row }: { row: ReportParticipant }) {
  return (
    <tr className="border-t border-zinc-100 align-top">
      <td className="py-3 pr-4">
        <div className="font-medium text-zinc-900">{row.name}</div>
        <div className="text-xs text-zinc-500">{row.email}</div>
      </td>

      <td className="py-3 pr-4 text-sm text-zinc-600">
        <RecordedDate value={row.enrolled_at} />
      </td>

      <td className="py-3 pr-4 text-sm text-zinc-900">
        {row.completed} / {row.total}
        <span className="ml-2 text-zinc-500">({row.percent}%)</span>

        {/*
          Said on the row rather than in a footnote: it changes what the row
          can be used to claim.
        */}
        {row.undated > 0 && (
          <div className="text-xs text-zinc-500">
            {row.undated}{" "}
            {row.undated === 1 ? "completion" : "completions"} without a date
          </div>
        )}
      </td>

      <td className="py-3 pr-4 text-sm text-zinc-600">
        {row.attended} / {row.sessions}
      </td>

      <td className="py-3 pr-4 text-sm text-zinc-600">
        {row.quizzes_passed} / {row.quizzes}

        {row.required_outstanding > 0 && (
          <div className="text-xs text-amber-700">
            {row.required_outstanding} required{" "}
            {row.required_outstanding === 1 ? "quiz" : "quizzes"} outstanding
          </div>
        )}
      </td>

      <td className="py-3 text-sm">
        {row.finished ? (
          <Badge className="bg-emerald-100 text-emerald-800">Finished</Badge>
        ) : (
          <span className="text-zinc-500">In progress</span>
        )}
      </td>
    </tr>
  );
}

export default async function ProgramReportPage({
  params,
}: PageProps<"/reports/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let report: ProgramReport;

  try {
    report = await getProgramReport(Number(id));
  } catch (error) {
    return renderAccessError(error, {
      title: "You do not have access to this report",
      detail:
        "Cohort reports are limited to instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  const title = report.program ? decodeEntities(report.program.title) : "Report";

  return (
    <PageShell wide>
      <Breadcrumbs
        trail={[{ label: "Reports", href: "/reports" }, { label: title }]}
      />

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
          {title}
        </h1>

        <Button
          variant="outline"
          className="ml-auto"
          nativeButton={false}
          render={<a href={`/reports/${id}/csv`} />}
        >
          Download CSV
        </Button>
      </div>

      {/*
        The programme's approved hours belong to the programme, not to any
        participant, so they are stated once rather than repeated down a column.
      */}
      <p className="mt-2 text-sm text-zinc-500">
        {report.credits
          .map((credit) =>
            credit.hours > 0
              ? `${credit.label}: ${credit.hours} h`
              : `${credit.label}: not accredited`
          )
          .join(" · ")}
      </p>

      {report.participants.length === 0 ? (
        <p className="mt-8 text-zinc-600">
          Nobody is enrolled in this programme yet.
        </p>
      ) : (
        <Card className="mt-8 p-0">
          <CardContent className="overflow-x-auto p-6">
            <table className="w-full min-w-[44rem] text-left">
              <thead>
                <tr className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  <th className="pb-2 pr-4">Participant</th>
                  <th className="pb-2 pr-4">Enrolled</th>
                  <th className="pb-2 pr-4">Modules</th>
                  <th className="pb-2 pr-4">Sessions</th>
                  <th className="pb-2 pr-4">Quizzes</th>
                  <th className="pb-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {report.participants.map((row) => (
                  <ParticipantRow key={row.id} row={row} />
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

      <p className="mt-6 text-sm text-zinc-500">
        Records as they stand, not conclusions. Whether they support a credit
        claim is a judgement for the person filing it.
      </p>
    </PageShell>
  );
}
