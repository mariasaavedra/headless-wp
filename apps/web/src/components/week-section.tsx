import Link from "next/link";

import { Badge } from "@pcle/ui/components/badge";
import { Card, CardContent } from "@pcle/ui/components/card";

import ProgressBar from "@/components/progress-bar";
import { decodeEntities } from "@/lib/html";
import type { Week } from "@/lib/types";

/**
 * One week with its modules and live sessions.
 *
 * Shared by the programme page (which lists every week) and the week page
 * (which shows one), so the two can never drift into showing different things
 * about the same week.
 */
export default function WeekSection({
  week,
  headingLevel = "h2",
  linkHeading = true,
}: {
  week: Week;
  headingLevel?: "h1" | "h2";
  linkHeading?: boolean;
}) {
  const Heading = headingLevel;
  const title = decodeEntities(week.title);

  return (
    <Card className="p-6">
      <CardContent className="p-0">
        <Heading className="text-xl font-medium text-zinc-950">
          {linkHeading ? (
            <Link href={`/weeks/${week.id}`} className="hover:underline">
              {title}
            </Link>
          ) : (
            title
          )}
        </Heading>

        {week.excerpt && (
          <p className="mt-2 text-sm text-zinc-600">
            {decodeEntities(week.excerpt)}
          </p>
        )}

        <div className="mt-4">
          <ProgressBar progress={week.progress} label="This week" />
        </div>

        {week.modules.length > 0 && (
          <ul className="mt-6 divide-y divide-zinc-100 border-t border-zinc-100">
            {week.modules.map((module) => (
              <li key={module.id}>
                <Link
                  href={`/modules/${module.id}`}
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

        {week.events.length > 0 && (
          <div className="mt-6 rounded border border-zinc-200 bg-zinc-50 p-4">
            <h3 className="text-sm font-semibold text-zinc-700">Live sessions</h3>

            <ul className="mt-2 space-y-1">
              {week.events.map((event) => (
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
