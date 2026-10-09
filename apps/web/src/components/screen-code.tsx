/**
 * The small grey codes from docs/ui-map.md (P10, M1, D2…).
 *
 * Every screen, popup and dropdown menu has one, so a request for a change can
 * name exactly one thing: "P10 Program builder: move Add module to the top".
 * A name alone is ambiguous here — "the program page" is both P3, what a
 * participant reads, and P10, where an author edits it.
 *
 *   PageCode   fixed to the bottom-right of the window, one per screen, so it
 *              is in the same spot everywhere and never shifts the layout
 *   PopupCode  the last line of a dropdown menu, right-aligned and in flow, so
 *              it never sits on top of an option; or, with `corner`, the
 *              top-right corner of a popup, whose footer has to stay last
 *
 * No hooks, so both work in server and client components alike.
 */

import { cn } from "@pcle/ui/lib/utils";

const TITLE = "Screen code: use it when asking for a change to this screen";

export function PageCode({ code }: { code: string }) {
  return (
    <span
      aria-hidden="true"
      title={TITLE}
      className="fixed right-2.5 bottom-2 z-30 font-mono text-[10px] leading-none text-zinc-400 select-all"
    >
      {code}
    </span>
  );
}

export function PopupCode({
  code,
  corner = false,
}: {
  code: string;
  corner?: boolean;
}) {
  return (
    <div
      aria-hidden="true"
      title={TITLE}
      className={cn(
        "font-mono text-[10px] leading-none text-zinc-400 select-all",
        corner ? "absolute top-1.5 right-2.5" : "px-1 pt-1 text-right"
      )}
    >
      {code}
    </div>
  );
}
