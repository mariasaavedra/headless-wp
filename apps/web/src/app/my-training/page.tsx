import Link from "next/link";
import { redirect } from "next/navigation";

import { Badge } from "@pcle/ui/components/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@pcle/ui/components/card";

import { renderAccessError } from "@/components/access-error";
import PageShell from "@/components/page-shell";
import ProgressBar from "@/components/progress-bar";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { TrainingProgram } from "@/lib/types";
import { getMyTraining } from "@/lib/wordpress";

export default async function MyTrainingPage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let programs: TrainingProgram[];

  try {
    ({ programs } = await getMyTraining());
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
        My Training
      </h1>

      {programs.length === 0 ? (
        <p className="mt-4 text-zinc-600">
          You are not enrolled in any programme yet. Once an administrator
          enrolls you, it will appear here.
        </p>
      ) : (
        <ul className="mt-8 space-y-4">
          {programs.map((program) => (
            <li key={program.id}>
              <Link
                href={`/programs/${program.id}`}
                className="block transition hover:shadow-sm"
              >
                <Card className="hover:border-zinc-300">
                  <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-2 text-xl font-medium text-zinc-950">
                      {decodeEntities(program.title)}
                      {program.format === "webinar" && (
                        <Badge className="bg-sky-100 text-sky-800">
                          Webinar
                        </Badge>
                      )}
                    </CardTitle>
                  </CardHeader>

                  <CardContent>
                    <ProgressBar progress={program.progress} />
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
