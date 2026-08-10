# User guide (administrators and instructors)

How to operate the platform from the WordPress dashboard.

---

## Roles

| Role | Can |
|---|---|
| **CLE Student** | View the content of programs they are **enrolled** in, mark modules as complete, reveal model answers. |
| **CLE Instructor** | Everything a student can + create/edit the curriculum, publish Case Updates, enroll students and view their progress. |
| **Administrator** | Full access. |

To assign a role: **Users → (user) → Role**.

## Building the curriculum

The **Habeas CLE** sidebar menu groups all content types. The recommended creation order follows the hierarchy top-down:

1. **Program** — the program container (e.g. "Immigration Habeas Corpus — Spring 2026").
2. **Week** — in the sidebar, select its **Parent Program**.
3. **Module** — select its **Parent Week**.
4. **Practice Scenario** / **Template** — select its **Parent Module**.
5. **Schedule Event** — select its **Parent Week** and set the **Session Date & Time**.
6. **Case Update** — cross-program announcements (not attached to any program).

> **Display order:** use the **Order** field (under *Page Attributes*) to order weeks and modules. They display from lowest to highest.

> **"Parent" column:** the admin lists show which parent each item belongs to, with a direct link.

### Model answers in scenarios

Inside a Practice Scenario's content, wrap the answer like this:

```
[hcle_model_answer]
The model answer that only participants will see goes here...
[/hcle_model_answer]
```

It renders inside a **"Reveal model answer"** disclosure. Users without permission never receive that content (real protection, not just hidden).

### Session dates

When editing a **Schedule Event**, use the **Session Date & Time** field in the sidebar. The date appears on the week and event pages, in the site's timezone.

> Set the timezone under **Settings → General → Timezone** (e.g. `America/Chicago` for Kansas City).

## Enrolling students

1. Go to **Habeas CLE → Participants & Enrollment**.
2. Pick the **program** in the selector.
3. Check the **Enrolled** box for each student who should have access.
4. Click **Save enrollment**.

Only users with the **CLE Student** role appear in the list. The same table shows each enrolled student's **progress**.

> Instructors and administrators do **not** need enrollment: their content-management permission grants full access.

### Automated emails

- **Enrollment confirmation** — sent to a student when they are newly enrolled in
  a program (with links to the program and their dashboard).
- **Session reminders** — enrolled students automatically receive a reminder for
  each live session in the 24 hours before it starts.

> These use the site's mailer. On the production host, configure **SMTP** so the
> emails actually deliver (WordPress alone often can't send reliably).

## The student experience

1. Log in to the site.
2. **My Training** appears in the menu.
3. They see a card for each program they're enrolled in, with its progress bar.
4. Entering a program: list of weeks (each with its progress) → modules → content.
5. On each module they can click **Mark as complete**; the bar updates instantly.
6. The **breadcrumbs** (above the title) let them jump back to any previous level.

### What does a non-enrolled user see?

- **Not logged in** → prompted to log in.
- **Logged in but not enrolled** in that program → redirected to "My Training" with a notice, and they only see the programs assigned to them.

## Sample data

To quickly populate a demo program, see `bin/seed-demo.php` in [DEVELOPMENT.md](DEVELOPMENT.md). Everything the seeder creates is marked as demo and can be regenerated without affecting real content.
