# Technical architecture

Reference document for the internal design of Habeas CLE.

---

## Guiding principle

**The plugin owns the logic; the theme only presents.** Anything that is business rules (what exists, who can view/edit it, how content relates, how progress is computed) lives in the plugin. The theme only places **dynamic blocks** that the plugin renders.

## Plugin file map

| File | Responsibility |
|---|---|
| `habeas-cle.php` | Bootstrap: defines constants, `require_once` of the modules, activation/deactivation hooks. |
| `includes/post-types.php` | Registers the 7 CPTs and the capability groups. |
| `includes/roles.php` | Creates roles and assigns capabilities. |
| `includes/access-control.php` | Login gate + per-program access + REST protection + `[hcle_model_answer]`. |
| `includes/relationships.php` | Hierarchy via post meta + query helpers. |
| `includes/enrollment.php` | Per-program enrollment (user meta) + form save + bulk enroll. |
| `includes/progress.php` | Progress (user meta) + REST + render + participants screen. |
| `includes/event-meta.php` | Schedule Event date/time. |
| `includes/blocks.php` | Dynamic blocks + front door + breadcrumbs. |
| `includes/protected-files.php` | Protected upload storage + guarded download endpoint. |
| `includes/emails.php` | Enrollment confirmation + WP-Cron session reminders. |
| `includes/health.php` | REST health-check endpoint for deploy/uptime checks. |
| `uninstall.php` | Removes roles on uninstall. |
| `bin/seed-demo.php` | Sample data (idempotent). |
| `bin/setup-front-door.php` | Creates the "My Training" page + menu link. |

## 1. Custom Post Types

The 7 CPTs are registered in `hcle_register_post_types()`. Design points:

- `public => false` **but** `publicly_queryable => true`: they don't appear in public archives/search, but they **do have individual URLs**. Who sees a URL is decided by access control, not by the absence of a URL. *(This was an early bug: with `publicly_queryable` false the single pages returned 404 and the access gate never ran.)*
- `show_in_rest => true`: required for the block editor.
- Grouped in the admin under the **Habeas CLE** parent menu.

### Capability groups (single source of truth)

Instead of 7 sets of permissions, the CPTs share **2 groups** defined in `hcle_capability_types()`:

| Group | Singular / Plural | CPTs |
|---|---|---|
| `content` | `hcle_content` / `hcle_contents` | program, week, module, scenario, template, event |
| `case_update` | `hcle_case_update` / `hcle_case_updates` | case_update |

`roles.php` reads **this same function** to grant permissions, so the names never drift apart.

## 2. Roles and capabilities

Defined in `hcle_register_roles()` (runs on activation). Custom (non-CPT) capabilities:

- `view_cle_content` — general participant gate.
- `reveal_model_answers` — reveal model answers.
- `view_participant_progress` — view others' progress (instructor+).

| Role | Capabilities |
|---|---|
| **CLE Student** | `read`, `view_cle_content`, `reveal_model_answers` |
| **CLE Instructor** | the above + `view_participant_progress` + `upload_files` + **all** `content` and `case_update` caps |
| **Administrator** | native role, extended with every plugin cap |

`hcle_user_is_staff()` (in `enrollment.php`) = anyone with `edit_hcle_contents` (instructor/admin).

## 3. Access control (per program)

Two levels:

1. **Coarse** — `hcle_user_can_access()`: logged in + `view_cle_content`. Used as defense in depth (REST, search).
2. **Fine** — `hcle_can_access_post( $post_id, $user_id )`:
   - Staff → always yes.
   - Non-participant → no.
   - Case Update → yes (cross-program announcement).
   - Curriculum content → must be **enrolled** in the post's program (resolved with `hcle_get_program_for_post()`).

The gate `hcle_guard_protected_content()` (hook `template_redirect`):
- Anonymous → `wp-login.php` with return URL.
- Logged in but not enrolled → "My Training" with `?hcle_notice=not_enrolled`.

**Model answers:** the `[hcle_model_answer]` shortcode **does not render** the content for users without `reveal_model_answers` (server-side protection, not CSS hiding).

**REST:** the CPTs are `show_in_rest` (for the block editor), so their published items would otherwise be readable by anyone via `/wp-json/`. `hcle_guard_rest_reads()` (hook `rest_pre_dispatch`) enforces per-program access on GET reads of the CPT routes: anonymous → 401, non-enrolled → 403, enrolled/staff → 200. (Note: there is no core `rest_{$post_type}_item_permissions_check` filter — an earlier version hooked that name and was a no-op.)

## 4. Hierarchical relationships

