# headless-wp

npm workspaces monorepo for **Platform CLE**, a Continuing Legal Education platform. WordPress is the current backend/CMS; a Next.js frontend is being developed alongside it, moving toward a headless architecture.

## Architecture

Today, WordPress serves the site directly through the Platform CLE plugin and theme (existing WordPress templates render the app). The intended direction is headless:

```text
WordPress + Platform CLE plugin
        ↓
WordPress REST API
        ↓
Next.js / React / TypeScript
```

That boundary now exists, but it is thin. `apps/web` authenticates against WordPress over JWT (`/jwt-auth/v1/token`), keeps the token in an httpOnly cookie, and reads two things: the site name/tagline for the home page, and the signed-in user's programs and progress from `/platform-cle/v1/my-training`. Nothing else is wired up yet — there are no screens for browsing a program, week or module, so **the WordPress-rendered site remains the live interface** while `apps/web` is built out in parallel.

The curriculum itself is readable headlessly (`/wp/v2/pcle_*`, with the parent-child relationships exposed in `meta`), so the remaining work is frontend, not API.

## Repository structure

```text
.
├── apps/
│   ├── web/                          # Next.js app (App Router, TypeScript, Tailwind)
│   │   ├── src/app/                  # routes (layout.tsx, page.tsx, globals.css)
│   │   └── Dockerfile
│   └── wordpress/
│       ├── bin/
│       │   └── bootstrap.sh          # container entrypoint: install + configure WP
│       ├── plugins/
│       │   └── platform-cle/
│       │       ├── plugin/           # Platform CLE plugin source
│       │       ├── theme/            # Platform CLE child theme source
│       │       └── docs/             # plugin architecture/dev/deployment docs
│       └── Dockerfile
├── docker-compose.yml
├── package.json                      # npm workspaces root (apps/*)
└── README.md
```

## Prerequisites

- Docker and Docker Compose
- Node.js (for running `apps/web` outside of Docker) and npm

## Quick start

```bash
cp .env.example .env   # if you don't already have a .env
docker compose up --build
```

This builds and starts three services:

- `web` — Next.js app
- `wordpress` — WordPress + Platform CLE plugin/theme, built with WP-CLI
- `db` — MySQL 8.0

## Local URLs

| Service   | URL                        |
|-----------|-----------------------------|
| WordPress | http://localhost:8080       |
| Next.js   | http://localhost:3000       |

## Environment configuration

Copy `.env.example` to `.env` and adjust as needed:

```env
WORDPRESS_DB_NAME=wordpress
WORDPRESS_DB_USER=wordpress
WORDPRESS_DB_PASSWORD=wordpress
MYSQL_ROOT_PASSWORD=root

WORDPRESS_SITE_URL=http://localhost:8080
WORDPRESS_SITE_TITLE=Platform by Pen & Sword
WORDPRESS_TAGLINE=The Pen & Sword is a 501(c)(3) nonprofit organization
WORDPRESS_LOCALE=en_US

WORDPRESS_ADMIN_USER=admin
WORDPRESS_ADMIN_PASSWORD=admin
WORDPRESS_ADMIN_EMAIL=<your local email>
```

These values are read by `docker-compose.yml` and passed through to `apps/wordpress/bin/bootstrap.sh`, which uses them to install and configure WordPress via WP-CLI. `WORDPRESS_DB_*` and `MYSQL_ROOT_PASSWORD` fall back to development defaults in `docker-compose.yml` if unset; the `WORDPRESS_SITE_*`, `WORDPRESS_LOCALE`, and `WORDPRESS_ADMIN_*` values do not have compose-level defaults, so `.env` should set them.

## WordPress bootstrap behavior

