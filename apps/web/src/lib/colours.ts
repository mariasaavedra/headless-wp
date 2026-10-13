import type { CSSProperties } from "react";

/**
 * The one palette every colour in the app comes from.
 *
 * Sixteen hues, each in a soft and a strong version: 32 named swatches. A
 * screen names a swatch ("teal-soft"), never a hex value or a Tailwind class,
 * so a shade can be retuned here without touching the screens that use it,
 * and a colour stored later in WordPress can be checked against this list.
 *
 * The badges used to pick Tailwind classes one screen at a time, which is how
 * the same "Draft" came to be amber in five files that had to be kept in step
 * by hand, and how "Webinar" and "In progress" ended up sharing sky blue.
 *
 * docs/colours.md lists every swatch and what each state uses, so a request
 * can name one: "make Draft gold-soft".
 */

/*
 * The plugin keeps the same list of hue names, to refuse anything else
 * (pcle_colour_swatches() in includes/colours.php). Change both together.
 */
const HUES = {
  rose: 15,
  coral: 35,
  rust: 50,
  amber: 75,
  gold: 95,
  olive: 115,
  lime: 135,
  fern: 150,
  emerald: 165,
  teal: 188,
  cyan: 210,
  sky: 235,
  cobalt: 258,
  indigo: 280,
  plum: 315,
  magenta: 348,
} as const;

type Hue = keyof typeof HUES;
type Tone = "soft" | "strong";
type Swatch = `${Hue}-${Tone}`;

const SWATCHES: Swatch[] = (Object.keys(HUES) as Hue[]).flatMap((hue) => [
  `${hue}-soft` as const,
  `${hue}-strong` as const,
]);

function parts(swatch: Swatch): { hue: Hue; tone: Tone; angle: number } {
  const [hue, tone] = swatch.split("-") as [Hue, Tone];
  return { hue, tone, angle: HUES[hue] };
}

/**
 * Background and text for a badge in this swatch.
 *
 * OKLCH so every hue comes out at the same perceived lightness: soft is a pale
 * fill with dark text, strong a full fill with white text, and both stay
 * readable whatever the hue.
 */
function swatchStyle(swatch: Swatch): CSSProperties {
  const { tone, angle } = parts(swatch);

  return tone === "soft"
    ? {
        backgroundColor: `oklch(0.935 0.045 ${angle})`,
        color: `oklch(0.40 0.12 ${angle})`,
      }
    : {
        backgroundColor: `oklch(0.52 0.15 ${angle})`,
        color: "#fff",
      };
}

/** Whether a value from a form or the API is a swatch this palette has. */
function isSwatch(value: unknown): value is Swatch {
  return typeof value === "string" && (SWATCHES as string[]).includes(value);
}

/** "teal-strong" → "Teal strong", for menus and titles. */
function swatchLabel(swatch: Swatch): string {
  const { hue, tone } = parts(swatch);
  return `${hue[0].toUpperCase()}${hue.slice(1)} ${tone}`;
}

/**
 * The one colour that stands for a swatch on a strip or a dot.
 *
 * A soft swatch's badge fill is too pale to see as a 4px line, so its strip
 * is a stronger version of the same hue; a strong swatch uses its fill.
 * Unknown names, including ones from a newer palette, draw nothing.
 */
function swatchSolid(swatch: Swatch | null | undefined): string {
  if (!isSwatch(swatch)) {
    return "transparent";
  }

  const { tone, angle } = parts(swatch);

  return tone === "soft"
    ? `oklch(0.80 0.10 ${angle})`
    : `oklch(0.52 0.15 ${angle})`;
}

/**
 * What a badge can say about something's state.
 *
 * Keyed by meaning, not by wording: a passed quiz, a completed module and a
 * participant who finished the programme are all `complete`, and say so in
 * their own words.
 */
type State =
  | "draft"
  | "published"
  | "notStarted"
  | "inProgress"
  | "complete"
  | "failed"
  | "required";

/**
 * Each state's colour, and the hues it may ever use.
 *
 * The family is what stops a failed quiz from being made to look like a
 * completed one once staff can choose these colours themselves; the check
 * below enforces it on the defaults today. `null` means the plain outline
 * badge: not having started is not news.
 */
const STATES: Record<
  State,
  { label: string; swatch: Swatch | null; family: Hue[] | null }
> = {
  draft: { label: "Draft", swatch: "amber-soft", family: ["amber", "gold", "rust", "coral"] },
  published: { label: "Published", swatch: "fern-soft", family: ["fern", "emerald", "lime", "teal", "olive"] },
  notStarted: { label: "Not started", swatch: null, family: null },
  inProgress: { label: "In progress", swatch: "sky-soft", family: ["sky", "cobalt", "cyan", "indigo", "teal"] },
  complete: { label: "Complete", swatch: "emerald-soft", family: ["fern", "emerald", "lime", "teal"] },
  failed: { label: "Not passed", swatch: "rose-soft", family: ["rose", "coral", "rust", "magenta"] },
  required: { label: "Required", swatch: "gold-soft", family: ["amber", "gold", "rust", "coral"] },
};

for (const [state, { swatch, family }] of Object.entries(STATES)) {
  if (swatch && family && !family.includes(parts(swatch).hue)) {
    throw new Error(`colours.ts: ${state} uses ${swatch}, outside its family`);
  }
}

/** Colours for things that are a kind rather than a state. */
const FORMAT_SWATCHES = {
  webinar: "indigo-soft",
} as const satisfies Record<string, Swatch>;

export {
  HUES,
  SWATCHES,
  STATES,
  FORMAT_SWATCHES,
  isSwatch,
  swatchLabel,
  swatchSolid,
  swatchStyle,
};
export type { Hue, Tone, Swatch, State };
