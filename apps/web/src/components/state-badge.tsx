import type { ReactNode } from "react";

import { Badge } from "@pcle/ui/components/badge";
import { cn } from "@pcle/ui/lib/utils";

import { STATES, swatchStyle, type State, type Swatch } from "@/lib/colours";

/**
 * A badge filled with one swatch from lib/colours.ts.
 *
 * The colour goes in `style`, not a class: Tailwind only ships the classes it
 * finds written out in the source, so a swatch chosen by name at runtime
 * would come out unstyled.
 */
export function SwatchBadge({
  swatch,
  className,
  children,
}: {
  swatch: Swatch | null;
  className?: string;
  children: ReactNode;
}) {
  if (!swatch) {
    return (
      <Badge variant="outline" className={cn("text-zinc-500", className)}>
        {children}
      </Badge>
    );
  }

  return (
    <Badge className={className} style={swatchStyle(swatch)}>
      {children}
    </Badge>
  );
}

/**
 * A badge for something's state, in that state's colour.
 *
 * The state's own label unless `children` says it in the screen's words
 * ("Passed", "✓ Completed"); the colour always comes from the state, so the
 * same meaning looks the same everywhere.
 */
export function StateBadge({
  state,
  className,
  children,
}: {
  state: State;
  className?: string;
  children?: ReactNode;
}) {
  return (
    <SwatchBadge swatch={STATES[state].swatch} className={className}>
      {children ?? STATES[state].label}
    </SwatchBadge>
  );
}
