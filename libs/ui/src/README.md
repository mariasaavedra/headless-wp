# @pcle/ui

Shared [shadcn/ui](https://ui.shadcn.com/docs) component library for the
monorepo. Components are vendored directly into `src/components` (the
standard shadcn model — you own the code, not a black-box package), built on
[Base UI](https://base-ui.com/react/overview/quick-start) primitives and
Tailwind.

## Installed components

- [Alert Dialog](https://ui.shadcn.com/docs/components/alert-dialog) — `components/alert-dialog.tsx`
- [Badge](https://ui.shadcn.com/docs/components/badge) — `components/badge.tsx`
- [Button Group](https://ui.shadcn.com/docs/components/button-group) — `components/button-group.tsx`
- [Button](https://ui.shadcn.com/docs/components/button) — `components/button.tsx`
- [Card](https://ui.shadcn.com/docs/components/card) — `components/card.tsx`
- [Dropdown Menu](https://ui.shadcn.com/docs/components/dropdown-menu) — `components/dropdown-menu.tsx`
- [Input](https://ui.shadcn.com/docs/components/input) — `components/input.tsx`
- [Label](https://ui.shadcn.com/docs/components/label) — `components/label.tsx`
- [Separator](https://ui.shadcn.com/docs/components/separator) — `components/separator.tsx`
- [Textarea](https://ui.shadcn.com/docs/components/textarea) — `components/textarea.tsx`
- [Tooltip](https://ui.shadcn.com/docs/components/tooltip) — `components/tooltip.tsx`
- [Questionnaire](https://ui.shadcn.com/docs/components/base/questionnaire) — `components/questionnaire.tsx`
  (wraps the `@shadcn/react/questionnaire` primitive rather than Base UI, which is
  why its dependency is listed separately below)

This list is the components the monorepo actually uses. Vendored components that
nothing imported — `avatar`, `item`, `scroll-area`, `sheet`, `sidebar`,
`skeleton`, `switch`, `toggle`, `toggle-group` and the `use-mobile` hook — were
removed rather than left to drift out of date. Adding one back is
`npx shadcn add <name>`, which is the point of vendoring them.

Full component index: [ui.shadcn.com/docs/components](https://ui.shadcn.com/docs/components)

## Dependencies

- [`@base-ui/react`](https://base-ui.com/react/overview/quick-start) — unstyled
  primitives most components wrap (dropdown menu, tooltip, switch, etc.)
- `@shadcn/react` — shadcn runtime helpers
- [`class-variance-authority`](https://cva.style/docs) — variant styling (e.g.
  `Button`'s `variant`/`size` props)
- [`clsx`](https://github.com/lukeed/clsx) + [`tailwind-merge`](https://github.com/dcastil/tailwind-merge) —
  combined in `lib/utils.ts` as the `cn()` helper
- [`lucide-react`](https://lucide.dev/guide/packages/lucide-react) — icon set used by components
- `react` / `react-dom` (peer deps, v19) — provided by the consuming app

Tailwind config and design tokens live in `src/styles/globals.css`, generated
by the [shadcn CLI](https://ui.shadcn.com/docs/cli) (`style: base-nova`,
`baseColor: neutral`) per `components.json` — see the
[components.json docs](https://ui.shadcn.com/docs/components-json).

## Consuming it

This is an npm workspace package (`apps/*`, `libs/*`), so no build step or
publish is needed — consumers resolve it via the workspace and a TS path alias.

1. Add the path alias in the consumer's `tsconfig.json`:
   ```json
   "paths": {
     "@pcle/ui/*": ["../../libs/ui/src/*"]
   }
   ```
2. Import the global stylesheet once, e.g. in the app's root CSS:
   ```css
   @import "@pcle/ui/globals.css";
   ```
3. Import components, utils, and hooks directly:
   ```ts
   import { Button } from "@pcle/ui/components/button";
   import { Card, CardContent } from "@pcle/ui/components/card";
   import { cn } from "@pcle/ui/lib/utils";
   ```

See `apps/web` for a working example of all three steps.

## Adding a new component

Run the [shadcn CLI](https://ui.shadcn.com/docs/cli) from `libs/ui` so it
picks up this package's `components.json` (aliases point at `@pcle/ui/*`, not
the default `@/*`):

```sh
cd libs/ui
npx shadcn@latest add <component>
```

Browse available components at
[ui.shadcn.com/docs/components](https://ui.shadcn.com/docs/components) before
adding one.
