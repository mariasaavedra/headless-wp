# @pcle/ui

Shared [shadcn/ui](https://ui.shadcn.com/docs) component library for the
monorepo. Components are vendored directly into `src/components` (the
standard shadcn model — you own the code, not a black-box package), built on
[Base UI](https://base-ui.com/react/overview/quick-start) primitives and
Tailwind.

## Installed components

- [Avatar](https://ui.shadcn.com/docs/components/avatar) — `components/avatar.tsx`
- [Badge](https://ui.shadcn.com/docs/components/badge) — `components/badge.tsx`
- [Button Group](https://ui.shadcn.com/docs/components/button-group) — `components/button-group.tsx`
- [Button](https://ui.shadcn.com/docs/components/button) — `components/button.tsx`
- [Card](https://ui.shadcn.com/docs/components/card) — `components/card.tsx`
- [Dropdown Menu](https://ui.shadcn.com/docs/components/dropdown-menu) — `components/dropdown-menu.tsx`
- [Input](https://ui.shadcn.com/docs/components/input) — `components/input.tsx`
- [Item](https://ui.shadcn.com/docs/components/item) — `components/item.tsx`
- [Scroll Area](https://ui.shadcn.com/docs/components/scroll-area) — `components/scroll-area.tsx`
- [Separator](https://ui.shadcn.com/docs/components/separator) — `components/separator.tsx`
- [Sheet](https://ui.shadcn.com/docs/components/sheet) — `components/sheet.tsx`
- [Sidebar](https://ui.shadcn.com/docs/components/sidebar) — `components/sidebar.tsx`
- [Skeleton](https://ui.shadcn.com/docs/components/skeleton) — `components/skeleton.tsx`
- [Switch](https://ui.shadcn.com/docs/components/switch) — `components/switch.tsx`
- [Toggle Group](https://ui.shadcn.com/docs/components/toggle-group) — `components/toggle-group.tsx`
- [Toggle](https://ui.shadcn.com/docs/components/toggle) — `components/toggle.tsx`
- [Tooltip](https://ui.shadcn.com/docs/components/tooltip) — `components/tooltip.tsx`
- `Questionnaire` — `components/questionnaire.tsx` (custom, not a shadcn primitive)

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
