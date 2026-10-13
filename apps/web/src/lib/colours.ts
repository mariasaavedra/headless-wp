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

/**
 * Whether a swatch may stand for a state: inside the state's family, or none.
 * What keeps "Not passed" from ever being made green on the settings screen.
 */
function allowedForState(state: State, swatch: Swatch | null): boolean {
  const family = STATES[state].family;
  return swatch === null || family === null || family.includes(parts(swatch).hue);
}

/** The kinds of item that carry a badge. A programme is coloured, not badged. */
const KINDS = [
  "pcle_unit",
  "pcle_module",
  "pcle_quiz",
  "pcle_event",
  "pcle_scenario",
  "pcle_template",
] as const;

const FORMATS = ["webinar"] as const;

type Kind = (typeof KINDS)[number];
type Format = (typeof FORMATS)[number];

/**
 * Every site-wide colour choice on the Colours screen (P16).
 *
 * WordPress stores only what an administrator changed (`pcle_colour_settings`);
 * these defaults fill in the rest, and stand in entirely when WordPress cannot
 * be reached, so a badge always has a colour to draw.
 */
type ColourSettings = {
  kinds: Record<Kind, Swatch | null>;
  formats: Record<Format, Swatch | null>;
  states: Record<State, Swatch | null>;
  /** How a programme's or item's colour is drawn on its card. */
  mark: "strip" | "tint";
  /** Whether participants see programme and item colours. WordPress enforces it. */
  participants: boolean;
};

const DEFAULT_SETTINGS: ColourSettings = {
  kinds: {
    pcle_unit: "cobalt-soft",
    pcle_module: "cyan-soft",
    pcle_quiz: "plum-soft",
    pcle_event: "magenta-soft",
    pcle_scenario: "olive-soft",
    pcle_template: "rust-soft",
  },
  formats: { webinar: "indigo-soft" },
  states: Object.fromEntries(
    Object.entries(STATES).map(([state, { swatch }]) => [state, swatch])
  ) as Record<State, Swatch | null>,
  mark: "strip",
  participants: true,
};

for (const [state, swatch] of Object.entries(DEFAULT_SETTINGS.states)) {
  if (!allowedForState(state as State, swatch)) {
    throw new Error(`colours.ts: ${state} defaults to ${swatch}, outside its family`);
  }
}

function field(source: unknown, key: string): unknown {
  return typeof source === "object" && source !== null
    ? (source as Record<string, unknown>)[key]
    : undefined;
}

/**
 * One group of swatches from WordPress, over its defaults: a key left out is
 * the default, `null` is "no colour", and anything else unusable is ignored.
 */
function mergeGroup<K extends string>(
  stored: unknown,
  defaults: Record<K, Swatch | null>,
  allowed: (key: K, swatch: Swatch | null) => boolean = () => true
): Record<K, Swatch | null> {
  const merged = { ...defaults };

  for (const key of Object.keys(defaults) as K[]) {
    const value = field(stored, key);

    if ((value === null || isSwatch(value)) && allowed(key, value)) {
      merged[key] = value;
    }
  }

  return merged;
}

/** What WordPress stored, laid over the defaults. */
function mergeSettings(stored: unknown): ColourSettings {
  const mark = field(stored, "mark");
  const participants = field(stored, "participants");

  return {
    kinds: mergeGroup(field(stored, "kinds"), DEFAULT_SETTINGS.kinds),
    formats: mergeGroup(field(stored, "formats"), DEFAULT_SETTINGS.formats),
    states: mergeGroup(field(stored, "states"), DEFAULT_SETTINGS.states, allowedForState),
    mark: mark === "tint" || mark === "strip" ? mark : DEFAULT_SETTINGS.mark,
    participants: typeof participants === "boolean" ? participants : DEFAULT_SETTINGS.participants,
  };
}

/**
 * Only what differs from the defaults: what WordPress should store.
 *
 * Saving the whole object would freeze today's defaults into the database,
 * and a default retuned here later would never reach a site that had once
 * saved anything.
 */
function changesFromDefaults(settings: ColourSettings): Partial<Record<keyof ColourSettings, unknown>> {
  const changes: Partial<Record<keyof ColourSettings, unknown>> = {};

  for (const group of ["kinds", "formats", "states"] as const) {
    const changed = Object.fromEntries(
      Object.entries(settings[group]).filter(
        ([key, swatch]) => (DEFAULT_SETTINGS[group] as Record<string, Swatch | null>)[key] !== swatch
      )
    );

    if (Object.keys(changed).length > 0) {
      changes[group] = changed;
    }
  }

  if (settings.mark !== DEFAULT_SETTINGS.mark) changes.mark = settings.mark;
  if (settings.participants !== DEFAULT_SETTINGS.participants) changes.participants = settings.participants;

  return changes;
}

/**
 * The settings as CSS custom properties, one trio per badge:
 * `--state-draft-bg`, `--state-draft-fg`, `--state-draft-border`.
 *
 * The root layout writes these once, and every badge reads them, server or
 * client component alike: changing a colour on the Colours screen is one
 * re-render of the layout, not a prop threaded through every screen.
 */
function settingsCss(settings: ColourSettings): string {
  const trio = (name: string, swatch: Swatch | null) => {
    if (!swatch) {
      // The outline badge: no fill, quiet text, a hairline.
      return `--${name}-bg:transparent;--${name}-fg:var(--color-zinc-500);--${name}-border:var(--color-zinc-200);`;
    }

    const { backgroundColor, color } = swatchStyle(swatch);
    return `--${name}-bg:${backgroundColor};--${name}-fg:${color};--${name}-border:transparent;`;
  };

  const lines = [
    ...Object.entries(settings.states).map(([key, swatch]) => trio(`state-${key}`, swatch)),
    ...Object.entries(settings.kinds).map(([key, swatch]) => trio(`kind-${key}`, swatch)),
    ...Object.entries(settings.formats).map(([key, swatch]) => trio(`format-${key}`, swatch)),
  ];

  return `:root{${lines.join("")}}`;
}

export {
  HUES,
  SWATCHES,
  STATES,
  KINDS,
  FORMATS,
  DEFAULT_SETTINGS,
  allowedForState,
  changesFromDefaults,
  isSwatch,
  mergeSettings,
  settingsCss,
  swatchLabel,
  swatchSolid,
  swatchStyle,
};
export type { Hue, Tone, Swatch, State, Kind, Format, ColourSettings };
