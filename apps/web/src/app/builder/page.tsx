import Link from "next/link";
import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import PageShell from "@/components/page-shell";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { AuthoringProgram } from "@/lib/types";
import { getAuthoringPrograms } from "@/lib/wordpress";

export default async function BuilderPage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let programs: AuthoringProgram[];

  try {
    ({ programs } = await getAuthoringPrograms());
  } catch (error) {
    // A participant who guesses this URL gets the same 403 panel as anywhere
    // else. WordPress decides; this only translates the answer.
    return renderAccessError(error, {
      title: "You do not have access to the builder",
      detail:
        "Building courses is limited to instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  return (
    <PageShell>
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
        Build
      </h1>
      <p className="mt-2 text-zinc-600">
        Programmes you can edit. Changes are drafts until you publish them.
      </p>

      {programs.length === 0 ? (
        <p className="mt-8 text-zinc-600">There are no programmes yet.</p>
      ) : (
        <ul className="mt-8 space-y-4">
          {programs.map((program) => (
            <li key={program.id}>
              <Link
                href={`/builder/programs/${program.id}`}
                className="block rounded-lg border border-zinc-200 bg-white p-6 transition hover:border-zinc-300 hover:shadow-sm"
              >
                <div className="flex flex-wrap items-center gap-3">
                  <h2 className="text-xl font-medium text-zinc-950">
                    {decodeEntities(program.title)}
                  </h2>

                  {program.status !== "publish" && (
                    <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">
                      Draft
                    </span>
                  )}
                </div>

                <p className="mt-2 text-sm text-zinc-500">
                  {program.weeks} {program.weeks === 1 ? "week" : "weeks"} ·{" "}
                  {program.modules}{" "}
                  {program.modules === 1 ? "module" : "modules"} ·{" "}
                  {program.enrollees}{" "}
                  {program.enrollees === 1 ? "participant" : "participants"}
                </p>

                <p className="mt-1 text-sm text-zinc-500">
                  {program.credits
                    .map((credit) =>
                      credit.hours > 0
                        ? `${credit.label}: ${credit.hours} h`
                        : `${credit.label}: not accredited`
                    )
                    .join(" · ")}
                </p>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </PageShell>
  );
}