Modeled with **post meta** (not `post_parent`, which doesn't cross post types). Map in `hcle_relationship_map()`:

| Child | Meta key | Parent |
|---|---|---|
| `hcle_week` | `_hcle_program_id` | `hcle_program` |
| `hcle_module` | `_hcle_week_id` | `hcle_week` |
| `hcle_event` | `_hcle_week_id` | `hcle_week` |
| `hcle_scenario` | `_hcle_module_id` | `hcle_module` |
| `hcle_template` | `_hcle_module_id` | `hcle_module` |

The meta is registered in REST with an `auth_callback` (only someone who can edit the post writes it), edited via a **select meta box**, and saved with a nonce + parent-type validation.

**Query API:** `hcle_get_weeks()`, `hcle_get_modules()`, `hcle_get_scenarios()`, `hcle_get_templates()`, `hcle_get_events()`, `hcle_get_parent_id()`, `hcle_get_program_for_post()`. Everything ordered by `menu_order` then title.

## 5. Progress

MVP via **user meta** `_hcle_completed_modules` (array of module IDs). Week/program progress is **computed** over the hierarchy, not stored (so it never drifts if the curriculum changes).

- CRUD: `hcle_mark_module_complete()`, `hcle_unmark_module_complete()`, `hcle_is_module_complete()`.
- Computation: `hcle_get_week_progress()`, `hcle_get_program_progress()` → `{completed, total, percent}`.
- **REST:** `POST /wp-json/habeas-cle/v1/progress` `{module_id, completed}` — always operates on the current user; protected by `view_cle_content` + `wp_rest` nonce.
- Frontend: `assets/progress.js` + `assets/progress.css` ("mark as complete" button that updates the bar live).

## 6. Enrollment

User meta `_hcle_enrolled_programs` (array of program IDs). Helpers in `enrollment.php`. Managed from the **Participants & Enrollment** screen: a checkbox per student, plus **bulk enroll by email** (paste a cohort's emails; admins with `create_users` also create Student accounts for unknown emails and send a set-password email). Saved with nonces on `admin_init`.

> Production enrollment will be **payment-driven** — see [ROADMAP.md](ROADMAP.md). The design keeps `hcle_enroll_user()` as the single enrollment primitive that a payment "bridge" calls, so payments stay decoupled and swappable.

## 7. Dynamic blocks (server-rendered, no build step)

| Block | Renders |
|---|---|
| `habeas-cle/curriculum-children` | Lists the current post's children by type. |
| `habeas-cle/progress-bar` | Progress bar for the current Program/Week. |
| `habeas-cle/complete-button` | "Mark as complete" button for the module. |
| `habeas-cle/event-datetime` | Session date/time. |
| `habeas-cle/breadcrumbs` | Breadcrumbs (walks up the hierarchy to "My Training"). |
| `habeas-cle/my-programs` | Front door: the user's programs with progress. |

**Shortcodes:** `[hcle_model_answer]`, `[hcle_module_progress]`, `[hcle_my_programs]`.

## 8. Theme

Child theme of **Twenty Twenty-Five**. Presentation only:
- `style.css` (styles) + `functions.php` (enqueues the stylesheet).
- 7 `single-hcle_*.html` templates combining native blocks (`post-title`, `post-content`) with the plugin's dynamic blocks.

## 9. Protected files

WordPress serves `wp-content/uploads/` directly through the web server, bypassing
PHP — so raw URLs to Template PDFs / briefs would be downloadable by anyone. To
close that:

- Files uploaded while editing a CLE post are routed to `uploads/hcle-protected/`
  (an `upload_dir` filter keyed on the parent post type).
- Access goes through a guarded endpoint `?hcle_download=<attachment_id>` which
  checks `hcle_can_access_post()` for the file's parent, then streams the file
  with a path-traversal guard.
- `wp_get_attachment_url` is filtered so protected files' URLs point to the
  endpoint — the raw path is never surfaced.
- The directory carries an `.htaccess` deny (Apache) + `index.php`. On **nginx**
  add the documented `location` rule (see [DEVELOPMENT.md](DEVELOPMENT.md)).

See `includes/protected-files.php`.

## 10. Emails

Two transactional notifications (`includes/emails.php`), sent via `wp_mail`:

- **Enrollment confirmation** — hooked to `do_action( 'hcle_user_enrolled', $program_id, $user_id )`,
  which `hcle_enroll_user()` fires **only on a genuinely new enrollment** (so
  re-saving the participants screen doesn't resend). The same hook is the
  intended entry point for the future payment → enrollment bridge.
- **Session reminders** — a WP-Cron job (`hcle_session_reminder_cron`, hourly)
  emails the enrolled students of any live session within the next window
  (default 24h, filter `hcle_reminder_window`). De-duplicated per event date via
  `_hcle_reminder_sent` (rescheduling re-arms it). Scheduled on activation,
  cleared on deactivation.

Subjects/bodies/headers are filterable. **Deliverability:** `wp_mail` needs a
mailer — configure SMTP on the production host.

## Storage keys summary

| Key | Type | Use |
|---|---|---|
| `_hcle_program_id` / `_hcle_week_id` / `_hcle_module_id` | post meta | hierarchical relationships |
| `_hcle_event_datetime` | post meta | event date/time (`Y-m-d H:i:s`) |
| `_hcle_completed_modules` | user meta | completed modules (array) |
| `_hcle_enrolled_programs` | user meta | enrolled programs (array) |
| `_hcle_reminder_sent` | post meta (event) | event date a reminder was already sent for |
| `_hcle_front_door` | post meta (page) | marks the "My Training" page |
| `_hcle_demo` | post meta | marks sample content (seeder idempotency) |
