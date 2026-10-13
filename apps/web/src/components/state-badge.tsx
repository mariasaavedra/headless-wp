import type { CSSProperties, ReactNode } from "react";

import { Badge } from "@pcle/ui/components/badge";

import { NODE_BADGES } from "@/components/builder/node-labels";
import { STATES, type Format, type Kind, type State } from "@/lib/colours";

/**
 * Badges coloured from the Colours screen (P16).
 *
 * The colour is read from CSS custom properties the root layout writes
 * (`--state-draft-bg` and its pair), not chosen here. That is what lets an
 * administrator recolour every Draft badge at once, and lets a client
 * component use these without anyone handing it the settings.
 *
 * The colour goes in `style`, not a class: Tailwind only ships the classes it
 * finds written out in the source.
 */
function tokenStyle(token: string): CSSProperties {
  return {
    backgroundColor: `var(--${token}-bg)`,
    color: `var(--${token}-fg)`,
    borderColor: `var(--${token}-border)`,
  };
}

function TokenBadge({
  token,
  className,
  children,
}: {
  token: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <Badge variant="outline" className={className} style={tokenStyle(token)}>
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
    <TokenBadge token={`state-${state}`} className={className}>
      {children ?? STATES[state].label}
    </TokenBadge>
  );
}

/** What kind of item something is ("Quiz", "Module"), in that kind's colour. */
export function KindBadge({ kind, className }: { kind: Kind; className?: string }) {
  return (
    <TokenBadge token={`kind-${kind}`} className={className}>
      {NODE_BADGES[kind]}
    </TokenBadge>
  );
}

/** A programme's format, where it is not the usual series. */
export function FormatBadge({ format }: { format: Format }) {
  return (
    <TokenBadge token={`format-${format}`}>
      {format === "webinar" ? "Webinar" : format}
    </TokenBadge>
  );
}
