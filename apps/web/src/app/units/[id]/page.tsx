import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import PageShell from "@/components/page-shell";
import UnitSection from "@/components/unit-section";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import type { UnitDetail } from "@/lib/types";
import { getUnit } from "@/lib/wordpress";

export default async function UnitPage({ params }: PageProps<"/units/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let unit: UnitDetail;

  try {
    unit = await getUnit(Number(id));
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          ...(unit.program
            ? [
                {
                  label: unit.program.title,
                  href: `/programs/${unit.program.id}`,
                },
              ]
            : []),
          { label: unit.title },
        ]}
      />

      <WpContent html={unit.content} className="mt-6" />

      <div className="mt-6">
        <UnitSection unit={unit} headingLevel="h1" linkHeading={false} />
      </div>
    </PageShell>
  );
}
