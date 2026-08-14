# Technical architecture

Reference document for the internal design of Platform CLE.

---

## Guiding principle

**The plugin owns the logic; the theme only presents.** Anything that is business rules (what exists, who can view/edit it, how content relates, how progress is computed) lives in the plugin. The theme only places **dynamic blocks** that the plugin renders.

## Plugin file map

| File | Responsibility |
|---|---|
| `platform-cle.php` | Bootstrap: defines constants, `require_once` of the modules, activation/deactivation hooks. |
| `includes/post-types.php` | Registers the 7 CPTs and the capability groups. |
| `includes/roles.php` | Creates roles and assigns capabilities. |
| `includes/access-control.php` | Login gate + per-program access + REST protection + `[pcle_model_answer]`. |
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

The 7 CPTs are registered in `pcle_register_post_types()`. Design points:

- `public => false` **but** `publicly_queryable => true`: they don't appear in public archives/search, but they **do have individual URLs**. Who sees a URL is decided by access control, not by the absence of a URL. *(This was an early bug: with `publicly_queryable` false the single pages returned 404 and the access gate never ran.)*
- `show_in_rest => true`: required for the block editor.
- Grouped in the admin under the **Platform CLE** parent menu.

### Capability groups (single source of truth)

Instead of 7 sets of permissions, the CPTs share **2 groups** defined in `pcle_capability_types()`:

| Group | Singular / Plural | CPTs |
|---|---|---|
| `content` | `pcle_content` / `pcle_contents` | program, unit, module, scenario, template, event |
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
| `pcle_template` | `_pcle_module_id` | `pcle_module` |

The meta is registered in REST with an `auth_callback` (only someone who can edit the post writes it), edited via a **select meta box**, and saved with a nonce + parent-type validation.

**Query API:** `pcle_get_units()`, `pcle_get_modules()`, `pcle_get_scenarios()`, `pcle_get_templates()`, `pcle_get_events()`, `pcle_get_parent_id()`, `pcle_get_program_for_post()`. Everything ordered by `menu_order` then title.

## 5. Progress

MVP via **user meta** `_pcle_completed_modules` (array of module IDs). Unit/program progress is **computed** over the hierarchy, not stored (so it never drifts if the curriculum changes).

- CRUD: `pcle_mark_module_complete()`, `pcle_unmark_module_complete()`, `pcle_is_module_complete()`.
- Computation: `pcle_get_unit_progress()`, `pcle_get_program_progress()` → `{completed, total, percent}`.
- **REST:** `POST /wp-json/platform-cle/v1/progress` `{module_id, completed}` — always operates on the current user; protected by `view_cle_content` + `wp_rest` nonce.
- Frontend: `assets/progress.js` + `assets/progress.css` ("mark as complete" button that updates the bar live).

## 6. Enrollment

User meta `_pcle_enrolled_programs` (array of program IDs). Helpers in `enrollment.php`. Managed from the **Participants & Enrollment** screen: a checkbox per student, plus **bulk enroll by email** (paste a cohort's emails; admins with `create_users` also create Student accounts for unknown emails and send a set-password email). Saved with nonces on `admin_init`.

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
- 7 `single-pcle_*.html` templates combining native blocks (`post-title`, `post-content`) with the plugin's dynamic blocks.

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

## Storage keys summary

| Key | Type | Use |
|---|---|---|
| `_pcle_program_id` / `_pcle_unit_id` / `_pcle_module_id` | post meta | hierarchical relationships |
| `_pcle_event_datetime` | post meta | event date/time (`Y-m-d H:i:s`) |
| `_pcle_completed_modules` | user meta | completed modules (array) |
| `_pcle_enrolled_programs` | user meta | enrolled programs (array) |
| `_pcle_reminder_sent` | post meta (event) | event date a reminder was already sent for |
| `_pcle_front_door` | post meta (page) | marks the "My Training" page |
| `_pcle_demo` | post meta | marks sample content (seeder idempotency) |
