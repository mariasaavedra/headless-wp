"use client";

import { useActionState } from "react";
import { CheckIcon } from "lucide-react";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@pcle/ui/components/dropdown-menu";

import type { BuilderActionState } from "@/app/actions/authoring";
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
 * The colour menu (D3): 32 swatches and one way back out.
 *
 * The same menu wherever a colour is chosen, in the builder and on the
 * Colours screen, so it has one code. What a choice means is the caller's:
 * `action` receives `fields` plus `color`, a swatch name or "" for `clear`.
 *
 * One form for every tile: the button that submits it carries the colour, so
 * this is still a plain post to a server action, as every builder control is.
 */
export default function SwatchMenu({
  action,
  fields,
  current,
  shown,
  label,
  now,
  clear,
  allowedHues = null,
  disabled = false,
}: {
  action: (state: BuilderActionState, formData: FormData) => Promise<BuilderActionState>;
  /** Hidden fields saying what is being coloured. */
  fields: Record<string, string | number>;
  /** The swatch chosen here, ticked in the grid. */
  current: Swatch | null;
  /** What the trigger's dot shows: `current`, or what it inherits. */
  shown: Swatch | null;
  /** What is being coloured, for the trigger's accessible name. */
  label: string;
  /** The colour in words: "Teal strong, from the programme". */
  now: string;
  /** The way back out, and the colour it leads to. */
  clear: { label: string; swatch: Swatch | null };
  /** Only these hues may be chosen. A state's family, for instance. */
  allowedHues?: Hue[] | null;
  /** Shown, not changed: the reader may not choose here. */
  disabled?: boolean;
}) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="inline-flex size-7 shrink-0 items-center justify-center rounded-md enabled:hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none disabled:cursor-default"
        aria-label={`Colour for ${label}: ${now}`}
        title={`Colour: ${now}`}
        disabled={pending || disabled}
      >
        <ColourDot swatch={shown} />
      </DropdownMenuTrigger>

      <DropdownMenuContent align="start" className="w-auto p-3">
        <p className="text-sm font-medium text-zinc-950">Colour</p>
        <p className="mb-3 text-xs text-zinc-500">Now: {now}</p>

        <form action={formAction}>
          {Object.entries(fields).map(([name, value]) => (
            <input key={name} type="hidden" name={name} value={value} />
          ))}

          <div className="space-y-2">
            {HALVES.map((half, index) => (
              <div key={index} className="grid grid-cols-8 gap-1.5">
                {half.map((swatch) => {
                  const offered = !allowedHues || allowedHues.some((hue) => swatch.startsWith(`${hue}-`));

                  return (
                    <DropdownMenuItem
                      key={swatch}
                      nativeButton
                      disabled={!offered}
                      render={
                        <button
                          type="submit"
                          name="color"
                          value={swatch}
                          aria-label={swatchLabel(swatch)}
                          title={offered ? swatchLabel(swatch) : `${swatchLabel(swatch)}: not offered here`}
                        />
                      }
                      className="size-8 justify-center rounded-md p-0 text-xs font-semibold focus:ring-2 focus:ring-zinc-950 focus:ring-offset-1 data-disabled:opacity-20"
                      style={swatchStyle(swatch)}
                    >
                      {swatch === current ? <CheckIcon className="size-4" /> : "Aa"}
                    </DropdownMenuItem>
                  );
                })}
              </div>
            ))}
          </div>

          {allowedHues && (
            <p className="mt-2 max-w-64 text-xs text-zinc-500">
              Only colours from this state&rsquo;s family are offered, so it
              cannot be mistaken for another.
            </p>
          )}

          <DropdownMenuItem
            nativeButton
            render={<button type="submit" name="color" value="" className="mt-3 w-full" />}
            disabled={!current}
          >
            <ColourDot swatch={clear.swatch} />
            {clear.label}
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
