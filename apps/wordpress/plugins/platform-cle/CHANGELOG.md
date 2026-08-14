# Changelog

All notable changes to Platform CLE.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses semantic versioning.

## [Unreleased] — Pilot-ready hardening

### Changed

- **"Week" is now "Unit".** The level between a programme and its modules was
  called a Week, which promised a shape the content does not have: a stage of a
  course is rarely exactly seven days, and naming it after a duration invited
  everyone to plan around one. It is now a Unit, everywhere — labels, URLs, the
  API, the post type (`pcle_week` → `pcle_unit`) and the relationship meta
  (`_pcle_week_id` → `_pcle_unit_id`).

  Renaming only the labels was the cheaper option and was rejected: it would
  have left the code saying one thing and the screen another, and this project
  has already paid for that kind of drift once, in its documentation. Schema
  v3 moves the existing rows, idempotently — without it an install would keep
  its units as an unregistered post type, invisible in the admin, and every
  module and session would lose its parent.

  Genuine calendar arithmetic is untouched: the demo seeder still places
  sessions a real week apart, and the duplicate feature still shifts dates by
  whole weeks.

### Security

- **Closed a per-program leak in REST collection listings.** The `rest_pre_dispatch`
  guard below enforced enrollment on single items (`/wp/v2/pcle_module/<id>`) but
  answered *listings* (`/wp/v2/pcle_module`) with only "is this a participant?".
  Any account holding the CLE Student role — including one enrolled in nothing —
  could therefore read every program's weeks, modules, scenarios, templates and
  events, with rendered content, in a single request. Listings are now narrowed
  per query to the items the reader may actually see (`pcle_restrict_rest_collection()`,
  hooked on `rest_{$post_type}_query`). Case Updates stay visible to all
  participants, as designed. Verified against a live install: non-enrolled
  student → 0 items, enrolled student → their program only, staff → everything,
  anonymous → 401.
- **Model answers are now gated on program access, not just capability.**
  `[pcle_model_answer]` checked `reveal_model_answers`, which every CLE Student
  holds by virtue of the role, so a student from another cohort received the
  answers rendered inside the scenario body. It now also requires access to the
  post being rendered, and fails closed when that post cannot be determined.
- **Protected file delivery.** Files attached to CLE content are stored in
  `uploads/pcle-protected/` and served only through a guarded endpoint
  (`?pcle_download=<id>`) that enforces per-program access; attachment URLs are
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
  the new `pcle_user_enrolled` action, fired only on genuinely new enrollments)
  and a WP-Cron **session reminder** for live sessions within the next 24h
  (de-duplicated per event date). Subjects/bodies filterable. Sending uses
  `wp_mail` — configure SMTP on the host for deliverability.
- **Health check endpoint** (`includes/health.php`):
  `GET /wp-json/platform-cle/v1/health` returns `{status, version}` publicly, plus
  configuration `checks` (roles, cron, protected dir, front door) for admins.
  For deploy verification and uptime monitoring.
- **Deployment runbook** ([docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)) — Local →
  production steps and a go-live checklist.
- The **"My Training" front-door page is auto-created on activation**
  (`pcle_ensure_front_door_page()`), so a fresh install needs no CLI script;
  adding it to the nav menu remains a one-time manual step.

## [0.1.0] — 2026-06-23

First functional release. Implements every feature from the brief.

### Added

- **Custom Post Types** (7): Program, Week, Module, Practice Scenario, Template, Schedule Event, Case Update. Grouped into 2 capability groups (`content`, `case_update`).
- **Roles and capabilities:** CLE Student, CLE Instructor, and an extended Administrator. Custom caps `view_cle_content`, `reveal_model_answers`, `view_participant_progress`.
- **Per-program access control:** login gate + enrollment check (`pcle_can_access_post`). REST API protection. Search filtering.
- **Model answers** protected server-side via `[pcle_model_answer]`.
- **Hierarchical relationships** via post meta (Program → Week → Module → Scenario/Template; Event per week), with a select meta box, a "Parent" admin column, and a query API.
- **Progress tracking** (user meta) with per-week and per-program computation, REST endpoint `platform-cle/v1/progress`, a live-updating frontend button, and per-week progress bars on the program page.
- **Per-program enrollment** (user meta) with a "Participants & Enrollment" management screen.
- **Session dates** on Schedule Events (datetime meta box + validation that rejects overflow).
- **Block theme** (child of Twenty Twenty-Five) with 7 `single-*` templates.
- **Dynamic blocks:** curriculum-children, progress-bar, complete-button, event-datetime, breadcrumbs, my-programs.
- **Front door** "My Training" (page + menu link) and navigation **breadcrumbs**.
- **Scripts:** `seed-demo.php` (idempotent sample data) and `setup-front-door.php`.

### Fixed

- The CPTs were registered with `public => false`, which left `publicly_queryable => false` and made the single views return 404 (the access gate never ran). Fixed with `publicly_queryable => true` + `exclude_from_search`.
