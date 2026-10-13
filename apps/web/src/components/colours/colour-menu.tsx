"use client";

import { setColourAction } from "@/app/actions/authoring";
import SwatchMenu from "@/components/colours/swatch-menu";
import { swatchLabel, type Swatch } from "@/lib/colours";

/**
 * Chooses a programme's or an item's own colour, in the colour menu (D3).
 *
 * `own` is what the item has itself; `inherited` what it shows without that,
 * and `inheritedFrom` whose it is ("programme", "unit"). A programme has no
 * parent, so for it clearing means no colour rather than inheriting one.
 */
export default function ColourMenu({
  nodeId,
  title,
  own,
  inherited,
  inheritedFrom,
}: {
  nodeId: number;
  title: string;
  own: Swatch | null;
  inherited: Swatch | null;
  inheritedFrom: string | null;
}) {
  const now = own
    ? `${swatchLabel(own)}, its own colour`
    : inherited
      ? `${swatchLabel(inherited)}, from the ${inheritedFrom}`
      : "No colour";

  return (
    <SwatchMenu
      action={setColourAction}
      fields={{ id: nodeId }}
      current={own}
      shown={own ?? inherited}
      label={title}
      now={now}
      clear={
        inheritedFrom === null
          ? { label: "No colour", swatch: null }
          : { label: `Use the ${inheritedFrom}'s colour`, swatch: inherited }
      }
    />
  );
}
