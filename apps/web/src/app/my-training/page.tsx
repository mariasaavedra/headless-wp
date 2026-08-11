import Link from "next/link";
import { redirect } from "next/navigation";

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
                className="block rounded-lg border border-zinc-200 bg-white p-6 transition hover:border-zinc-300 hover:shadow-sm"
              >
                <h2 className="text-xl font-medium text-zinc-950">
                  {decodeEntities(program.title)}
                </h2>

                <div className="mt-4">
                  <ProgressBar progress={program.progress} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </PageShell>
  );
}
