"use client";

import { useState } from "react";
import { ChevronRightIcon } from "lucide-react";

import { cn } from "@pcle/ui/lib/utils";

/**
 * A row whose contents can be folded away.
 *
 * A programme of four units and nine modules put eighteen rows on screen at
 * once, permanently. Someone working on week three was reading weeks one, two
 * and four at the same time — the shape of the course was buried in its own
 * contents.
 *
 * Units arrive closed, so a programme reads as a handful of rows first. Deeper
 * levels arrive open, because having opened a unit you asked to see what is in
 * it, and making that a second click would just move the problem down a level.
 *
 * The row itself is passed in rather than composed here so the caller stays a
 * server component: only the fold needs to be interactive.
 */
export default function CollapsibleNode({
  row,
  children,
  defaultOpen,
  label,
}: {
  row: React.ReactNode;
  children: React.ReactNode;
  defaultOpen: boolean;
  label: string;
}) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <>
      <div className="flex items-start gap-1.5">
        <button
          type="button"
          onClick={() => setOpen(!open)}
          aria-expanded={open}
          aria-label={`${open ? "Collapse" : "Expand"} ${label}`}
          className="size-5 shrink-0 rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
        >
          <ChevronRightIcon
            className={cn("size-4 transition-transform", open && "rotate-90")}
          />
        </button>

        <div className="min-w-0 flex-1">{row}</div>
      </div>

      {open && children}
    </>
  );
}
