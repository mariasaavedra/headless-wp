import Link from "next/link";

import { Badge } from "@pcle/ui/components/badge";
import { Card, CardContent } from "@pcle/ui/components/card";

import { ColourDot, ColourStrip } from "@/components/colour-marks";
import ProgressBar from "@/components/progress-bar";
import { decodeEntities } from "@/lib/html";
import { keepPreview } from "@/lib/preview";
import type { Unit } from "@/lib/types";

/**
 * One unit with its modules and live sessions.
 *
 * Shared by the programme page (which lists every unit) and the unit page
 * (which shows one), so the two can never drift into showing different things
 * about the same unit.
 */
export default function UnitSection({
  unit,
  headingLevel = "h2",
  linkHeading = true,
  preview = false,
}: {
  unit: Unit;
  headingLevel?: "h1" | "h2";
  linkHeading?: boolean;
  /**
   * Carries the participant preview into every link out of this card. A
   * preview that ends silently one click in is worse than none: the reader
   * is back to their own progress without being told.
   */
  preview?: boolean;
}) {
  const Heading = headingLevel;
  const title = decodeEntities(unit.title);

  return (
    <Card className="relative p-6">
      <ColourStrip swatch={unit.color} />
      <CardContent className="p-0">
        <Heading className="text-xl font-medium text-zinc-950">
          {linkHeading ? (
            <Link
              href={keepPreview(`/units/${unit.id}`, preview)}
              className="hover:underline"
            >
              {title}
            </Link>
          ) : (
            title
          )}
        </Heading>

        {unit.excerpt && (
          <p className="mt-2 text-sm text-zinc-600">
            {decodeEntities(unit.excerpt)}
          </p>
        )}

        <div className="mt-4">
          <ProgressBar progress={unit.progress} label="This unit" />
        </div>

        {unit.modules.length > 0 && (
          <ul className="mt-6 divide-y divide-zinc-100 border-t border-zinc-100">
            {unit.modules.map((module) => (
              <li key={module.id}>
                <Link
                  href={keepPreview(`/modules/${module.id}`, preview)}
                  className="flex items-start gap-3 py-3 hover:bg-zinc-50"
                >
                  <Badge
                    aria-hidden="true"
                    variant={module.completed ? "default" : "outline"}
                    className={
                      module.completed
                        ? "mt-0.5 h-5 w-5 shrink-0 rounded-full bg-emerald-600 p-0"
                        : "mt-0.5 h-5 w-5 shrink-0 rounded-full border-zinc-300 p-0"
                    }
                  >
                    {module.completed ? "✓" : ""}
                  </Badge>

                  <span>
                    <span className="block font-medium text-zinc-900">
                      {/*
                        Only when it differs from the unit's: the strip
                        already says the rest, and a dot on every row would
                        only repeat it.
                      */}
                      {module.color && module.color !== unit.color && (
                        <ColourDot swatch={module.color} className="mr-2 size-2.5 align-middle" />
                      )}
                      {decodeEntities(module.title)}
                      <span className="sr-only">
                        {module.completed ? " (completed)" : " (not completed)"}
                      </span>
                    </span>

                    {module.excerpt && (
                      <span className="mt-0.5 block text-sm text-zinc-500">
                        {decodeEntities(module.excerpt)}
                      </span>
                    )}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}

        {unit.events.length > 0 && (
          <div className="mt-6 rounded border border-zinc-200 bg-zinc-50 p-4">
            <h3 className="text-sm font-semibold text-zinc-700">Live sessions</h3>

            <ul className="mt-2 space-y-1">
              {unit.events.map((event) => (
                <li key={event.id} className="text-sm text-zinc-600">
                  <span className="font-medium text-zinc-800">
                    {decodeEntities(event.title)}
                  </span>
                  {event.formatted && (
                    <>
                      {" — "}
                      <time dateTime={event.starts_at || undefined}>
                        {decodeEntities(event.formatted)}
                      </time>
                    </>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
