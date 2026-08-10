# Habeas CLE

A CLE (Continuing Legal Education) training platform on **immigration habeas corpus**, built for the nonprofit **The Pen & Sword KC** (Sharma-Crawford Attorneys at Law).

It is not a full LMS: it's a **lightweight, authenticated learning platform** that delivers a 4-week virtual program, built on WordPress.

---

## Architecture in one sentence

> The **plugin** (`habeas-cle`) owns all business logic; the **block theme** (`habeas-cle-theme`) handles presentation only; WordPress provides native authentication and roles.

```
┌─────────────────────────┐     ┌──────────────────────────┐
│  Plugin habeas-cle       │     │  Theme habeas-cle-theme   │
│  (logic)                 │     │  (presentation)           │
│                          │     │                           │
│  • CPTs                  │     │  • Child of Twenty         │
│  • Roles & capabilities  │ ──▶ │    Twenty-Five            │
│  • Access control        │     │  • 7 single templates     │
│  • Hierarchical relations│     │  • Places the plugin's    │
│  • Progress (user meta)  │     │    dynamic blocks         │
│  • Enrollment            │     │                           │
│  • Dynamic blocks        │     │                           │
└─────────────────────────┘     └──────────────────────────┘
```

## Repository structure

```
habeas-cle/
├── plugin/        → the plugin (goes in wp-content/plugins/habeas-cle/)
├── theme/         → the theme  (goes in wp-content/themes/habeas-cle-theme/)
├── docs/          → documentation
│   ├── ARCHITECTURE.md   → data model and technical design
│   ├── DEVELOPMENT.md    → local setup, scripts, commands
│   ├── USER-GUIDE.md     → manual for administrators and instructors
│   ├── ROADMAP.md        → viability audit, hardening status, payments milestone
│   └── DEPLOYMENT.md     → Local → production runbook and go-live checklist
├── bin/
│   └── sync.sh    → syncs the repo ↔ the Local install
├── CHANGELOG.md
└── README.md
```

> **Note on the monorepo:** this repo is a *snapshot* of the code. The plugin and theme must live in their `wp-content/` folders to run (WordPress breaks asset URLs if the plugin lives outside `wp-content/plugins/`, which is why we don't use symlinks). Use [`bin/sync.sh`](bin/sync.sh) to move changes between the repo and the live site.

## Features

- **7 Custom Post Types:** Program, Week, Module, Practice Scenario, Template, Schedule Event, Case Update.
- **3 roles:** CLE Student, CLE Instructor, and an extended Administrator.
- **Per-program access control:** content is protected behind login and requires **enrollment** in the specific program.
- **Hierarchy** Program → Week → Module → (Scenario | Template), with Events per week.
- **Progress tracking** (MVP via user meta) with per-week and per-program bars.
- **Model answers** protected server-side (`[hcle_model_answer]`).
- **Student enrollment** managed by instructors.
- **Front door** ("My Training") + breadcrumbs for navigation.
- **Session dates** on Schedule Events.

See the full breakdown in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).


