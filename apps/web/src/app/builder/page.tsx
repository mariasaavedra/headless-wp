import Link from "next/link";
import { redirect } from "next/navigation";
import { PlusIcon } from "lucide-react";

import { Badge } from "@pcle/ui/components/badge";
import { Button } from "@pcle/ui/components/button";
import { Card, CardContent, CardHeader, CardTitle } from "@pcle/ui/components/card";
import { Input } from "@pcle/ui/components/input";

import { renderAccessError } from "@/components/access-error";
import ActionForm from "@/components/builder/action-form";
import { createProgramAction } from "@/app/actions/authoring";
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

      {/*
        The only creation in the builder that is not made in context: a
        programme has no parent, so this is where a course starts. Credit hours
        are set on the programme itself once it exists, because they come off
        accreditation paperwork rather than out of someone's head at the moment
        of naming it.
      */}
      {/*
        A programme is named up front, unlike everything else in the builder:
        it has no parent to be created inside, and no other screen where the
        name could be corrected later. Everything below it is created with one
        click and renamed where it is edited.
      */}
      <Card className="mt-6 p-4">
        <ActionForm action={createProgramAction} className="flex flex-wrap gap-2">
          <Input
            type="text"
            name="title"
            placeholder="e.g. Immigration Habeas Corpus — Spring 2027"
            aria-label="Title for the new programme"
            className="w-96 max-w-full"
          />
          <Button type="submit">
            <PlusIcon className="size-4" />
            New programme
          </Button>
        </ActionForm>
      </Card>

      {programs.length === 0 ? (
        <p className="mt-8 text-zinc-600">There are no programmes yet.</p>
      ) : (
        <ul className="mt-8 space-y-4">
          {programs.map((program) => (
            <li key={program.id}>
              <Link
                href={`/builder/programs/${program.id}`}
                className="block transition hover:shadow-sm"
              >
                <Card className="hover:border-zinc-300">
                  <CardHeader>
                    <div className="flex flex-wrap items-center gap-3">
                      <CardTitle className="text-xl font-medium text-zinc-950">
                        {decodeEntities(program.title)}
                      </CardTitle>

                      {program.status !== "publish" && (
                        <Badge className="bg-amber-100 text-amber-800">Draft</Badge>
                      )}
                    </div>
                  </CardHeader>

                  <CardContent>
                    <p className="text-sm text-zinc-500">
                      {program.units} {program.units === 1 ? "unit" : "units"} ·{" "}
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
