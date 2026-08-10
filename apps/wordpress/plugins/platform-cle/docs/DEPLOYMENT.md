# Deployment guide (Local → production)

Going live is a standard WordPress migration plus a few Habeas-CLE-specific
steps. Infrastructure (host, domain, DNS, SSL) is owner-provided; this document
is the runbook and go-live checklist.

---

## 0. Prerequisites

- A production host: managed WordPress hosting or a VPS with **PHP 8.1+**,
  **MySQL 5.7+/MariaDB 10.4+**, and **HTTPS**.
- A domain and DNS access.
- The repo (`github.com/rafaelcd8892/habeas-cle`) for the plugin + theme.

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

Then install the plugin + theme (via the migration, or `bin/sync.sh push` /
`git` on the server).

## 3. Activate and flush

1. **Activate the `habeas-cle` plugin.** Activation creates the roles, the
   protected-uploads directory, schedules the reminder cron, and flushes rewrite
   rules. (If it was already active pre-migration, deactivate + reactivate once
   so these run on the new host.)
2. **Activate the `habeas-cle-theme`.**
3. Confirm permalinks are **Post name** (Settings → Permalinks → Save to flush).

## 4. Plugin-specific production config

- **Protected files (required).** On **nginx**, add the deny rule from
  [DEVELOPMENT.md](DEVELOPMENT.md#protected-files-uploads):
  ```nginx
  location ^~ /wp-content/uploads/hcle-protected/ { deny all; return 404; }
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
  */10 * * * * curl -s https://YOURSITE/wp-cron.php?doing_wp_cron >/dev/null 2>&1
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

- [ ] Health check: `GET https://YOURSITE/wp-json/habeas-cle/v1/health` returns
      `"status":"ok"`. Logged in as admin, `checks` are all `true`.
- [ ] Anonymous visit to a program URL → redirected to `wp-login.php`.
- [ ] A non-enrolled student → redirected to "My Training" with the notice.
- [ ] An enrolled student → sees the program, can mark a module complete.
- [ ] Upload a file to a Template → its link goes through `?hcle_download=…`;
      the raw `/wp-content/uploads/hcle-protected/…` URL returns 403/404.
- [ ] REST: `curl https://YOURSITE/wp-json/wp/v2/hcle_program` (anonymous) → 401.
- [ ] Bulk-enroll a real test email → the confirmation email **arrives**.
- [ ] Smoke tests pass on the server:
      `php wp-content/plugins/habeas-cle/tests/smoke-test.php` → `exit 0`.

## Rollback

Keep the pre-launch backup. If a critical issue appears, restore the backup and
repoint DNS. Because enrollment/progress live in user meta and content in CPTs, a
DB restore fully reverts state.
