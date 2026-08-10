# Changelog

All notable changes to Habeas CLE.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses semantic versioning.

## [Unreleased] — Pilot-ready hardening

### Security

- **Protected file delivery.** Files attached to CLE content are stored in
  `uploads/hcle-protected/` and served only through a guarded endpoint
  (`?hcle_download=<id>`) that enforces per-program access; attachment URLs are
  rewritten to that endpoint so the raw path is never exposed. Includes an
  `.htaccess` deny (Apache) and a documented nginx rule. (`includes/protected-files.php`)
- **Fixed an ineffective REST guard.** The previous guard hooked a non-existent
  `rest_{$post_type}_item_permissions_check` filter (a no-op), leaving published
  CPT items readable via `/wp-json/` by anyone. Replaced with a `rest_pre_dispatch`
  guard enforcing per-program access on reads. Verified: anonymous → 401,
  non-enrolled student → 403, enrolled student/staff → 200.

### Added

- **Bulk enrollment by email** on the Participants & Enrollment screen: paste a
  list of emails to enroll a cohort at once. Administrators can create Student
  accounts for unknown emails (with a set-password email); instructors enroll
  existing students only.
- **Smoke test suite** (`tests/smoke-test.php`) — dependency-free tests covering
  access control, enrollment, progress, relationships, protected files, the REST
  guard, and emails (41 assertions). Non-zero exit on failure for CI.
- **Transactional emails** (`includes/emails.php`): enrollment confirmation (via
  the new `hcle_user_enrolled` action, fired only on genuinely new enrollments)
  and a WP-Cron **session reminder** for live sessions within the next 24h
  (de-duplicated per event date). Subjects/bodies filterable. Sending uses
  `wp_mail` — configure SMTP on the host for deliverability.
- **Health check endpoint** (`includes/health.php`):
  `GET /wp-json/habeas-cle/v1/health` returns `{status, version}` publicly, plus
  configuration `checks` (roles, cron, protected dir, front door) for admins.
  For deploy verification and uptime monitoring.
- **Deployment runbook** ([docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)) — Local →
  production steps and a go-live checklist.
- The **"My Training" front-door page is auto-created on activation**
  (`hcle_ensure_front_door_page()`), so a fresh install needs no CLI script;
  adding it to the nav menu remains a one-time manual step.

## [0.1.0] — 2026-06-23

First functional release. Implements every feature from the brief.

### Added

- **Custom Post Types** (7): Program, Week, Module, Practice Scenario, Template, Schedule Event, Case Update. Grouped into 2 capability groups (`content`, `case_update`).
- **Roles and capabilities:** CLE Student, CLE Instructor, and an extended Administrator. Custom caps `view_cle_content`, `reveal_model_answers`, `view_participant_progress`.
- **Per-program access control:** login gate + enrollment check (`hcle_can_access_post`). REST API protection. Search filtering.
- **Model answers** protected server-side via `[hcle_model_answer]`.
- **Hierarchical relationships** via post meta (Program → Week → Module → Scenario/Template; Event per week), with a select meta box, a "Parent" admin column, and a query API.
- **Progress tracking** (user meta) with per-week and per-program computation, REST endpoint `habeas-cle/v1/progress`, a live-updating frontend button, and per-week progress bars on the program page.
- **Per-program enrollment** (user meta) with a "Participants & Enrollment" management screen.
- **Session dates** on Schedule Events (datetime meta box + validation that rejects overflow).
- **Block theme** (child of Twenty Twenty-Five) with 7 `single-*` templates.
- **Dynamic blocks:** curriculum-children, progress-bar, complete-button, event-datetime, breadcrumbs, my-programs.
- **Front door** "My Training" (page + menu link) and navigation **breadcrumbs**.
- **Scripts:** `seed-demo.php` (idempotent sample data) and `setup-front-door.php`.

### Fixed

- The CPTs were registered with `public => false`, which left `publicly_queryable => false` and made the single views return 404 (the access gate never ran). Fixed with `publicly_queryable => true` + `exclude_from_search`.
