import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import PageShell from "@/components/page-shell";
import WeekSection from "@/components/week-section";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import type { WeekDetail } from "@/lib/types";
import { getWeek } from "@/lib/wordpress";

export default async function WeekPage({ params }: PageProps<"/weeks/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let week: WeekDetail;

  try {
    week = await getWeek(Number(id));
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          ...(week.program
            ? [
                {
                  label: week.program.title,
                  href: `/programs/${week.program.id}`,
                },
              ]
            : []),
          { label: week.title },
        ]}
      />

      <WpContent html={week.content} className="mt-6" />

      <div className="mt-6">
        <WeekSection week={week} headingLevel="h1" linkHeading={false} />
      </div>
    </PageShell>
  );
}
