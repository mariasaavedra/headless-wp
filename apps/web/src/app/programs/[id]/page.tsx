import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import PageShell from "@/components/page-shell";
import ProgressBar from "@/components/progress-bar";
import WeekSection from "@/components/week-section";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { Program } from "@/lib/types";
import { getProgram } from "@/lib/wordpress";

export default async function ProgramPage({
  params,
}: PageProps<"/programs/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let program: Program;

  try {
    program = await getProgram(Number(id));
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          { label: program.title },
        ]}
      />

      <h1 className="mt-4 text-3xl font-semibold tracking-tight text-zinc-950">
        {decodeEntities(program.title)}
      </h1>

      <div className="mt-6 rounded-lg border border-zinc-200 bg-white p-6">
        <ProgressBar progress={program.progress} label="Programme progress" />
      </div>

      <WpContent html={program.content} className="mt-8" />

      {program.weeks.length === 0 ? (
        <p className="mt-8 text-zinc-600">
          This programme has no published weeks yet.
        </p>
      ) : (
        <div className="mt-8 space-y-6">
          {program.weeks.map((week) => (
            <WeekSection key={week.id} week={week} />
          ))}
        </div>
      )}
    </PageShell>
  );
}
