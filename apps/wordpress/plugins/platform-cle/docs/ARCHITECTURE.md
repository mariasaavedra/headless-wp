# Technical architecture

Reference document for the internal design of Platform CLE.

---

## Guiding principle

**The plugin owns the logic; the presentation layer only presents.** Anything that is business rules (what exists, who can view/edit it, how content relates, how progress is computed) lives in the plugin.

There are now **two** presentation layers, and the principle holds for both:

- the **block theme**, which places dynamic blocks the plugin renders;
- **`apps/web`**, the Next.js app, which consumes the plugin's REST API.

Neither decides anything. The Next.js app in particular offers a reader only the
routes their role allows, but every route is guarded server-side regardless —
what the frontend chooses to *offer* is never what decides what is *allowed*.

## Plugin file map

Listed in load order (`platform-cle.php` requires them in this sequence; `schema.php`
comes first because everything storage-backed depends on the tables existing).

| File | Responsibility |
|---|---|
| `platform-cle.php` | Bootstrap: defines constants, `require_once` of the modules, activation/deactivation hooks. |
| `includes/schema.php` | The four custom tables, `PCLE_DB_VERSION`, migrations, and record cleanup on user/post delete. |
| `includes/post-types.php` | Registers the 8 CPTs and the capability groups. |
| `includes/roles.php` | Creates roles and assigns capabilities. |
| `includes/access-control.php` | Login gate + per-program access + REST protection + `[pcle_model_answer]`. |
| `includes/relationships.php` | Hierarchy via post meta + query helpers + reparenting validation. |
| `includes/enrollment.php` | Per-program enrollment + form save + bulk enroll by email. |
| `includes/progress.php` | Module completion + REST + render + participants screen. |
| `includes/blocks.php` | Dynamic blocks + front door + breadcrumbs. |
| `includes/rest.php` | Participant REST API — the curriculum shaped for reading. |
| `includes/event-meta.php` | Schedule Event date/time. |
| `includes/protected-files.php` | Protected upload storage + guarded download endpoint. |
| `includes/emails.php` | Enrollment confirmation + WP-Cron session reminders. |
| `includes/health.php` | REST health-check endpoint for deploy/uptime checks. |
| `includes/credits.php` | CLE credit hours per jurisdiction, carried by the programme. |
| `includes/attendance.php` | Attendance at live sessions, marked by instructors. |
| `includes/quizzes.php` | The `pcle_quiz` CPT, questions in meta, server-side marking, completion gating. |
| `includes/certificates.php` | Completion certificates — **scaffold**, pending accreditation identity. |
| `includes/reports.php` | Cohort reporting + CSV export, built from queries rather than per-user loops. |
| `includes/authoring-content.php` | Turns authored plain text into Gutenberg block markup server-side. |
| `includes/rest-authoring.php` | Authoring REST API — the curriculum as the builder sees it. |
| `includes/demo-data.php` | Sample-data seeder internals. |
| `uninstall.php` | Removes roles and records on uninstall. |
| `bin/seed-demo.php` | Sample data (idempotent). |
| `bin/setup-front-door.php` | Creates the "My Training" page + menu link. |
| `tests/smoke-test.php` | Dependency-free smoke suite — 398 assertions across 31 sections. |

## 1. Custom Post Types

The 8 CPTs — Program, Unit, Module, Practice Scenario, **Quiz**, Template, Schedule
Event, Case Update — are registered in `pcle_register_post_types()`. Design points:

- `public => false` **but** `publicly_queryable => true`: they don't appear in public archives/search, but they **do have individual URLs**. Who sees a URL is decided by access control, not by the absence of a URL. *(This was an early bug: with `publicly_queryable` false the single pages returned 404 and the access gate never ran.)*
- `show_in_rest => true`: required for the block editor.
- Grouped in the admin under the **Platform CLE** parent menu.

### Capability groups (single source of truth)

Instead of 8 sets of permissions, the CPTs share **2 groups** defined in `pcle_capability_types()`:

| Group | Singular / Plural | CPTs |
|---|---|---|
| `content` | `pcle_content` / `pcle_contents` | program, unit, module, scenario, quiz, template, event |
| `case_update` | `pcle_case_update` / `pcle_case_updates` | case_update |

`roles.php` reads **this same function** to grant permissions, so the names never drift apart.

## 2. Roles and capabilities

Defined in `pcle_register_roles()` (runs on activation). Custom (non-CPT) capabilities:

- `view_cle_content` — general participant gate.
- `reveal_model_answers` — reveal model answers.
- `view_participant_progress` — view others' progress (instructor+).

| Role | Capabilities |
|---|---|
| **CLE Student** | `read`, `view_cle_content`, `reveal_model_answers` |
| **CLE Instructor** | the above + `view_participant_progress` + `upload_files` + **all** `content` and `case_update` caps |
| **Administrator** | native role, extended with every plugin cap |

