# Development guide

How to work with the code in the local environment (Local by Flywheel).

---

## Environment

- **Site:** Local by Flywheel, at `~/Local Sites/tps/app/public`.
- **WordPress:** active theme `habeas-cle-theme`, active plugin `habeas-cle`.
- **PHP / MySQL:** the binaries live inside the Local app (see below).

### Local binary paths

```bash
PHP="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
MYSQL="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysql"
SOCK="$(find "$HOME/Library/Application Support/Local/run" -name mysqld.sock | head -1)"
```

> The socket's `run-id` changes if you restart the site in Local, which is why it's located with `find`.

## Running PHP scripts against the site

There is no WP-CLI on the PATH, and MySQL uses a non-standard socket. The trick is to pass the socket via PHP's ini (WordPress connects to `localhost`, which with `mysqli.default_socket` resolves to Local's socket):

```bash
cd ~/Local\ Sites/tps/app/public
"$PHP" -d mysqli.default_socket="$SOCK" wp-content/plugins/habeas-cle/bin/seed-demo.php
```

For an ad-hoc script that boots WordPress:

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -r '
require getcwd()."/wp-load.php";
echo get_bloginfo("name")."\n";
'
```

## Syntax check (lint)

```bash
"$PHP" -l wp-content/plugins/habeas-cle/includes/post-types.php
```

## Querying the database directly

```bash
PW=$(grep "DB_PASSWORD" wp-config.php | sed -E "s/.*DB_PASSWORD'[, ]*'([^']*)'.*/\1/")
MYSQL_PWD="$PW" "$MYSQL" -u root --socket="$SOCK" local -e "SELECT option_value FROM wp_options WHERE option_name='wp_user_roles'\G"
```

## Included scripts

### `plugin/bin/seed-demo.php`
Creates a full sample program (1 Program, 4 Weeks, 9 Modules, scenarios, templates, events, 2 Case Updates). **Idempotent**: it marks everything with the `_hcle_demo` meta and clears previous demos before recreating.

```bash
"$PHP" -d mysqli.default_socket="$SOCK" wp-content/plugins/habeas-cle/bin/seed-demo.php
```

### `plugin/bin/setup-front-door.php`
Creates the **My Training** page (`/my-training/`) with the `my-programs` block and adds a navigation menu link. Idempotent (meta `_hcle_front_door`).

```bash
"$PHP" -d mysqli.default_socket="$SOCK" wp-content/plugins/habeas-cle/bin/setup-front-door.php
```

## Tests

Dependency-free smoke tests (no PHPUnit/composer needed) cover the critical
paths — access control, enrollment, progress, relationships, protected files,
and the REST guard. They boot WordPress, create isolated fixtures, assert, and
clean up. Exit code is non-zero on failure, so they can gate CI later.

```bash
"$PHP" -d mysqli.default_socket="$SOCK" wp-content/plugins/habeas-cle/tests/smoke-test.php
echo "exit=$?"   # 0 = all passed, 1 = a test failed
```

> Upgrade path (Option C): migrate these to WP-PHPUnit (`WP_UnitTestCase`) with a
> dedicated test database and run them in CI. See [ROADMAP.md](ROADMAP.md).

## Syncing repo ↔ live site

Because the repo is a snapshot, use `bin/sync.sh`:

```bash
# Pull changes from the live site into the repo (before committing)
bin/sync.sh pull

# Push the repo's code to the live site (after a clone/change)
bin/sync.sh push
```

Paths are configurable via the `HCLE_PLUGIN_LIVE` and `HCLE_THEME_LIVE` environment variables.

## Testing the flow as a student

To view the site from a student's perspective without a real password, you can generate a valid auth cookie:

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -r '
require getcwd()."/wp-load.php";
$u=2; $exp=time()+3600; // student id
echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u,$exp,"logged_in").";";
echo (defined("SECURE_AUTH_COOKIE")?SECURE_AUTH_COOKIE:AUTH_COOKIE)."=".wp_generate_auth_cookie($u,$exp,defined("SECURE_AUTH_COOKIE")?"secure_auth":"auth");
'
# Then: curl -H "Cookie: <the above>" http://tps.local/program/...
```

## Protected files (uploads)

Files uploaded while editing a CLE post are stored in `wp-content/uploads/hcle-protected/`
and served only through the guarded endpoint `?hcle_download=<attachment_id>`, which
checks per-program access before streaming. Attachment URLs for these files are
rewritten to that endpoint automatically.

Direct HTTP access to the raw path is blocked by an `.htaccess` on **Apache**. On
**nginx** (Local, and many managed hosts) add this rule to the server block:

```nginx
location ^~ /wp-content/uploads/hcle-protected/ {
    deny all;
    return 404;
}
```

Without it, nginx would still serve the raw file if the exact URL is guessed — the
endpoint + URL rewriting hide it, but the server rule is what truly blocks it. Add
the rule before the first real pilot.

## Code conventions

- `hcle_` prefix on functions; `HCLE_` on constants.
- Standards from WordPress: escape on output (`esc_html`, `esc_url`, `esc_attr`), nonces in forms, capability checks on every save.
- No build step: blocks are server-rendered (`render_callback`).
