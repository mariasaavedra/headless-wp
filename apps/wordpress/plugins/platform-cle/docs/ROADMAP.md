# Roadmap & viability notes

Living document. Captures the viability audit, what is running in production,
and the planned milestones — including the decisions behind them, so they do not
live only in chat.

---

## Viability verdict

Well-architected for its stated purpose (a lightweight, authenticated learning
platform). Clean separation of plugin logic vs theme presentation, native WP
roles/caps, single sources of truth for capabilities and relationships. The gaps
below are **additions, not rewrites** — and most of the ones this audit opened
have since been closed.

**What the critical path is now.** Not the host — that arrived. The plugin has
been serving `platform.thepen-and-swordkc.org` since 18 September 2026, the
Next.js app serves `armory.thepen-and-swordkc.org`, and the plugin deploys
through a workflow rather than by hand ([DEPLOYMENT.md](DEPLOYMENT.md)).

What remains is of three kinds, and only the first is engineering:

- **Mail that arrives.** No SMTP is configured, and the subdomain has no SPF or
  DKIM record. Everything the platform sends — invitations, enrolment
  confirmations, session reminders, password resets — leaves the server with
  nothing vouching for it. This now gates more than it did: the frontend can
  create accounts, and the link to set a password travels by email.
- ~~**Tests on `apps/web`.**~~ Closed: 27 Playwright tests run in CI against a
  real WordPress, covering signing in, what each role is offered, and managing
  a cohort. What they do not cover yet is the builder and the participant's
  path through a programme to a quiz.
- **Business input.** The accreditation identity certificates need, and a
  payment provider. Neither is blocked by code any more.

## Open at a glance

Everything still outstanding, shortest path first. Everything not listed here has
been closed — see the findings below for what and how.

| | Item | Blocked on |
|---|---|---|
| 🟡 | Confirm the host's backups run and restore | — minutes, and the deploy workflow writes to production |
| 🟡 | SMTP delivery: nothing the platform emails is vouched for | a choice — relay through the org's Microsoft 365, or a transactional provider |
| 🟡 | An administrator account's display name is the address it was created from, so it is the public byline on anything it authors | — wp-admin, data not code |
| 🟡 | Login rate limiting / brute-force protection | — SG Security is active on the host; verify what it already covers before building |
| 🟡 | Certificates: provider numbers, signatory, per-bar wording | **owner** — accreditation input |
| 🟡 | Payment-driven enrollment | provider choice |
| 🟡 | Blocks lack `block.json` (invisible in the editor inserter) | — |
| 🟡 | No i18n catalog (`.pot`) | — |
| 🟡 | `FROM wordpress:latest` is unpinned | — |
| 🟡 | Progress computation is N+1; nothing cached | — no measurement yet says it costs anything at this size |
| 🟡 | Live sessions carry a date but no video/conferencing link | — |
| 🟢 | Deleting a parent from wp-admin still orphans children | — |

Closed since the audit and not listed above: the production host, backups aside;
the deploy pipeline; enrolment, removal, invitations and role changes from the
frontend; and end-to-end tests on `apps/web`.

---

## In production

| | Host | Deploys by |
|---|---|---|
| Plugin + theme | `platform.thepen-and-swordkc.org` (SiteGround) | [`deploy-plugin.yml`](../../../../.github/workflows/deploy-plugin.yml), run by hand |
| Next.js app | `armory.thepen-and-swordkc.org` (Vercel) | itself, on every merge to `main` |

Live since 18 September 2026. The asymmetry is the thing to remember: a merge
publishes the app and does nothing to the plugin, so a change that spans both
halves goes plugin-first or the app calls an endpoint that is not there yet.
The runbook, the secrets it needs and the guard rails are in
[DEPLOYMENT.md](DEPLOYMENT.md).

Automatic plugin deploys on merge are written and commented out in that
workflow, to enable once it has been driven by hand a few more times.

---

## Enrolment from the frontend

Instructors and administrators manage a cohort from the report screen rather
than wp-admin. Built in phases, because the risk is not evenly spread.

| Phase | Scope | Status |
|---|---|---|
| 1 | Enrol and remove people who already have accounts | ✅ shipped |
| 2 | Create accounts for unknown addresses, WordPress mails the set-password link | ✅ shipped |
| 3 | Change roles, and a screen listing who has an account | ✅ shipped |

