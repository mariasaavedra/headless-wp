# Deployment guide (Local → production)

Going live is a standard WordPress migration plus a few Platform-CLE-specific
steps. Infrastructure (host, domain, DNS, SSL) is owner-provided; this document
is the runbook and go-live checklist.

---

## Where production lives today

| Host | What it is |
|------|------------|
| `platform.thepen-and-swordkc.org` | The live WordPress: this plugin, this theme, and the system of record. |
| `armory.thepen-and-swordkc.org` | The Next.js app (`apps/web`) on Vercel, which talks to the host above and to nothing else. |
| `thepen-and-swordkc.org` | The organisation's marketing site, on different hosting. **Not part of this deployment** — do not migrate onto it or point the app at it. |

The commands below use `platform.thepen-and-swordkc.org`; substitute your own
host when standing up a second environment. Whatever host you choose, the
Vercel project's `WORDPRESS_API_URL` and `WORDPRESS_SITE_URL` have to be
repointed at it, or the Armory keeps reading the old one.

---

## Updating the plugin on a live site

Sections 0 to 7 below are the one-time migration. A routine release is only
the plugin directory, and there are two ways to move it.

### Automated (GitHub Actions)

[`.github/workflows/deploy-plugin.yml`](../../../../.github/workflows/deploy-plugin.yml)
runs the smoke suite, rsyncs `plugin/` to the host, purges the cache and
checks the health endpoint. **Actions → Deploy plugin → Run workflow.** Leave
`dry run` ticked the first time: it prints the exact list of files it would
add, change and delete, and writes nothing.

It needs five repository secrets (Settings → Secrets and variables → Actions):
`SSH_HOST`, `SSH_USER`, `SSH_PORT` (18765 on SiteGround), `SSH_PRIVATE_KEY`
and `REMOTE_PLUGIN_PATH`. Optionally `SSH_KNOWN_HOSTS` pins the host key
instead of trusting whatever answers on the first connection. Without the
secrets the workflow fails at its first SSH step, so merging it arms nothing.

Four things it refuses to do:

- Deploy without the smoke suite passing first — the deploy job needs it.
- Sync when `REMOTE_PLUGIN_PATH` does not already contain `platform-cle.php`.
  `rsync --delete` against a mistyped path would empty a directory nobody
  meant to touch.
- Call itself done while anything still differs: it re-runs rsync in dry-run
  mode afterwards and fails on any remaining change.
- Stay quiet about a broken site — it fails if
  `/wp-json/platform-cle/v1/health` does not report `ok` when it is finished.

Deploying on every merge that touches the plugin is a commented-out `push`
trigger in the same file. Uncomment it once a few manual runs have done the
right thing.

### By hand

Build a zip whose root is a `platform-cle/` directory, then wp-admin →
Plugins → Add New → Upload Plugin, and choose **Replace current with
uploaded**:

```bash
cd apps/wordpress/plugins/platform-cle
rm -rf /tmp/pcle && mkdir -p /tmp/pcle/platform-cle
rsync -a --exclude '.DS_Store' plugin/ /tmp/pcle/platform-cle/
( cd /tmp/pcle && zip -rq /tmp/platform-cle.zip platform-cle )
```

Then purge SG Cache in wp-admin and check the health endpoint. An upload does
not remove files that were deleted from the repository, so when a release
deletes one, use the workflow or plain SSH instead.

### Either way

Neither path touches the database, and neither needs the plugin deactivated:
`pcle_maybe_upgrade_schema()` runs on `plugins_loaded`, so a pending
migration applies itself on the next request. Deactivate/reactivate is only
for the move to a new host, in section 3.

---

## 0. Prerequisites

- A production host: managed WordPress hosting or a VPS with **PHP 8.1+**,
  **MySQL 5.7+/MariaDB 10.4+**, and **HTTPS**.
- A domain and DNS access.
- The repo (`github.com/mariasaavedra/headless-wp`) — the plugin and theme live
  under `apps/wordpress/plugins/platform-cle/`.

## 1. Provision the host

Set up PHP 8.1+, the database, and a valid TLS certificate (Let's Encrypt is
fine). Note whether the web server is **Apache** or **nginx** — it changes one
step below (protected files).

## 2. Migrate the site

Either:

- **Migration plugin** (simplest): All-in-One WP Migration or Duplicator —
  exports files + DB and imports on the new host, handling URL rewrites.
