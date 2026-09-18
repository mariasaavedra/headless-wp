# apps/web

The participant-facing Next.js app for **Platform CLE** (App Router,
TypeScript, Tailwind), served in production at
**[armory.thepen-and-swordkc.org](https://armory.thepen-and-swordkc.org)** on
Vercel.

WordPress remains the system of record. This app never touches its database: it
authenticates over JWT and reads and writes through the Platform CLE plugin's
REST routes.

## Where it points

| Environment | `WORDPRESS_API_URL` | Serves at |
|-------------|---------------------|-----------|
| Local, app outside Docker | `http://localhost:8080/wp-json` | http://localhost:3000 |
| Local, app inside Docker | `http://wordpress/wp-json` | http://localhost:3000 |
| Production | `https://platform.thepen-and-swordkc.org/wp-json` | https://armory.thepen-and-swordkc.org |

Three variables matter:

- `WORDPRESS_API_URL` — where **this server** reaches WordPress. Required.
- `WORDPRESS_SITE_URL` — where a **browser** reaches WordPress, used for links
  into `wp-admin`. Not always the same host, and when it is unset the app
  simply stops offering those links.
- `NEXT_PUBLIC_SITE_URL` — this app's own public address, used to resolve
  metadata URLs. Defaults to the `armory` host.

For the full picture of what runs where, see the "Deployments" section of the
[root README](../../README.md).

## Running it

From the repository root, which starts WordPress and MySQL first:

```bash
npm run dev
```

Or on its own, against a WordPress that is already up:

```bash
npm run dev --workspace=apps/web
```

`apps/web/.env.local` holds the local values; it is not committed.

## Checks

```bash
npm run lint --workspace=apps/web
npm run build --workspace=apps/web
```

Both run in CI on every push, in the `web` job of `.github/workflows/ci.yml`.

## Layout

```text
src/
├── app/          # routes, layouts and server actions
├── components/   # app components, including the curriculum builder
└── lib/          # WordPress client, auth cookie, shared types
```

Shared presentational components come from `@pcle/ui` (`libs/ui`), not from
this workspace.