`pcle_user_is_staff()` (in `enrollment.php`) = anyone with `edit_pcle_contents` (instructor/admin).

## 3. Access control (per program)

Two levels:

1. **Coarse** — `pcle_user_can_access()`: logged in + `view_cle_content`. Used as defense in depth (REST, search).
2. **Fine** — `pcle_can_access_post( $post_id, $user_id )`:
   - Staff → always yes.
   - Non-participant → no.
   - Case Update → yes (cross-program announcement).
   - Curriculum content → must be **enrolled** in the post's program (resolved with `pcle_get_program_for_post()`).

The gate `pcle_guard_protected_content()` (hook `template_redirect`):
- Anonymous → `wp-login.php` with return URL.
- Logged in but not enrolled → "My Training" with `?pcle_notice=not_enrolled`.

**Model answers:** the `[pcle_model_answer]` shortcode **does not render** the content for users without `reveal_model_answers` (server-side protection, not CSS hiding).

**REST:** the CPTs are `show_in_rest` (for the block editor), so their published items would otherwise be readable by anyone via `/wp-json/`. `pcle_guard_rest_reads()` (hook `rest_pre_dispatch`) enforces per-program access on GET reads of the CPT routes: anonymous → 401, non-enrolled → 403, enrolled/staff → 200. (Note: there is no core `rest_{$post_type}_item_permissions_check` filter — an earlier version hooked that name and was a no-op.)

## 4. Hierarchical relationships

