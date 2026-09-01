# Platform CLE

A CLE (Continuing Legal Education) training platform on **immigration habeas corpus**, built for the nonprofit **The Pen & Sword KC** (Sharma-Crawford Attorneys at Law).

It is not a full LMS: it's a **lightweight, authenticated learning platform** that delivers a 4-week virtual program, built on WordPress.

---

## Architecture in one sentence

> The **plugin** (`platform-cle`) owns all business logic; the **block theme** (`platform-cle-theme`) handles presentation only; WordPress provides native authentication and roles.

```
┌─────────────────────────┐     ┌──────────────────────────┐
│  Plugin platform-cle       │     │  Theme platform-cle-theme   │
│  (logic)                 │     │  (presentation)           │
│                          │     │                           │
│  • CPTs                  │     │  • Child of Twenty         │
│  • Roles & capabilities  │ ──▶ │    Twenty-Five            │
│  • Access control        │     │  • 7 single templates     │
│  • Hierarchical relations│     │  • Places the plugin's    │
│  • Progress (tables)     │     │    dynamic blocks         │
│  • Enrollment            │     │                           │
│  • Dynamic blocks        │     │                           │
└─────────────────────────┘     └──────────────────────────┘
```

Since the move into the monorepo there is a **second** presentation layer: the
Next.js app in `apps/web`, which consumes this plugin's REST API rather than its
blocks. It is where the curriculum builder and quiz-taking live. The principle is
unchanged — the plugin owns the logic, and both frontends only present.

## Repository structure

```
platform-cle/
├── plugin/        → the plugin (goes in wp-content/plugins/platform-cle/)
├── theme/         → the theme  (goes in wp-content/themes/platform-cle-theme/)
├── docs/          → documentation
│   ├── ARCHITECTURE.md   → data model and technical design
│   ├── DEVELOPMENT.md    → local setup, scripts, commands
│   ├── USER-GUIDE.md     → manual for administrators and instructors
│   ├── ROADMAP.md        → viability audit, hardening status, payments milestone
│   └── DEPLOYMENT.md     → Local → production runbook and go-live checklist
├── bin/
│   └── sync.sh    → OBSOLETE (Local by Flywheel era; superseded by Docker)
├── CHANGELOG.md
└── README.md
```

> **Note on the monorepo:** this lives inside
> [`headless-wp`](../../../../README.md), where `plugin/` and `theme/` are
> **bind-mounted** into the WordPress container — edits take effect immediately,
> with no sync step. The plugin and theme still have to sit in their
> `wp-content/` folders to run, which is what the bind mounts arrange.
>
> `bin/sync.sh` is **obsolete**: it rsynced between the repo and a Local by
> Flywheel install, which Docker Compose replaced. It is not part of any current
> workflow.

## Features

- **8 Custom Post Types:** Program, Unit, Module, Practice Scenario, Quiz, Template, Schedule Event, Case Update.
- **3 roles:** CLE Student, CLE Instructor, and an extended Administrator.
- **Per-program access control:** content is protected behind login and requires **enrollment** in the specific program.
- **Hierarchy** Program → Unit → Module → (Scenario | Template), with Events per unit.
- **Progress tracking** in a real table, with completion timestamps, and per-unit and per-program bars.
- **Model answers** protected server-side (`[pcle_model_answer]`).
- **Student enrollment** managed by instructors.
- **Front door** ("My Training") + breadcrumbs for navigation.
- **Session dates** on Schedule Events.
- **Quizzes** hanging off a module: server-side marking, retained attempts, and an
  optional pass requirement gating the module's completion.
- **CLE credit hours** per jurisdiction, carried by the programme.
- **Session attendance**, marked by instructors.
- **Cohort reports** with CSV export — completion, attendance, quiz results and
  approved hours.
- **Certificates** — scaffold only, pending accreditation identity.
- **Authoring API** behind the app's curriculum builder, so instructors never type
  WordPress markup.

See the full breakdown in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).


