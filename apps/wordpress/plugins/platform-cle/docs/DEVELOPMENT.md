# Development guide

How to work with the plugin and theme in the local environment.

> **This used to describe Local by Flywheel.** The project moved to Docker
> Compose in the `headless-wp` monorepo; the PHP/MySQL binary paths, the socket
> trick and `bin/sync.sh` are all obsolete. See
> [the monorepo README](../../../../../README.md) for the stack as a whole.

---

## Environment

Everything runs from the repository root via Docker Compose:

| Service | What | URL |
|---|---|---|
| `wordpress` | WordPress + this plugin and theme, installed by WP-CLI | http://localhost:8080 |
| `web` | The Next.js app | http://localhost:3000 |
| `db` | MySQL 8.0 | — |

```bash
cp .env.example .env      # if you don't already have one
docker compose up --build
```

`apps/wordpress/bin/bootstrap.sh` runs on container start: it installs WordPress
if needed, applies site settings, sets pretty permalinks, activates the
`platform-cle` plugin and theme, and — **only on a fresh install** — seeds demo
data.

### Editing the plugin or theme

`plugin/` and `theme/` are **bind-mounted** into the container. Edits take effect
immediately; no rebuild, no sync step.

This matters because the `wordpress_data` volume shadows `/var/www/html` after
the first run. The copy baked into the image only ever populates that volume
once — without the bind mounts, `docker compose build` would appear to succeed
while the running site kept serving the old code.

## Running PHP scripts against the site

WP-CLI is available inside the container:

```bash
docker compose exec wordpress wp option get blogname --allow-root
```

For a script that boots WordPress:

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/platform-cle/bin/seed-demo.php
```

## Syntax check (lint)

```bash
docker compose exec wordpress php -l /var/www/html/wp-content/plugins/platform-cle/includes/post-types.php
```

## Querying the database directly

```bash
docker compose exec db mysql -uwordpress -pwordpress wordpress \
  -e "SELECT * FROM wp_pcle_enrollments LIMIT 5\G"
```

The plugin's own tables are `wp_pcle_enrollments`, `wp_pcle_progress`,
`wp_pcle_attendance` and `wp_pcle_quiz_attempts` — see
[ARCHITECTURE.md §11](ARCHITECTURE.md).

## Included scripts

### `plugin/bin/seed-demo.php`
Creates a full sample program (Program, Units, Modules, scenarios, templates,
quizzes, events, Case Updates). **Idempotent**: it marks everything with
`_pcle_demo` meta and clears previous demos before recreating.

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/platform-cle/bin/seed-demo.php
```

When `PCLE_DEMO_USER_PASSWORD` is set in `.env`, the seeder also creates
`demo.student` (enrolled), `demo.outsider` (not enrolled) and `demo.instructor`,
so all three access paths can be exercised without hand-building fixtures. Leave
it empty anywhere that isn't a local machine and no demo accounts are created.

### `plugin/bin/setup-front-door.php`
Creates the **My Training** page (`/my-training/`) with the `my-programs` block
and adds a navigation menu link. Idempotent (meta `_pcle_front_door`).

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/platform-cle/bin/setup-front-door.php
```

## Tests

Dependency-free smoke tests (no PHPUnit, no composer) — **398 assertions across 31
sections** covering access control, enrollment, progress, relationships,
protected files, the REST guards, credit hours, attendance, certificates,
quizzes and marking, the quiz completion gate, reporting and the authoring API.
They boot WordPress, create isolated fixtures, assert, and clean up.

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/platform-cle/tests/smoke-test.php
echo "exit=$?"   # 0 = all passed, 1 = a test failed
```

**These run in CI on every push and pull request** (`.github/workflows/ci.yml`),
against a real stack rather than mocks: the workflow brings up WordPress and
MySQL, waits for `/wp-json/platform-cle/v1/health` to answer, then runs the
suite. A second job lints and builds `apps/web`.

> Upgrade path (Option C): migrate these to WP-PHPUnit (`WP_UnitTestCase`) with a
> dedicated test database. See [ROADMAP.md](ROADMAP.md).

## Testing the flow as a student

Sign in as `demo.student` (see the seeder above), or generate a valid auth cookie:

```bash
docker compose exec wordpress php -r '
require "/var/www/html/wp-load.php";
$u = get_user_by( "login", "demo.student" )->ID;
$exp = time() + 3600;
echo LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie( $u, $exp, "logged_in" );
'
# Then: curl -H "Cookie: <the above>" http://localhost:8080/program/<slug>/
```

For the Next.js app, sign in at http://localhost:3000/login — it exchanges the
credentials for a JWT at `/jwt-auth/v1/token` and keeps it in an httpOnly cookie.

## Protected files (uploads)

Files uploaded while editing a CLE post are stored in
`wp-content/uploads/pcle-protected/` and served only through the guarded endpoint
`?pcle_download=<attachment_id>`, which checks per-program access before
streaming. Attachment URLs for these files are rewritten to that endpoint
automatically.

Direct HTTP access to the raw path is blocked by an `.htaccess` on **Apache**,
which is what the container runs. On **nginx** (many managed hosts) add this rule
to the server block:

```nginx
location ^~ /wp-content/uploads/pcle-protected/ {
    deny all;
    return 404;
}
```

Without it, nginx would still serve the raw file if the exact URL is guessed —
the endpoint and URL rewriting hide it, but the server rule is what truly blocks
it. Add the rule before the first real pilot.

## Code conventions

- `pcle_` prefix on functions; `PCLE_` on constants.
- WordPress standards: escape on output (`esc_html`, `esc_url`, `esc_attr`),
  nonces in forms, capability checks on every save.
- No build step for the plugin: blocks are server-rendered (`render_callback`).
- The authoring API accepts **plain text and never HTML** — the server builds the
  markup. See [ARCHITECTURE.md §16](ARCHITECTURE.md).