Modeled with **post meta** (not `post_parent`, which doesn't cross post types). Map in `pcle_relationship_map()`:

| Child | Meta key | Parent |
|---|---|---|
| `pcle_unit` | `_pcle_program_id` | `pcle_program` |
| `pcle_module` | `_pcle_unit_id` | `pcle_unit` |
| `pcle_event` | `_pcle_unit_id` | `pcle_unit` |
| `pcle_scenario` | `_pcle_module_id` | `pcle_module` |
| `pcle_quiz` | `_pcle_module_id` | `pcle_module` |
| `pcle_template` | `_pcle_module_id` | `pcle_module` |

The meta is registered in REST with an `auth_callback` (only someone who can edit the post writes it), edited via a **select meta box**, and saved with a nonce + parent-type validation.

**Query API:** `pcle_get_units()`, `pcle_get_modules()`, `pcle_get_scenarios()`, `pcle_get_templates()`, `pcle_get_events()`, `pcle_get_parent_id()`, `pcle_get_program_for_post()`. Everything ordered by `menu_order` then title.

## 5. Progress

One row per (user, module) in the **`pcle_progress` table**, carrying the completion
timestamp. Unit/program progress is **computed** over the hierarchy, not stored, so it
never drifts if the curriculum changes.

> This began as user meta `_pcle_completed_modules` (an array of module IDs). It moved
> because a serialized array cannot answer *when* someone completed something, and a
> credit claim turns on exactly that. `pcle_migrate_legacy_meta()` carries existing
> installs across; the old key survives only as a migration source.

- CRUD: `pcle_mark_module_complete()`, `pcle_unmark_module_complete()`, `pcle_is_module_complete()`.
- Computation: `pcle_get_unit_progress()`, `pcle_get_program_progress()` → `{completed, total, percent}`.
- **Gating:** a module carrying a quiz with `_pcle_quiz_gates_completion` cannot be marked complete until that quiz is passed (see §11).
- **REST:** `POST /wp-json/platform-cle/v1/progress` `{module_id, completed}` — always operates on the current user; protected by `view_cle_content` + `wp_rest` nonce.
- Frontend: `assets/progress.js` + `assets/progress.css` ("mark as complete" button that updates the bar live).

## 6. Enrollment

One row per (user, program) in the **`pcle_enrollments` table**, with the enrollment
timestamp. Helpers in `enrollment.php` keep the signatures they had as user meta
(`_pcle_enrolled_programs`), which now survives only as a migration source. Managed
from the **Participants & Enrollment** screen: a checkbox per student, plus **bulk enroll by email** (paste a cohort's emails; admins with `create_users` also create Student accounts for unknown emails and send a set-password email). Saved with nonces on `admin_init`.

> Production enrollment will be **payment-driven** — see [ROADMAP.md](ROADMAP.md). The design keeps `pcle_enroll_user()` as the single enrollment primitive that a payment "bridge" calls, so payments stay decoupled and swappable.

## 7. Dynamic blocks (server-rendered, no build step)

| Block | Renders |
|---|---|
| `platform-cle/curriculum-children` | Lists the current post's children by type. |
| `platform-cle/progress-bar` | Progress bar for the current Program/Unit. |
| `platform-cle/complete-button` | "Mark as complete" button for the module. |
| `platform-cle/event-datetime` | Session date/time. |
| `platform-cle/breadcrumbs` | Breadcrumbs (walks up the hierarchy to "My Training"). |
| `platform-cle/my-programs` | Front door: the user's programs with progress. |

**Shortcodes:** `[pcle_model_answer]`, `[pcle_module_progress]`, `[pcle_my_programs]`.

## 8. Theme

Child theme of **Twenty Twenty-Five**. Presentation only:
- `style.css` (styles) + `functions.php` (enqueues the stylesheet).
- 7 `single-pcle_*.html` templates combining native blocks (`post-title`, `post-content`) with the plugin's dynamic blocks, plus `page.html`.

**There is no `single-pcle_quiz.html`.** Quizzes are sat in `apps/web` only — the
marking flow is an API conversation, not a rendered template, and duplicating it
in the theme would mean two implementations of the one thing that must not leak
answers. The WordPress-rendered site therefore covers the curriculum but not
assessment.

## 9. Protected files

WordPress serves `wp-content/uploads/` directly through the web server, bypassing
PHP — so raw URLs to Template PDFs / briefs would be downloadable by anyone. To
close that:

- Files uploaded while editing a CLE post are routed to `uploads/pcle-protected/`
  (an `upload_dir` filter keyed on the parent post type).
- Access goes through a guarded endpoint `?pcle_download=<attachment_id>` which
  checks `pcle_can_access_post()` for the file's parent, then streams the file
  with a path-traversal guard.
- `wp_get_attachment_url` is filtered so protected files' URLs point to the
  endpoint — the raw path is never surfaced.
- The directory carries an `.htaccess` deny (Apache) + `index.php`. On **nginx**
  add the documented `location` rule (see [DEVELOPMENT.md](DEVELOPMENT.md)).

See `includes/protected-files.php`.

## 10. Emails

Two transactional notifications (`includes/emails.php`), sent via `wp_mail`:

- **Enrollment confirmation** — hooked to `do_action( 'pcle_user_enrolled', $program_id, $user_id )`,
  which `pcle_enroll_user()` fires **only on a genuinely new enrollment** (so
  re-saving the participants screen doesn't resend). The same hook is the
  intended entry point for the future payment → enrollment bridge.
- **Session reminders** — a WP-Cron job (`pcle_session_reminder_cron`, hourly)
  emails the enrolled students of any live session within the next window
  (default 24h, filter `pcle_reminder_window`). De-duplicated per event date via
  `_pcle_reminder_sent` (rescheduling re-arms it). Scheduled on activation,
  cleared on deactivation.

Subjects/bodies/headers are filterable. **Deliverability:** `wp_mail` needs a
mailer — configure SMTP on the production host.

## 11. Database schema

Four tables (`schema.php`), created with `dbDelta` and versioned by
`PCLE_DB_VERSION` (currently **4**) against the `pcle_db_version` option.
`pcle_maybe_upgrade_schema()` runs pending migrations on upgrade.

| Table | Grain | Why a table rather than meta |
|---|---|---|
| `pcle_enrollments` | one row per (user, program) | "who is enrolled in programme X" has to be queryable |
| `pcle_progress` | one row per (user, module) | needs a completion **timestamp**; credit claims turn on it |
| `pcle_attendance` | one row per (user, event) | records who marked it, not just that it happened |
| `pcle_quiz_attempts` | one row per sitting | deliberately **no** unique key on (user, quiz) — a participant may sit a quiz more than once, and each sitting is its own record |

The rule the schema follows: **tables are for what has to be queried.** Quiz
questions stay in post meta because a question is never read except as part of
its quiz. See §12.

Cleanup is hooked, not manual: `deleted_post` and the user-delete hook clear the
corresponding rows.

## 12. Quizzes

A quiz hangs off a module, alongside scenarios and templates. It is the first
content in the plugin with a *right answer*, so it is the first with something to
leak. Two decisions carry that weight:

**Questions live in post meta, not a table.** `_pcle_quiz_questions` is
deliberately **not** registered via `register_post_meta()` and its key is
underscore-prefixed, so it is absent from `/wp/v2` and from the editor's
custom-fields box by default. The correct answers are kept from participants *by
construction* rather than by a guard that could be forgotten.

**Marking is server-side.** `GET /platform-cle/v1/quizzes/<id>` strips the answers
before the quiz ever reaches a client; `POST …/attempts` marks the submission and
records it. Nothing on the client knows which answer is right, so nothing on the
client can reveal it.

Per-quiz settings: `_pcle_quiz_pass_mark`, and `_pcle_quiz_gates_completion` —
which, when set, blocks the parent module's completion until the quiz is passed.

## 13. Credit hours and attendance

Both exist for CLE compliance, and they are **deliberately not wired to each
other**.

- **Credit hours** (`credits.php`) are carried by the programme, per jurisdiction,
  in `_pcle_credit_hours_<code>` meta. How many hours a course is worth, and in
  which state, is an accreditation decision a person makes and enters — not
  something the plugin can compute from module counts or session lengths.
- **Attendance** (`attendance.php`) is marked by instructors, not participants:
  progress is what a student records about their own reading; attendance is one
  person vouching that another was in the room. The table keeps who marked it for
  that reason.

Wiring one into the other would invent a credit rule nobody signed off on.
Whether a bar accepts these records is a question for whoever files them.

## 14. Certificates — scaffold

`certificates.php` works and draws on real records: participant, programme,
completion date, approved hours, attendance. It is **not issuing real
certificates**, because the accreditation identity is missing — the provider
numbers each bar issues, the authorised signatory, and the wording a given bar
requires on the face of the document. None of that can be guessed, so none of it
is stubbed. See [ROADMAP.md](ROADMAP.md).

## 15. Cohort reporting

`reports.php` answers what a CLE has to answer about a finished programme: who was
enrolled, what they completed and when, which sessions they attended, which
assessments they passed.

Built from **five queries for any cohort size**, not by calling the per-user
helpers in a loop — which is what the move to tables (§11) bought. Exposed at
`/reports/programs/<id>` and `/reports/programs/<id>/csv`; the plugin composes the
CSV columns, ordering and escaping so a second implementation cannot drift from
it. `pcle_csv_safe()` neutralises spreadsheet formula injection (a participant
named `=HYPERLINK(...)` cannot execute on the machine of whoever opens the file).

It decides nothing. A completion with no recorded date says so, rather than being
dropped or given a plausible one.

## 16. Authoring API

`rest-authoring.php` is the curriculum as the *builder* sees it, which is close to
the opposite of what participants need: drafts included, structure over prose,
gated on being allowed to edit rather than to attend. Rather than have the builder
drive `/wp/v2/pcle_*` directly:

- the whole tree is one request instead of seven collection fetches plus a
  client-side join on meta;
- a reorder or a duplicate is one atomic call rather than N untransacted writes
  that can half-apply;
- per-programme permission is decided in one guard instead of once per route.

**The builder sends plain text, never HTML.** `authoring-content.php` escapes it
and constructs the tags server-side, so no markup from a client reaches the
database — instructors do not hold `unfiltered_html`, and an authoring API must
not become the way around that. What gets stored is **Gutenberg block markup**
rather than bare HTML, because bare HTML opens in wp-admin as a single "Classic"
block and re-saving there quietly rewrites the paragraph structure. Block markup
opens as native blocks, which is what makes coexistence between the two editors
real rather than aspirational.

Routes: `/authoring/programs`, `/authoring/programs/<id>/tree`,
`/authoring/nodes` (POST), `/authoring/nodes/<id>` (GET/PATCH/DELETE),
`/authoring/reorder`, `/authoring/move`, and `/me`.

A DELETE that would orphan descendants is **refused** with the list of them unless
the caller passes `cascade`.

## Storage keys summary

**Tables** (see §11)

| Table | Grain |
|---|---|
| `pcle_enrollments` | one row per (user, program) |
| `pcle_progress` | one row per (user, module), with completion time |
| `pcle_attendance` | one row per (user, event), with who marked it |
| `pcle_quiz_attempts` | one row per sitting |

**Meta**

| Key | Type | Use |
|---|---|---|
| `_pcle_program_id` / `_pcle_unit_id` / `_pcle_module_id` | post meta | hierarchical relationships |
| `_pcle_event_datetime` | post meta | event date/time (`Y-m-d H:i:s`) |
| `_pcle_credit_hours_<code>` | post meta (program) | approved credit hours per jurisdiction |
| `_pcle_quiz_questions` | post meta (quiz) | questions **and answers** — never registered in REST |
| `_pcle_quiz_pass_mark` | post meta (quiz) | percentage required to pass |
| `_pcle_quiz_gates_completion` | post meta (quiz) | blocks the parent module until passed |
| `_pcle_reminder_sent` | post meta (event) | event date a reminder was already sent for |
| `_pcle_front_door` | post meta (page) | marks the "My Training" page |
| `_pcle_demo` | post meta | marks sample content (seeder idempotency) |

**Legacy** — migration sources only, read by `pcle_migrate_legacy_meta()` and not written:

| Key | Type | Superseded by |
|---|---|---|
| `_pcle_completed_modules` | user meta | `pcle_progress` |
| `_pcle_enrolled_programs` | user meta | `pcle_enrollments` |
