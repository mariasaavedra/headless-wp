import { cn } from "@pcle/ui/lib/utils";

import { swatchSolid, type Swatch } from "@/lib/colours";

/*
 * How a programme's or item's colour is drawn: a strip down the left edge of
 * its card, or a dot beside it. Never the only sign of anything: the colour
 * says which programme, and the words on the card still say what it is.
 *
 * No hooks, so both work in server and client components alike.
 */

/** A round sample of a colour, dashed when there is none. */
export function ColourDot({
  swatch,
  className,
}: {
  swatch: Swatch | null;
  className?: string;
}) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        "inline-block size-3.5 shrink-0 rounded-full",
        swatch
          ? "shadow-[inset_0_0_0_1px_rgb(0_0_0/0.12)]"
          : "border-[1.5px] border-dashed border-zinc-300",
        className
      )}
      style={swatch ? { backgroundColor: swatchSolid(swatch) } : undefined}
    />
  );
}

/**
 * A strip down the left edge of a card, and a faint wash over it when the
 * Colours screen says "Tinted" (`.colour-tint`, in globals.css, keyed off
 * `data-colour-mark` on <html>).
 *
 * The card needs `relative`; its own rounded corners and overflow clip both
 * to fit. Nothing at all when there is no colour, so an uncoloured card looks
 * exactly as it did.
 */
export function ColourStrip({ swatch }: { swatch: Swatch | null }) {
  if (!swatch) {
    return null;
  }

  const colour = swatchSolid(swatch);

  return (
    <>
      <span
        aria-hidden="true"
        className="colour-tint pointer-events-none absolute inset-0"
        style={{ backgroundColor: colour }}
      />
      <span
        aria-hidden="true"
        className="absolute inset-y-0 left-0 w-1.5"
        style={{ backgroundColor: colour }}
      />
    </>
  );
}
