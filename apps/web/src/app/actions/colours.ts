"use server";

import { revalidatePath } from "next/cache";

import type { BuilderActionState } from "@/app/actions/authoring";
import {
  DEFAULT_SETTINGS,
  FORMATS,
  KINDS,
  STATES,
  allowedForState,
  isSwatch,
  type ColourSettings,
  type Format,
  type Kind,
  type State,
} from "@/lib/colours";
import {
  WordPressApiError,
  getColourSettings,
  saveColourSettings,
} from "@/lib/wordpress";

/**
 * One choice on the Colours screen (P16), saved on its own.
 *
 * Each control changes one setting, as each builder control changes one
 * thing; the action reads the rest fresh from WordPress and writes the whole
 * back. Everything is checked again here, the state families especially:
 * the menu greys out what a state may not use, but a hand-made request
 * should be refused, not obeyed.
 */
async function apply(
  change: (settings: ColourSettings) => ColourSettings | string
): Promise<BuilderActionState> {
  const next = change(structuredClone(await getColourSettings()));

  if (typeof next === "string") {
    return { error: next };
  }

  try {
    await saveColourSettings(next);
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 403) {
      return { error: "Only an administrator may change the colour settings." };
    }
    if (error instanceof WordPressApiError && error.status === 401) {
      return { error: "Your session expired. Sign in again and retry — nothing was saved." };
    }
    return { error: "That change could not be saved. Please try again." };
  }

  // Every screen draws its badges from the root layout's settings.
  revalidatePath("/", "layout");
  return {};
}

/** A swatch for a kind, a format or a state. "" means no colour at all. */
async function setDefaultColourAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const group = String(formData.get("group") ?? "");
  const key = String(formData.get("key") ?? "");
  const raw = String(formData.get("color") ?? "");
  const swatch = raw === "" ? null : raw;

  if (swatch !== null && !isSwatch(swatch)) {
    return { error: "That colour could not be applied." };
  }

  return apply((settings) => {
    if (group === "kinds" && (KINDS as readonly string[]).includes(key)) {
      settings.kinds[key as Kind] = swatch;
    } else if (group === "formats" && (FORMATS as readonly string[]).includes(key)) {
      settings.formats[key as Format] = swatch;
    } else if (group === "states" && key in STATES) {
      if (!allowedForState(key as State, swatch)) {
        return `${STATES[key as State].label} has to keep to its own colour family.`;
      }
      settings.states[key as State] = swatch;
    } else {
      return "That setting could not be identified.";
    }

    return settings;
  });
}

/** How programme colours are drawn, and whether participants see them. */
async function setDisplayAction(
  _prev: BuilderActionState,
  formData: FormData
): Promise<BuilderActionState> {
  const mark = formData.get("mark");
  const participants = formData.get("participants");

  return apply((settings) => {
    if (mark === "strip" || mark === "tint") {
      settings.mark = mark;
    } else if (participants === "on" || participants === "off") {
      settings.participants = participants === "on";
    } else {
      return "That setting could not be identified.";
    }

    return settings;
  });
}

/** Every default back as it shipped. */
async function resetColoursAction(): Promise<BuilderActionState> {
  return apply(() => structuredClone(DEFAULT_SETTINGS));
}

export { setDefaultColourAction, setDisplayAction, resetColoursAction };
