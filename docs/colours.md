# Colours — one palette, named swatches

Every colour in the web app comes from one palette of 32 named swatches:
16 hues, each in a **soft** version (pale fill, dark text) and a **strong**
version (full fill, white text). Name a swatch in a change request:
*"make Draft gold-soft"*.

The palette lives in `apps/web/src/lib/colours.ts`. Screens never pick a
Tailwind colour or a hex value; they ask for a state (`<StateBadge>`) or a
named swatch (`<SwatchBadge>`) from `components/state-badge.tsx`.

## Swatches

Each hue below exists as `<hue>-soft` and `<hue>-strong`, e.g. `teal-soft`.

| Warm | Green | Blue | Purple |
|---|---|---|---|
| rose | olive | teal | indigo |
| coral | lime | cyan | plum |
| rust | fern | sky | magenta |
| amber | emerald | cobalt | |
| gold | | | |

## States

A state is what a badge means, not the words on it: a passed quiz, a completed
module and a finished participant are all *Complete*.

Each state may only use the hues in its family, so a failed quiz can never be
coloured like a completed one. The app refuses to start if a default breaks
this.

| State | Colour | Family | Where it shows |
|---|---|---|---|
| Draft | amber-soft | amber, gold, rust, coral | Builder (P9, P10, P11), Reports (P12) |
| Published | fern-soft | fern, emerald, lime, teal, olive | Program builder (P10) and item editor (P11) headers |
| Not started | outline, no fill | any | Program report (P13) |
| In progress | sky-soft | sky, cobalt, cyan, indigo, teal | Program report (P13) |
| Complete | emerald-soft | fern, emerald, lime, teal | P4, P6, P13, P14, quiz results ("Passed", "Completed", "Finished", "Correct") |
| Not passed | rose-soft | rose, coral, rust, magenta | Quiz attempts (P6) and results ("Not passed", "Incorrect") |
| Required | gold-soft | amber, gold, rust, coral | Required quizzes (P4, P6) |

## Other colours

| Badge | Colour |
|---|---|
| Webinar | indigo-soft |