- **Manual:** copy the files, export the DB, import it, then search-replace the
  site URL (use WP-CLI `wp search-replace 'https://old' 'https://new'` to update
  serialized data safely). Recreate `wp-config.php` with the new DB credentials
  and fresh salts.

Then install the plugin + theme — via the migration, or by deploying
`apps/wordpress/plugins/platform-cle/plugin/` to `wp-content/plugins/platform-cle/`
and `.../theme/` to `wp-content/themes/platform-cle/`.

> `bin/sync.sh` in the plugin directory is **obsolete** — it rsynced to a Local by
> Flywheel install and is not part of any current workflow.

## 3. Activate and flush

1. **Activate the `platform-cle` plugin.** Activation creates the roles, **the
   four database tables** (`pcle_enrollments`, `pcle_progress`,
   `pcle_attendance`, `pcle_quiz_attempts`), the protected-uploads directory,
   schedules the reminder cron, and flushes rewrite rules. (If it was already
   active pre-migration, deactivate + reactivate once so these run on the new
   host.) On upgrade, `pcle_maybe_upgrade_schema()` applies any pending
   migration against the `pcle_db_version` option.
2. **Activate the `platform-cle-theme`.**
3. Confirm permalinks are **Post name** (Settings → Permalinks → Save to flush).

## 4. Plugin-specific production config

- **Protected files (required).** On **nginx**, add the deny rule from
  [DEVELOPMENT.md](DEVELOPMENT.md#protected-files-uploads):
  ```nginx
  location ^~ /wp-content/uploads/pcle-protected/ { deny all; return 404; }
  ```
  On **Apache**, the `.htaccess` the plugin drops handles it — verify `AllowOverride`
  is on.
- **Email deliverability (SMTP).** `wp_mail` alone rarely delivers. Install an
  SMTP plugin (e.g. WP Mail SMTP) or use the host's transactional mailer, and
  send a test. Without this, enrollment confirmations and session reminders won't
  arrive.
- **Real cron for reminders.** WP-Cron only fires on page traffic, which is
  unreliable for time-sensitive reminders. In `wp-config.php`:
  ```php
  define( 'DISABLE_WP_CRON', true );
  ```
  and add a system cron hitting `wp-cron.php` every ~10 minutes:
  ```
  */10 * * * * curl -s https://platform.thepen-and-swordkc.org/wp-cron.php?doing_wp_cron >/dev/null 2>&1
  ```

## 5. Harden (wp-config.php)

```php
define( 'WP_DEBUG', false );
define( 'DISALLOW_FILE_EDIT', true );   // no theme/plugin editor in admin
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
```
Use fresh, unique keys/salts (https://api.wordpress.org/secret-key/1.1/salt/),
and add a login rate-limiter / 2FA plugin.

## 6. Backups

Enable **daily** host-level backups (files + DB) and **test a restore** before
launch. Keep off-site copies.

## 7. Go-live verification checklist

Run these on production before announcing:

- [ ] Health check: `GET https://platform.thepen-and-swordkc.org/wp-json/platform-cle/v1/health` returns
      `"status":"ok"`. Logged in as admin, `checks` are all `true`.
- [ ] Anonymous visit to a program URL → redirected to `wp-login.php`.
- [ ] A non-enrolled student → redirected to "My Training" with the notice.
- [ ] An enrolled student → sees the program, has no "mark complete" button; an instructor marks it from the report.
- [ ] Upload a file to a Template → its link goes through `?pcle_download=…`;
      the raw `/wp-content/uploads/pcle-protected/…` URL returns 403/404.
- [ ] REST: `curl https://platform.thepen-and-swordkc.org/wp-json/wp/v2/pcle_program` (anonymous) → 401.
- [ ] REST: `curl https://platform.thepen-and-swordkc.org/wp-json/wp/v2/users`
      (anonymous) → 401, not a list of accounts.
- [ ] Bulk-enroll a real test email → the confirmation email **arrives**.
- [ ] An enrolled student can sit a quiz and see it marked; a module whose quiz
      gates completion cannot be completed until they pass.
- [ ] A cohort report renders and its CSV downloads.
- [ ] Smoke tests pass on the server:
      `php wp-content/plugins/platform-cle/tests/smoke-test.php` → `exit 0`
      (654 assertions across 39 sections).

## Rollback

Keep the pre-launch backup. If a critical issue appears, restore the backup and
repoint DNS. All state is in the database — content in CPTs and post meta,
enrollment/progress/attendance/quiz attempts in the plugin's four tables — so a DB
restore fully reverts it. Uploads are on disk; back those up too.
