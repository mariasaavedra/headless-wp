import Link from "next/link";

import { Separator } from "@pcle/ui/components/separator";

import { decodeEntities } from "@/lib/html";

type Crumb = {
  label: string;
  href?: string;
};

/**
 * Trail back up the hierarchy: My Training → Program → Unit → Module.
 *
 * The last crumb is the current page and is not a link.
 */
export default function Breadcrumbs({ trail }: { trail: Crumb[] }) {
  return (
    <nav aria-label="Breadcrumb">
      <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-zinc-500">
        {trail.map((crumb, index) => {
          const isLast = index === trail.length - 1;

          return (
            <li key={`${crumb.label}-${index}`} className="flex items-center gap-2">
              {crumb.href && !isLast ? (
                <Link
                  href={crumb.href}
                  className="hover:text-zinc-900 hover:underline"
                >
                  {decodeEntities(crumb.label)}
                </Link>
              ) : (
                <span aria-current={isLast ? "page" : undefined}>
                  {decodeEntities(crumb.label)}
                </span>
              )}

              {!isLast && (
                <Separator orientation="vertical" className="h-3" aria-hidden="true" />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
