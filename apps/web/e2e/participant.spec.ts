import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress, type TreeNode } from "./support/wordpress";

test.describe("The path a participant walks", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let participantEmail: string;

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
    participantEmail = await wordpress.emailOf(people.participant.username);

    // The participant path needs a participant on the programme.
    await wordpress.enrol(programme.id, participantEmail);
  });

  test("their programme is on My Training", async ({ page }) => {
    await signIn(page, people.participant);

    await expect(page).toHaveURL(/\/my-training$/);
    await expect(page.getByRole("link", { name: programme.title })).toBeVisible();
  });

  test("from the list to a programme to a module", async ({ page }) => {
    const lesson = await wordpress.completableModule(programme.id);

    await signIn(page, people.participant);
    await page.getByRole("link", { name: programme.title }).click();

    await expect(page).toHaveURL(new RegExp(`/programs/${programme.id}$`));
    await expect(
      page.getByRole("heading", { name: programme.title })
    ).toBeVisible();

    await page.getByRole("link", { name: lesson.title }).first().click();

    await expect(page).toHaveURL(new RegExp(`/modules/${lesson.id}$`));
    await expect(page.getByRole("heading", { name: lesson.title })).toBeVisible();
  });

  test("marking a module complete, and undoing it", async ({ page }) => {
    const lesson = await wordpress.completableModule(programme.id);

    await signIn(page, people.participant);
    await page.goto(`/modules/${lesson.id}`);

    /*
     * The control says what it will do rather than what the state is, so the
     * two labels are the assertion: a module that is already complete offers
     * to undo, and one that is not offers to complete.
     */
    const mark = page.getByRole("button", { name: "Mark as complete" });
    const undo = page.getByRole("button", { name: /Completed/ });

    if (await mark.isVisible()) {
      await mark.click();
    }

    await expect(undo).toBeVisible();

    await undo.click();
    await expect(page.getByRole("button", { name: "Mark as complete" })).toBeVisible();
  });

  test("sitting a quiz and being told how it went", async ({ page }) => {
    const quiz: TreeNode = await wordpress.firstQuiz(programme.id);

    await signIn(page, people.participant);
    await page.goto(`/quizzes/${quiz.id}`);

    await expect(page.getByRole("heading", { name: quiz.title })).toBeVisible();

    /*
     * One question at a time, so the walk is: answer whatever is on screen,
     * move on, and submit when the questionnaire offers to. Answering
     * correctly is not the point — being told the outcome is.
     */
    for (let step = 0; step < 6; step += 1) {
      const radio = page.getByRole("radio").first();
      if (await radio.isVisible().catch(() => false)) {
        await radio.check();
      }

      const box = page.getByRole("checkbox").first();
      if (await box.isVisible().catch(() => false)) {
        await box.check();
      }

      /*
       * Including the questions that are not scored. They are marked
       * optional and the questionnaire still refuses to submit while one is
       * blank — see the note in the pull request; a participant who leaves
       * the discussion question empty cannot hand the quiz in at all.
       */
      const written = page.locator('[data-slot="questionnaire-input"]');
      if (await written.isVisible().catch(() => false)) {
        await written.fill("An answer, for the sake of handing this in.");
      }

      /*
       * By data-slot, not by name. The questionnaire renders more than one
       * control that reads as "Next" — a name match finds them all, and
       * which one a test clicks should not depend on their order.
       */
      const submit = page.locator('[data-slot="questionnaire-submit"]');
      if (await submit.isVisible().catch(() => false)) {
        await submit.click();
        break;
      }

      await page.locator('[data-slot="questionnaire-next"]').first().click();
    }

    /*
     * "Sit it again" is the signal, and it is generous about timing: it
     * appears only on the result screen, and getting there waits on
     * WordPress marking the attempt rather than on a render.
     *
     * The outcome itself is matched .first() on purpose. The same words
     * appear again in "Your attempts" below, a list that grows by one every
     * time this test runs — a locator that assumes one match would pass
     * today and fail on the second run.
     */
    await expect(page.getByRole("button", { name: "Sit it again" })).toBeVisible({
      timeout: 20_000,
    });
    await expect(page.getByText(/^(Passed|Not passed)$/).first()).toBeVisible();
  });

  test("an optional question can be left blank", async ({ page }) => {
    const quiz = await wordpress.firstQuiz(programme.id);

    await signIn(page, people.participant);
    await page.goto(`/quizzes/${quiz.id}`);

    /*
     * The regression this exists for: a question marked "not scored, for
     * discussion" could be neither answered nor got past. The questionnaire
     * counts a question as settled when it is answered or skipped, and the
     * app rendered no way to skip — so a participant with nothing to write
     * was stuck on a quiz they could not hand in.
     *
     * This walk never types a word into the written question.
     */
    for (let step = 0; step < 6; step += 1) {
      const radio = page.getByRole("radio").first();
      if (await radio.isVisible().catch(() => false)) {
        await radio.check();
      }

      const box = page.getByRole("checkbox").first();
      if (await box.isVisible().catch(() => false)) {
        await box.check();
      }

      const written = page.locator('[data-slot="questionnaire-input"]');
      const skip = page.locator('[data-slot="questionnaire-skip"]');

      if (await written.isVisible().catch(() => false)) {
        // Offered only where the question is optional.
        await expect(skip).toBeVisible();
        await skip.click();
      }

      const submit = page.locator('[data-slot="questionnaire-submit"]');
      if (await submit.isVisible().catch(() => false)) {
        /*
         * Skipping the last question hands the quiz in by itself, which
         * leaves this button disabled and reading "Submitting…". Clicking it
         * then waits for an enabled state that is never coming.
         */
        if (await submit.isEnabled()) {
          await submit.click();
        }
        break;
      }

      const next = page.locator('[data-slot="questionnaire-next"]');
      if (await next.isVisible().catch(() => false)) {
        await next.first().click();
      }
    }

    await expect(page.getByRole("button", { name: "Sit it again" })).toBeVisible({
      timeout: 20_000,
    });
    await expect(page.getByText("You left this blank.")).toBeVisible();
  });

  test("someone enrolled in nothing is refused the programme", async ({
    page,
  }) => {
    await signIn(page, {
      username: "demo.outsider",
      password: people.participant.password,
    });

    await page.goto(`/programs/${programme.id}`);

    // They hold the participant role and every capability that comes with
    // it. Access is decided by enrolment, which is the sharpest test of it.
    await expect(
      page.getByText(/not enrolled in this programme/i)
    ).toBeVisible();
  });
});
