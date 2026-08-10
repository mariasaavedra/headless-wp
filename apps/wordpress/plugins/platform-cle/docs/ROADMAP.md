# Roadmap & viability notes

Living document. Captures the viability audit, what's being hardened for a pilot,
and the planned milestones (so decisions don't live only in chat).

---

## Viability verdict

Well-architected for its stated purpose (a lightweight, authenticated learning
platform — MVP level). Clean separation of plugin logic vs theme presentation,
native WP roles/caps, single sources of truth for capabilities and relationships.
The gaps below are **additions, not rewrites**.

## Findings from the audit

Severity: 🔴 blocker · 🟡 important · 🟢 fine.

**Security**
- 🔴→✅ Uploaded files were served directly from `uploads/`, bypassing the login
  gate. **Fixed** (protected file delivery — see ARCHITECTURE §9).
- 🔴→✅ REST guard hooked a non-existent filter (no-op); published CPT items were
  readable via `/wp-json/` by anyone. **Fixed** (`rest_pre_dispatch` guard).
- 🟡 No brute-force / rate limiting on login (standard WP concern).

**Domain / CLE-specific (largest product gaps)**
- 🔴 (product) No CLE-credit / MCLE compliance: no attendance verification for
  live sessions, no completion certificates, no credit-hours tracking/reporting.
- 🟡 Live sessions have no video/conferencing integration (just a date).
- 🟡 No email notifications yet.

**Data model / scale**
- 🟡 Enrollment & progress are serialized user-meta arrays. Fine for tens–low
  hundreds; "who is enrolled in program X" iterates users in PHP and can't be
  queried/reported via SQL.
- 🟡 Relationships have no referential integrity (deleting a parent orphans
  children; no cascade).
- 🟡 Progress computation is N+1 (loops per week/module); no caching.

**Engineering practices**
- 🟡 No automated tests (planned — Option A #5).
- 🟡 Blocks registered without `block.json` → not in the editor inserter.
- 🟡 No i18n catalog (`.pot`).
- 🟡 Monorepo is a manual `rsync` snapshot (divergence risk); no CI.

**Ops**
- 🟡 Only on Local; no staging/production host, backups, or deploy pipeline.
- 🟡 WordPress version reported as "7.0" — verify (current stable is 6.x).
- 🟡 No data-model versioning (no upgrade path for future schema changes).

---

## Option A — Pilot-ready hardening (in progress)

Goal: run the first real 4-week cohort safely.

| # | Item | Status |
|---|------|--------|
| 1 | Protected file delivery | ✅ done + verified E2E |
| 2 | Per-program REST guard (fix the no-op) | ✅ done + verified E2E |
| 3 | Bulk enrollment by email | ✅ done + verified |
| 4 | Emails (enrollment confirmation + session reminder) | ✅ done (`includes/emails.php`); verified via wp_mail capture. Needs SMTP on the host for real delivery. |
| 5 | Smoke tests on access-control, progress, files, REST | ✅ done (`tests/smoke-test.php`, 36 assertions, dependency-free) |
| 6 | Deploy prep (health check + runbook) | ✅ done (`includes/health.php` + [DEPLOYMENT.md](DEPLOYMENT.md)); host/backups/DNS remain owner-driven |

---

## Milestone: payment-driven enrollment (production model)

**Decision (deferred):** in production, students are enrolled when they **pay**.
Provider not yet chosen — plan only for now; the pilot uses bulk/manual enrollment.

**Design — the "bridge" pattern.** `hcle_enroll_user($program_id, $user_id)` is the
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
