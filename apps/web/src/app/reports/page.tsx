import Link from "next/link";
import { redirect } from "next/navigation";

import { Badge } from "@pcle/ui/components/badge";
import { Card, CardContent } from "@pcle/ui/components/card";

import { renderAccessError } from "@/components/access-error";
import PageShell from "@/components/page-shell";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { AuthoringProgram } from "@/lib/types";
import { getAuthoringPrograms } from "@/lib/wordpress";

/**
 * Which cohort to report on.
 *
 * The list comes from the authoring endpoint because it answers the same
 * question the report guard asks — which programmes may this person work on —
 * and having two lists that could disagree about that is worse than reusing
 * one that cannot.
 */
export default async function ReportsPage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let programs: AuthoringProgram[];

  try {
    ({ programs } = await getAuthoringPrograms());
  } catch (error) {
    return renderAccessError(error, {
      title: "You do not have access to reports",
      detail:
        "Cohort reports are limited to instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  return (
    <PageShell>
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
        Reports
      </h1>
      <p className="mt-2 text-zinc-600">
        Who is enrolled, what they have completed, and what is outstanding.
      </p>

      {programs.length === 0 ? (
        <p className="mt-8 text-zinc-600">There are no programmes yet.</p>
      ) : (
        <ul className="mt-8 space-y-4">
          {programs.map((program) => (
            <li key={program.id}>
              <Link
                href={`/reports/${program.id}`}
                className="block transition hover:shadow-sm"
              >
                <Card className="p-6 hover:ring-foreground/20">
                  <CardContent className="p-0">
                    <div className="flex flex-wrap items-center gap-3">
                      <h2 className="text-xl font-medium text-zinc-950">
                        {decodeEntities(program.title)}
                      </h2>

                      {program.status !== "publish" && (
                        <Badge className="bg-amber-100 text-amber-800">
                          Draft
                        </Badge>
                      )}
                    </div>

                    <p className="mt-2 text-sm text-zinc-500">
                      {program.enrollees}{" "}
                      {program.enrollees === 1 ? "participant" : "participants"}{" "}
                      · {program.modules}{" "}
                      {program.modules === 1 ? "module" : "modules"}
                    </p>
                  </CardContent>
                </Card>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </PageShell>
  );
}
