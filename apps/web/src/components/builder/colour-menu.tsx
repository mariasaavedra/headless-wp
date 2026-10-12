"use client";

import { useActionState } from "react";
import { CheckIcon } from "lucide-react";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@pcle/ui/components/dropdown-menu";

import { setColourAction } from "@/app/actions/authoring";
import { ColourDot } from "@/components/colour-marks";
import { PopupCode } from "@/components/screen-code";
import {
  HUES,
  swatchLabel,
  swatchStyle,
  type Hue,
  type Swatch,
} from "@/lib/colours";

/*
 * The 32 swatches as the menu lays them out: eight hues across, soft above
 * strong, so each column is one hue and the two halves read as warm-to-green
 * and teal-to-magenta.
 */
const HUE_NAMES = Object.keys(HUES) as Hue[];
const HALVES: Swatch[][] = [HUE_NAMES.slice(0, 8), HUE_NAMES.slice(8)].map(
  (half) => [
    ...half.map((hue) => `${hue}-soft` as const),
    ...half.map((hue) => `${hue}-strong` as const),
  ]
);

/**
 * Chooses an item's colour (D3).
 *
 * `own` is what the item has itself; `inherited` what it shows without that,
 * and `inheritedFrom` whose it is ("programme", "unit"). A programme has no
 * parent, so for it clearing means no colour rather than inheriting one.
 *
 * One form for every tile: the button that submits it carries the colour, so
 * this is still a plain post to a server action, as every builder control is.
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
  const [state, formAction, pending] = useActionState(setColourAction, {});
  const shown = own ?? inherited;
  const canInherit = inheritedFrom !== null;

  const now = own
    ? `${swatchLabel(own)}, its own colour`
    : inherited
      ? `${swatchLabel(inherited)}, from the ${inheritedFrom}`
      : "No colour";

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="inline-flex size-7 shrink-0 items-center justify-center rounded-md hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none"
        aria-label={`Colour for ${title}: ${now}`}
        title={`Colour: ${now}`}
        disabled={pending}
      >
        <ColourDot swatch={shown} />
      </DropdownMenuTrigger>

      <DropdownMenuContent align="start" className="w-auto p-3">
        <p className="text-sm font-medium text-zinc-950">Colour</p>
        <p className="mb-3 text-xs text-zinc-500">Now: {now}</p>

        <form action={formAction}>
          <input type="hidden" name="id" value={nodeId} />

          <div className="space-y-2">
            {HALVES.map((half, index) => (
              <div key={index} className="grid grid-cols-8 gap-1.5">
                {half.map((swatch) => (
                  <DropdownMenuItem
                    key={swatch}
                    nativeButton
                    render={
                      <button
                        type="submit"
                        name="color"
                        value={swatch}
                        aria-label={swatchLabel(swatch)}
                        title={swatchLabel(swatch)}
                      />
                    }
                    className="size-8 justify-center rounded-md p-0 text-xs font-semibold focus:ring-2 focus:ring-zinc-950 focus:ring-offset-1"
                    style={swatchStyle(swatch)}
                  >
                    {swatch === own ? <CheckIcon className="size-4" /> : "Aa"}
                  </DropdownMenuItem>
                ))}
              </div>
            ))}
          </div>

          <DropdownMenuItem
            nativeButton
            render={<button type="submit" name="color" value="" className="mt-3 w-full" />}
            disabled={!own}
          >
            <ColourDot swatch={canInherit ? inherited : null} />
            {canInherit
              ? `Use the ${inheritedFrom}'s colour`
              : "No colour"}
          </DropdownMenuItem>
        </form>

        {state.error && (
          <p role="alert" className="px-2 py-1.5 text-xs text-red-700">
            {state.error}
          </p>
        )}

        <PopupCode code="D3" />
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