On container start, `apps/wordpress/bin/bootstrap.sh` runs (via the image's `CMD`) and does the following, in order:

1. Runs the base WordPress image's install step (`docker-ensure-installed.sh`) to extract WordPress core and generate `wp-config.php` if not already present.
2. Waits for the `db` service to accept connections.
3. If WordPress is not yet installed, runs `wp core install` using `WORDPRESS_SITE_URL`, `WORDPRESS_SITE_TITLE`, and the `WORDPRESS_ADMIN_*` credentials. If it's already installed, this step is skipped.
4. Applies site settings unconditionally: site title, tagline, site URL, and home URL, from the corresponding env vars. Installs and switches to `WORDPRESS_LOCALE` if it isn't `en_US`.
5. Configures pretty permalinks (`/%postname%/`) and flushes rewrite rules.
6. Activates the `platform-cle` theme and the `platform-cle` plugin.
7. **Only on a fresh install**, runs the Platform CLE demo-data seeder (`seed-demo.php`).
8. Starts Apache in the foreground.

This is idempotent: restarting the `wordpress` container against an existing `wordpress_data` volume re-applies site settings and skips both the WordPress install and the demo-data seed, so content is not duplicated. A destroyed/recreated volume triggers a fresh install and reseeds demo data.

## Development workflows

### Start everything

```bash
docker compose up --build
# or detached:
docker compose up --build -d
```

### Editing the plugin or theme

`apps/wordpress/plugins/platform-cle/plugin` and `.../theme` are bind-mounted
into the container, so edits to them take effect immediately — no rebuild.

This matters because the `wordpress_data` volume shadows `/var/www/html` from
the first run onwards: the plugin copy baked into the image is only ever used
to populate that volume once. Without the bind mounts, `docker compose build`
appears to succeed while the running site keeps serving the old code.

### Running the plugin test suite

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/platform-cle/tests/smoke-test.php
```

Dependency-free, exits non-zero on failure, and runs in CI on every push
(`.github/workflows/ci.yml`).

### Demo accounts

When `PCLE_DEMO_USER_PASSWORD` is set in `.env`, the sample-data seeder also
creates three accounts, so the enrolled / not-enrolled / staff paths can all be
exercised without hand-building fixtures:

| Login | Role | Enrolled |
|---|---|---|
| `demo.student` | CLE Student | yes |
| `demo.outsider` | CLE Student | no |
| `demo.instructor` | CLE Instructor | n/a (staff) |

Leave `PCLE_DEMO_USER_PASSWORD` empty anywhere that isn't a local machine and
no demo accounts are created at all.

### Logs

```bash
docker compose logs -f wordpress
docker compose logs -f web
docker compose logs -f db
```

### Restart without losing data

Containers can be stopped and restarted without affecting the `wordpress_data` or `db_data` volumes:

```bash
docker compose restart wordpress
```

or

```bash
docker compose down
docker compose up
```

(`docker compose down` alone does **not** remove volumes — only containers/networks.)

### Resetting the entire local environment

To wipe WordPress and MySQL state and start clean (fresh install, fresh demo data):

```bash
docker compose down -v
docker compose up --build
```

The `-v` flag deletes the `wordpress_data` and `db_data` volumes. On the next `up`, `bootstrap.sh` detects there's no existing install and runs the full install + demo-data seed again.

### Next.js outside Docker

From the repo root (npm workspaces):

```bash
npm install
npm run dev     # apps/web dev server, http://localhost:3000
npm run build
npm run lint
npm run start
```

These root scripts delegate to the `apps/web` workspace.

## Platform CLE plugin and theme

The `platform-cle` plugin (`apps/wordpress/plugins/platform-cle/plugin`) owns the application's domain logic: custom post types, roles/capabilities, access control, enrollment, progress tracking, and dynamic blocks. It includes an idempotent demo-data seeder (`plugin/bin/seed-demo.php`) invoked by the bootstrap script only on a fresh install.

The child theme (`apps/wordpress/plugins/platform-cle/theme`) handles presentation for the current WordPress-rendered site and is required for the plugin's templates to display correctly. It is activated automatically by the bootstrap script.

WordPress remains the CMS/backend of record while `apps/web` is developed; the plugin and theme are not being removed as part of that move.

For plugin-specific architecture, local setup, and deployment details, see:

- [apps/wordpress/plugins/platform-cle/README.md](apps/wordpress/plugins/platform-cle/README.md)
- [apps/wordpress/plugins/platform-cle/docs/ARCHITECTURE.md](apps/wordpress/plugins/platform-cle/docs/ARCHITECTURE.md)
- [apps/wordpress/plugins/platform-cle/docs/DEVELOPMENT.md](apps/wordpress/plugins/platform-cle/docs/DEVELOPMENT.md)
- [apps/wordpress/plugins/platform-cle/docs/DEPLOYMENT.md](apps/wordpress/plugins/platform-cle/docs/DEPLOYMENT.md)

Note: that plugin repository's own docs refer to the theme's source directory as `platform-cle-theme`; in this monorepo it is built and deployed as `wp-content/themes/platform-cle` (see `apps/wordpress/Dockerfile`).

## Next.js frontend

- Location: `apps/web`
- Stack: Next.js (App Router, under `src/app`), TypeScript, Tailwind CSS
- Built with `output: "standalone"` for the Docker image (`apps/web/Dockerfile` runs a multi-stage `npm ci` → `next build` → standalone runtime).
- In `docker-compose.yml`, `web` builds from `apps/web/Dockerfile`, runs on port `3000`, and depends on the `wordpress` service. It reaches WordPress over the compose network at `WORDPRESS_API_URL=http://wordpress/wp-json`; running the app outside Docker needs that pointed at `http://localhost:8080/wp-json` instead.

### Routes

| Route | What it does |
|---|---|
| `/` | Site name and tagline from `/wp-json/`. |
| `/login` | Posts to `/jwt-auth/v1/token`; stores the token in an httpOnly cookie. |
| `/my-training` | Requires the cookie, calls `/platform-cle/v1/my-training`. Currently renders the raw JSON — the programme cards and progress bars are still to build. |

Two things worth knowing before adding screens:

- **Decode entities on text you render.** WordPress returns HTML-encoded strings (`Pen &amp; Sword`), and React escapes them again, so the entity shows up on screen. Pass plain-text fields through `decodeEntities()` (`src/lib/html.ts`). Never apply it to `content.rendered` — that is real HTML, and decoding it would turn an escaped `&lt;script&gt;` back into a live tag.
- **The token lasts 7 days and cannot be revoked.** Logging out deletes the cookie, but the token stays valid until it expires. Shorten it and add refresh before any of this is exposed publicly.

## Troubleshooting

**Bootstrap seems stuck or WordPress isn't responding:**

```bash
docker compose logs -f wordpress
```

Look for `bootstrap: waiting for database...` — if this repeats indefinitely, check the `db` service's logs and health instead.

**Check what's running:**

```bash
docker compose ps
```

**Changed a Dockerfile and don't see the change:**

```bash
docker compose up --build
```

Compose caches image layers; `--build` forces a rebuild. Rebuilding the image does not by itself change existing WordPress content, since that lives in the `wordpress_data` volume, not the image.

**WordPress state seems "stuck" (wrong URL, stale theme/plugin activation, missing demo data) after changing `.env` or plugin/theme source:**

Site settings (title, tagline, URL, locale, permalinks, theme/plugin activation) are reapplied by `bootstrap.sh` on every container start, so a plain restart picks up most `.env` changes. Demo data is only (re)seeded on a fresh install, so if you need demo data restored, reset the volumes:

```bash
docker compose down -v
docker compose up --build
```

**Permalinks or REST routes seem wrong:**

`bootstrap.sh` sets the permalink structure to `/%postname%/` and runs `wp rewrite flush --hard` on every start, so persistent 404s on custom post type URLs are more likely an Apache/`.htaccess` or plugin registration issue than a stale rewrite cache.
