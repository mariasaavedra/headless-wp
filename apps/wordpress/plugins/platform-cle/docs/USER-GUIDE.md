# User guide (administrators and instructors)

How to operate the platform from the WordPress dashboard.

---

## Roles

| Role | Can |
|---|---|
| **CLE Student** | View the content of programs they are **enrolled** in, mark modules as complete, reveal model answers. |
| **CLE Instructor** | Everything a student can + create/edit the curriculum (in the builder or wp-admin), publish Case Updates, enroll students, mark attendance, and view progress and cohort reports. |
| **Administrator** | Full access. |

To assign a role: **Users → (user) → Role**.

## Two ways to build a curriculum

There are two authoring surfaces, and they write to the same records:

| | Where | Best for |
|---|---|---|
| **The builder** | the app at `/builder` | day-to-day authoring: the whole programme as one tree, add/rename/reorder/publish inline, quiz editing, no WordPress syntax to learn |
| **wp-admin** | **Platform CLE** in the sidebar | anything the builder does not cover yet, and full block-editor control of a module's body |

Use whichever suits the task — neither locks the other out. Content written in
the builder is stored as native Gutenberg blocks precisely so it opens and
re-saves cleanly in wp-admin.

### Using the builder

1. Sign in to the app and go to **/builder**. Only instructors and administrators
   see it.
2. Pick a programme, or create one.
3. The tree shows the whole programme. From it you can add a unit, module,
   scenario, template or quiz; rename anything inline; reorder siblings; publish
   or unpublish; and set credit hours on the programme.
4. Clicking any node opens its editor — the body, and for a quiz its questions
   and pass mark.

You never type WordPress markup. The builder sends plain text and the server
constructs the markup, which is also why an instructor cannot accidentally paste
something unsafe into a page.

## Building the curriculum in wp-admin

The **Platform CLE** sidebar menu groups all content types. The recommended creation order follows the hierarchy top-down:

1. **Program** — the program container (e.g. "Immigration Habeas Corpus — Spring 2026").
2. **Unit** — in the sidebar, select its **Parent Program**.
3. **Module** — select its **Parent Unit**.
4. **Practice Scenario** / **Template** / **Quiz** — select its **Parent Module**.
5. **Schedule Event** — select its **Parent Unit** and set the **Session Date & Time**.
6. **Case Update** — cross-program announcements (not attached to any program).

> **Display order:** use the **Order** field (under *Page Attributes*) to order units and modules. They display from lowest to highest.

> **"Parent" column:** the admin lists show which parent each item belongs to, with a direct link.

### Model answers in scenarios

Inside a Practice Scenario's content, wrap the answer like this:

```
[pcle_model_answer]
The model answer that only participants will see goes here...
[/pcle_model_answer]
```

It renders inside a **"Reveal model answer"** disclosure. Users without permission never receive that content (real protection, not just hidden).

### Session dates

When editing a **Schedule Event**, use the **Session Date & Time** field in the sidebar. The date appears on the unit and event pages, in the site's timezone.

> Set the timezone under **Settings → General → Timezone** (e.g. `America/Chicago` for Kansas City).

## Enrolling students

1. Go to **Platform CLE → Participants & Enrollment**.
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

Participants can use either the WordPress-rendered site or the app — the same
records back both. The app is the fuller experience and the only place quizzes can
be sat.

1. Log in.
2. **My Training** shows a card for each program they're enrolled in, with its progress bar.
3. Entering a program: units (each with its progress) → modules → content.
4. On each module they can click **Mark as complete**; the bar updates instantly.
   If the module carries a quiz that gates completion, they must pass it first.
5. Quizzes are sat in the app. They see their score immediately and can review
   every past attempt.
6. **Breadcrumbs** let them jump back to any previous level.

### What does a non-enrolled user see?

- **Not logged in** → prompted to log in.
- **Logged in but not enrolled** in that program → redirected to "My Training" with a notice, and they only see the programs assigned to them.

## Quizzes

A quiz hangs off a module, alongside scenarios and templates. Author it in the
builder (easiest) or as a **Quiz** post in wp-admin with its **Parent Module** set.

Per quiz you set:

- the **questions and their correct answers**;
- a **pass mark** (percentage);
- optionally, **gates completion** — when on, a participant cannot mark the parent
  module complete until they pass.

**Participants never receive the answers.** The quiz is stripped of them before it
is sent, and marking happens on the server. A participant may sit a quiz more than
once; every attempt is kept, and all of them appear in the cohort report.

## Credit hours

Open a **Program** and use the **Credit hours** box in the sidebar. Enter the
approved hours per jurisdiction.

These are entered by a person, not calculated. How many hours a course is worth,
and in which state, is an accreditation decision — the platform records it and
reports it, and deliberately does not try to infer it from module counts or
session lengths.

## Session attendance

**Platform CLE → Session Attendance.** Pick a session, then tick who was present.

Attendance is marked by instructors, not by participants — progress is what a
student records about their own reading; attendance is one person vouching that
another was in the room. The record keeps who marked it, for that reason.

Attendance is **not** wired to credit hours. Whether a bar accepts these records
as verification is a question for whoever files them, and connecting the two
automatically would answer it on their behalf.

## Cohort reports

**Platform CLE → Reports** in wp-admin, or `/reports` in the app.

For a chosen programme you get, per participant: enrollment, modules completed and
when, sessions attended, quiz results, and the programme's approved credit hours.
**Download CSV** gives you the same rows as a spreadsheet.

The report states what is recorded, including where a record is incomplete — a
completion with no date says "not recorded" rather than being dropped or given a
plausible one. Whether the result supports a credit claim is a judgement for the
person filing it.

## Certificates

**Not yet issuing.** The mechanism is built and draws on real records, but the
accreditation identity is missing: the provider numbers each bar issues, the
authorised signatory, and the wording a given bar requires on the face of the
document. Supply those and this becomes a live feature; until then nothing is
issued, because a certificate that guessed at any of it would be worse than none.
See [ROADMAP.md](ROADMAP.md).

## Sample data

To quickly populate a demo program, see `bin/seed-demo.php` in [DEVELOPMENT.md](DEVELOPMENT.md). Everything the seeder creates is marked as demo and can be regenerated without affecting real content.
