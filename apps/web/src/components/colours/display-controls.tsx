"use client";

import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";

import { resetColoursAction, setDisplayAction } from "@/app/actions/colours";
import type { ColourSettings } from "@/lib/colours";

/**
 * How programme colours are drawn, whether participants see them, and the way
 * back to how the colours shipped. Each button is its own small form, saved
 * the moment it is pressed, like every other choice on this screen.
 */
export default function DisplayControls({
  settings,
  disabled,
}: {
  settings: ColourSettings;
  disabled: boolean;
}) {
  const [state, formAction, pending] = useActionState(setDisplayAction, {});
  const [resetState, resetAction, resetting] = useActionState(resetColoursAction, {});
  const off = disabled || pending || resetting;

  return (
    <div className="space-y-6">
      <form action={formAction} className="space-y-2">
        <p className="font-medium text-zinc-900">Programme and item colours are drawn as</p>
        <div className="flex flex-wrap gap-2" role="group" aria-label="Mark style">
          {(
            [
              ["strip", "A strip down the left"],
              ["tint", "A strip, and a faint tint over the card"],
            ] as const
          ).map(([mark, label]) => (
            <Button
              key={mark}
              type="submit"
              name="mark"
              value={mark}
              variant={settings.mark === mark ? "default" : "outline"}
              aria-pressed={settings.mark === mark}
              disabled={off}
            >
              {label}
            </Button>
          ))}
        </div>
      </form>

      <form action={formAction} className="space-y-2">
        <p className="font-medium text-zinc-900">Participants</p>
        <p className="text-sm text-zinc-600">
          {settings.participants
            ? "Participants see programme and unit colours on My Training and their programmes."
            : "Participants see their screens without programme colours. Staff still see them in the builder."}
        </p>
        <Button
          type="submit"
          name="participants"
          value={settings.participants ? "off" : "on"}
          variant="outline"
          disabled={off}
        >
          {settings.participants ? "Hide colours from participants" : "Show colours to participants"}
        </Button>
      </form>

      {state.error && (
        <p role="alert" className="text-sm text-red-700">
          {state.error}
        </p>
      )}

      <form action={resetAction} className="space-y-2 border-t border-zinc-100 pt-6">
        <p className="text-sm text-zinc-600">
          Puts every colour on this screen back as it shipped. Programme and
          item colours chosen in the builder are kept.
        </p>
        <Button type="submit" variant="outline" disabled={off}>
          Restore the original colours
        </Button>
        {resetState.error && (
          <p role="alert" className="text-sm text-red-700">
            {resetState.error}
          </p>
        )}
      </form>
    </div>
  );
}
