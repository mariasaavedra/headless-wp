import { redirect } from "next/navigation";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import PageShell from "@/components/page-shell";
import PreviewBanner from "@/components/preview-banner";
import UnitSection from "@/components/unit-section";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import { isPreview, keepPreview } from "@/lib/preview";
import type { UnitDetail } from "@/lib/types";
import { getUnit } from "@/lib/wordpress";

export default async function UnitPage({
  params,
  searchParams,
}: PageProps<"/units/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;
  const preview = isPreview(await searchParams);

  let unit: UnitDetail;

  try {
    unit = await getUnit(Number(id), preview);
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      {preview && <PreviewBanner programmeId={unit.program?.id} />}

      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          ...(unit.program
            ? [
                {
                  label: unit.program.title,
                  href: keepPreview(`/programs/${unit.program.id}`, preview),
                },
              ]
            : []),
          { label: unit.title },
        ]}
      />

      <WpContent html={unit.content} className="mt-6" />

      <div className="mt-6">
        <UnitSection
          unit={unit}
          headingLevel="h1"
          linkHeading={false}
          preview={preview}
        />
      </div>
    </PageShell>
  );
}
