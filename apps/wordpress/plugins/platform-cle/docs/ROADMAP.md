# Roadmap & viability notes

Living document. Captures the viability audit, what's being hardened for a pilot,
and the planned milestones (so decisions don't live only in chat).

---

## Viability verdict

Well-architected for its stated purpose (a lightweight, authenticated learning
platform). Clean separation of plugin logic vs theme presentation, native WP
roles/caps, single sources of truth for capabilities and relationships. The gaps
below are **additions, not rewrites** — and most of the ones this audit opened
have since been closed.

**What the critical path is now.** Not engineering. Email delivery, payment-driven
enrollment and real certificates each sit behind the same prerequisite: a
production host with SSL. Everything reachable without one has largely been
built.

## Open at a glance

Everything still outstanding, shortest path first. Everything not listed here has
been closed — see the findings below for what and how.

| | Item | Blocked on |
|---|---|---|
| 🟡 | Production host, backups, deploy pipeline | **owner** — the critical path |
| 🟡 | SMTP delivery for enrollment and reminder emails | production host |
| 🟡 | Certificates: provider numbers, signatory, per-bar wording | **owner** — accreditation input |
| 🟡 | Payment-driven enrollment | provider choice + production host |
| 🟡 | No login rate limiting / brute-force protection | — |
| 🟡 | Blocks lack `block.json` (invisible in the editor inserter) | — |
| 🟡 | No i18n catalog (`.pot`) | — |
| 🟡 | `apps/web` has no tests; CI only lints and builds it | — |
| 🟡 | `FROM wordpress:latest` is unpinned | — |
| 🟡 | Progress computation is N+1; nothing cached | — |
| 🟡 | Live sessions carry a date but no video/conferencing link | — |
| 🟢 | Deleting a parent from wp-admin still orphans children | — |

---

## Findings from the audit

Severity: 🔴 blocker · 🟡 important · 🟢 fine.

**Security**
- 🔴→✅ Uploaded files were served directly from `uploads/`, bypassing the login
  gate. **Fixed** (protected file delivery — see ARCHITECTURE §9).
- 🔴→✅ REST guard hooked a non-existent filter (no-op); published CPT items were
  readable via `/wp-json/` by anyone. **Fixed** (`rest_pre_dispatch` guard).
- 🔴→✅ That fix covered single items only: **collection listings** still went out
  to any participant, so a student enrolled in nothing could read every
  program's content — model answers included — from `/wp/v2/pcle_module` and
  friends. **Fixed** (per-query narrowing + model answers gated on program
  access). Worth remembering as a pattern: in the REST API, "one item" and "a
  list of items" are separate routes, and a guard written for one is not a
  guard on the other.
- 🟡 No brute-force / rate limiting on login (standard WP concern).

**Domain / CLE-specific (largest product gaps)**
- 🔴→🟡 (product) No CLE-credit / MCLE compliance. **Mostly resolved.** Credit
  hours per jurisdiction (`credits.php`), attendance at live sessions
  (`attendance.php`) and cohort reporting including both (`reports.php`, plus
  the CSV export) are built and covered by the smoke suite. Certificates
  (`certificates.php`) are deliberately a **scaffold**: the mechanism works and
  draws on real records, but the accreditation identity — the provider numbers
  each bar issues, the authorised signatory, the wording a bar requires on the
  face of the document — is a business input, not something code can supply.
  That input is the only thing left on this item.
- 🟡 Live sessions have no video/conferencing integration (just a date).
- 🟡→✅ No email notifications. **Fixed** (`emails.php`; Option A #4). Still
  needs SMTP on the production host to actually deliver.

**Data model / scale**
- 🟡→✅ Enrollment & progress were serialized user-meta arrays that could not be
  queried. **Fixed**: both are real tables now (`pcle_enrollments`,
  `pcle_progress`), one row per pair, carrying the completion timestamp that a
  credit claim needs and a serialized array had no room for.
  `pcle_migrate_legacy_meta()` moves existing installs across. A cohort report
  is five queries at any size rather than a loop over every user.
- 🟡→🟢 Relationships had no referential integrity. **Largely fixed**: a child
  cannot be reparented onto the wrong type, a non-existent post, or itself
  (asserted in the smoke suite); the authoring API refuses a delete that would
  orphan descendants unless the caller explicitly asks for a cascade; and
  `deleted_post` clears the tables. **Remaining gap:** deleting a parent from
  wp-admin still orphans its children — only the authoring API guards that.
- 🟡 Progress computation is still N+1 (loops per unit/module) and nothing is
  cached. Reporting no longer goes through those helpers, so the sharp edge is
  gone; the per-page cost remains.

**Engineering practices**
- 🟡→✅ No automated tests. **Fixed** for the plugin (`tests/smoke-test.php`,
  398 assertions across 31 sections). `apps/web` still has none: CI lints and
  builds it, and nothing more.
- 🟡 Blocks registered without `block.json` → not in the editor inserter.
- 🟡 No i18n catalog (`.pot`).
- 🟡→✅ Monorepo was a manual `rsync` snapshot with no CI. **Fixed**: the plugin
  and theme are bind-mounted from the repo into the container, and
  `.github/workflows/ci.yml` runs the smoke suite against a real stack plus a
  web lint/build on every push and PR. `plugin/bin/sync.sh` survives from the
  Local by Flywheel era and is no longer part of any workflow.

**Ops**
- 🟡 No staging or production host, backups, or deploy pipeline. Local
  development is Docker Compose now, not Local by Flywheel, but neither is a
  place to run a cohort. **This is the critical path** — SMTP delivery, payments
  and real certificates all sit behind it.
- 🟡 `apps/wordpress/Dockerfile` is `FROM wordpress:latest` — unpinned, so a
  rebuild can change WordPress version underneath you. Pin it. (This is also the
  likely source of the puzzling "version 7.0" this audit originally recorded.)
- 🟡→✅ No data-model versioning. **Fixed**: `PCLE_DB_VERSION` (currently 4) with
  the applied version in the `pcle_db_version` option, `dbDelta` migrations, and
  `pcle_maybe_upgrade_schema()` running them on upgrade.

---

## Option A — Pilot-ready hardening (complete)

Goal: run the first real 4-week cohort safely.

| # | Item | Status |
|---|------|--------|
| 1 | Protected file delivery | ✅ done + verified E2E |
| 2 | Per-program REST guard (fix the no-op) | ✅ done + verified E2E |
| 3 | Bulk enrollment by email | ✅ done + verified |
| 4 | Emails (enrollment confirmation + session reminder) | ✅ done (`includes/emails.php`); verified via wp_mail capture. Needs SMTP on the host for real delivery. |
| 5 | Smoke tests on access-control, progress, files, REST | ✅ done (`tests/smoke-test.php`, 398 assertions across 31 sections, dependency-free); green in CI on every push |
| 6 | Deploy prep (health check + runbook) | ✅ done (`includes/health.php` + [DEPLOYMENT.md](DEPLOYMENT.md)); host/backups/DNS remain owner-driven |

---

## Milestone: payment-driven enrollment (production model)

**Decision (deferred):** in production, students are enrolled when they **pay**.
Provider not yet chosen — plan only for now; the pilot uses bulk/manual enrollment.

**Design — the "bridge" pattern.** `pcle_enroll_user($program_id, $user_id)` is the
single enrollment primitive. Payment is only the *trigger*. Any provider does the
same four things:

1. Map each **Program** to a product/price (store the provider's price/product ID
   in a program meta).
2. Listen for the **payment-confirmed** event (webhook/hook).
3. Find-or-create the WP user by email, enroll them in the mapped program, send
   confirmation.
4. Be **idempotent** (webhooks retry — never double-enroll).

**Provider options**
- **Stripe Checkout (recommended for a lean nonprofit):** hosted checkout (PCI on
  Stripe), nonprofit discount; we build a small `checkout.session.completed`
  webhook → bridge. Best if selling simple program seats.
- **WooCommerce:** full commerce (receipts, tax, refunds, coupons, catalog); a
  short `woocommerce_payment_complete` → bridge. Heavier; better for formal
  invoicing / multiple products.

**Open decisions when we build it**
- Pricing model: per program? per seat? donation / sliding scale?
- Account creation at checkout (email → user).
- Refund → auto-unenroll?
- Receipts / invoices (relevant if issuing CLE credit).
- **Hard prerequisite:** production host + SSL (Option A #6); live payments can't
  be tested on Local.

---

## Later options (post-pilot, from the audit)

- **Option B — CLE-grade platform:** attendance tracking, completion certificates
  (PDF), credit-hour tracking & compliance reporting, video/Zoom integration,
  richer instructor dashboards. *(Highest mission value for a CLE.)*
- **Option C — Scale & engineering maturity:** relational data model for
  enrollment/progress with reporting, `block.json` editor integration, i18n,
  CI/CD + tests, caching, versioned migrations.