**Decisions made, so they do not live only in chat:**

- `pcle_bulk_enroll()` is the single implementation. wp-admin and the REST route
  both call it, so what "a, b; c" means, who counts as already enrolled and what
  happens to an address nobody holds are answered once.
- **No password is ever chosen, transported or seen by the app.** Account
  creation hands `wp_insert_user()` a random password nobody reads and lets
  WordPress send the reset link. Phase 3 does not change this.
- Creating accounts takes `create_users`; an instructor has neither the
  capability nor the control. Asking for it without the capability is not an
  error — the known addresses in the same paste are still enrolled, and
  `create_permitted` says why the rest were not.
- Removal deletes the enrolment row only. Accounts, progress, attendance and
  quiz attempts survive, which is what makes the button safe without a
  confirmation step.
- **Phase 3 obeys four rules the first two did not need**, each asserted in the
  smoke suite: only CLE Student and CLE Instructor are grantable, never your
  own role, never an administrator's, and nothing here deletes an account —
  progress, attendance and quiz attempts hang off a user id, and a credit claim
  may depend on them. The grantable set is stated once, in
  `pcle_grantable_roles()`, rather than derived from the roles WordPress
  happens to have: a list that grows by itself is how a role nobody discussed
  becomes grantable from a dropdown.

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
- 🟡→✅ No email notifications. **Fixed** (`emails.php`; Option A #4). Delivery
  is the open half and is now the platform's sharpest gap: the host sends
  through its local MTA with no SMTP plugin, and `platform.thepen-and-swordkc.org`
  publishes neither SPF nor DKIM. Mail leaves; nothing vouches for it.

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
  577 assertions across 35 sections) and now for `apps/web` too: 27 Playwright
  tests in `apps/web/e2e/`, run in CI against a real stack rather than a
  mocked backend — what they check is precisely what depends on WordPress.
  They arrange their own fixtures through the enrolment and people APIs
  instead of inheriting whatever the last run left behind. The builder and the
  participant's path to a quiz are not covered yet.
- 🟡 Blocks registered without `block.json` → not in the editor inserter.
- 🟡 No i18n catalog (`.pot`).
- 🟡→✅ Monorepo was a manual `rsync` snapshot with no CI. **Fixed**: the plugin
  and theme are bind-mounted from the repo into the container, and
  `.github/workflows/ci.yml` runs the smoke suite against a real stack plus a
  web lint/build on every push and PR. `plugin/bin/sync.sh` survives from the
  Local by Flywheel era and is no longer part of any workflow.

**Ops**
- 🟡→✅ No staging or production host, backups, or deploy pipeline. **Mostly
  fixed.** Both halves are live (see In production above) and the plugin
  deploys through a workflow that runs the smoke suite, refuses a target that
  does not already hold the plugin, re-checks that the host matches the
  repository afterwards, and fails if the health endpoint stops answering.
  **Remaining:** no staging environment — a plugin release is rehearsed with a
  dry run against production rather than tried somewhere else first — and the
  host's backups have not been confirmed to run or to restore.
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
| 5 | Smoke tests on access-control, progress, files, REST | ✅ done (`tests/smoke-test.php`, 577 assertions across 35 sections, dependency-free); green in CI on every push |
| 6 | Deploy prep (health check + runbook) | ✅ done (`includes/health.php` + [DEPLOYMENT.md](DEPLOYMENT.md)), and since 18 Sep 2026 actually deployed: host, DNS and a deploy workflow all live. Backups remain unconfirmed. |

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
- ~~**Hard prerequisite:** production host + SSL~~ — satisfied since 18 Sep 2026.
  What is left is the provider choice and the decisions above; live payments can
  be tested against the production host when there is one to test.

---

## Later options (post-pilot, from the audit)

- **Option B — CLE-grade platform:** attendance tracking, completion certificates
  (PDF), credit-hour tracking & compliance reporting, video/Zoom integration,
  richer instructor dashboards. *(Highest mission value for a CLE.)*
- **Option C — Scale & engineering maturity:** relational data model for
  enrollment/progress with reporting, `block.json` editor integration, i18n,
  CI/CD + tests, caching, versioned migrations.
