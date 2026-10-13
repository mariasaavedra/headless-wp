# Colours — one palette, named swatches

Every colour in the web app comes from one palette of 32 named swatches:
16 hues, each in a **soft** version (pale fill, dark text) and a **strong**
version (full fill, white text). Name a swatch in a change request:
*"make Draft gold-soft"*.

The palette lives in `apps/web/src/lib/colours.ts`. Screens never pick a
Tailwind colour or a hex value; they ask for a state, a kind or a format
(`<StateBadge>`, `<KindBadge>`, `<FormatBadge>` in `components/state-badge.tsx`),
and the colour comes from the Colours screen.

## The Colours screen (P16)

**Colours** in the header, for instructors and administrators.

| Section | Who changes it | What it sets |
|---|---|---|
| Programmes | Authors | Each programme's colour, as the dot in the builder does |
| Kinds of item | Administrators | The badge colour for units, modules, quizzes, live sessions, scenarios, templates, and the Webinar badge |
| States | Administrators | The badge colour for each state below, within its family |
| Display | Administrators | A strip down the left of a card, or a strip and a faint tint; whether participants see programme colours; restoring the original colours |

Every choice is saved the moment it is made. WordPress stores only what
differs from the defaults (`pcle_colour_settings`), so a default retuned in
`lib/colours.ts` still reaches a site that has changed something else.

The root layout turns the settings into CSS custom properties
(`--state-draft-bg`, `--kind-pcle_quiz-fg`, …) that every badge reads, so a
change reaches every screen at once. When WordPress cannot be reached, the
defaults are used.

When colours are hidden from participants, WordPress stops sending them on the
participant routes, so the setting holds for staff previewing as a
participant too.

## My colours (on P7, Your account)

Anyone signed in can recolour the programmes on their own My Training, under
**My colours**. Only they see the result: it replaces the programme's colour,
and the colours of the items inside it, on their My Training and programme
pages. **Use the programme's colour** gives it back.

WordPress keeps these per person (user meta `pcle_my_colours`) and refuses a
programme that is not on the reader's own My Training. They are hidden, with
every other colour, when an administrator hides colours from participants, and
a staff member previewing as a participant does not see their own.

## Programme and item colours

An author gives a programme a colour from the dot beside its title in the
Program builder (P10), using the colour menu (D3). Everything inside the
programme shows that colour, unless an item has been given its own; then
everything inside *that* item shows the item's colour instead. Choosing
**Use the programme's colour** (or the unit's) hands an item back.

The menu says where a colour comes from: *Teal strong, from the programme*
or *Plum soft, its own colour*.

| Where | How it shows |
|---|---|
| Program builder (P10) | A strip down the left of every row, and the dot that opens D3 |
| Item editor (P11) | The dot beside the title |
| Build (P9), Reports (P12), My Training (P2) | A strip down the left of the programme's card |
| Program (P3) | A strip down the left of each unit; a dot beside a module whose colour differs from its unit's |

WordPress stores only the swatch *name* an item was given itself
(`_pcle_colour`), never a colour value, and refuses names that are not in the
palette. Choosing a colour is not recorded as an edit.

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

These are the defaults; administrators change them on P16. A state is what a badge means, not the words on it: a passed quiz, a completed
module and a finished participant are all *Complete*.

Each state may only use the hues in its family, so a failed quiz can never be
coloured like a completed one. The colour menu offers only those hues, the
save refuses any other, and the app refuses to start if a default breaks it.

| State | Colour | Family | Where it shows |
|---|---|---|---|
| Draft | amber-soft | amber, gold, rust, coral | Builder (P9, P10, P11), Reports (P12) |
| Published | fern-soft | fern, emerald, lime, teal, olive | Program builder (P10) and item editor (P11) headers |
| Not started | outline, no fill | any | Program report (P13) |
| In progress | sky-soft | sky, cobalt, cyan, indigo, teal | Program report (P13) |
| Complete | emerald-soft | fern, emerald, lime, teal | P4, P6, P13, P14, quiz results ("Passed", "Completed", "Finished", "Correct") |
| Not passed | rose-soft | rose, coral, rust, magenta | Quiz attempts (P6) and results ("Not passed", "Incorrect") |
| Required | gold-soft | amber, gold, rust, coral | Required quizzes (P4, P6) |

## Kinds of item (defaults)

| Badge | Colour |
|---|---|
| Unit | cobalt-soft |
| Module | cyan-soft |
| Quiz | plum-soft |
| Live session | magenta-soft |
| Practice scenario | olive-soft |
| Template | rust-soft |
| Webinar | indigo-soft |

Kind badges show on the item editor (P11); the builder's group headings
(*Units*, *Quizzes*) carry a small square in the same colour.
