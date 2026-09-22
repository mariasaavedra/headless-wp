import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

test.describe("Managing a cohort", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let participantEmail: string;

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
    participantEmail = await wordpress.emailOf(people.participant.username);
  });

  // Whatever the last run or the last person left behind is not this test's
  // starting position.
  test.beforeEach(async () => {
    await wordpress.remove(programme.id, participantEmail);
  });

  test.afterAll(async () => {
    await wordpress.remove(programme.id, participantEmail);
  });

  test("enrolling someone by email puts them in the table", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);

    await page.getByLabel("Add participants").fill(participantEmail);
    /*
     * exact, because a name match is a substring match and a row's "Remove
     * <name> from this programme" carries the participant's display name --
     * which in the demo data is "Demo Student (enrolled)".
     */
    await page.getByRole("button", { name: "Enrol", exact: true }).click();

    await expect(page.getByText("1 person enrolled")).toBeVisible();
    await expect(page.getByRole("table")).toContainText(participantEmail);
  });

  test("every address is accounted for by name", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);

    await page
      .getByLabel("Add participants")
      .fill(`${participantEmail}, nobody@example.test, not-an-address`);
    await page.getByRole("button", { name: "Enrol", exact: true }).click();

    /*
     * Counts alone would not answer "which nine of the twelve", which is the
     * only question worth asking of a paste that did not all work.
     */
    await expect(page.getByText("no account yet")).toBeVisible();
    await expect(page.getByText("not an email address")).toBeVisible();
    await expect(page.getByText("enrolled", { exact: true })).toBeVisible();
  });

  test("re-pasting the same list enrols nobody twice", async ({ page }) => {
    await wordpress.enrol(programme.id, participantEmail);

    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);

    await page.getByLabel("Add participants").fill(participantEmail);
    await page.getByRole("button", { name: "Enrol", exact: true }).click();

    await expect(page.getByText("0 people enrolled")).toBeVisible();
    await expect(page.getByText("already enrolled")).toBeVisible();
  });

  test("removing a participant takes them off the programme", async ({
    page,
  }) => {
    await wordpress.enrol(programme.id, participantEmail);

    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);
    await expect(page.getByRole("table")).toContainText(participantEmail);

    /*
     * The row for this person, not the first Remove on the page. A seeded
     * cohort has other participants in it, and .first() would quietly take
     * one of them off the programme instead — which is the kind of test that
     * passes on an empty local database and removes the wrong student in CI.
     */
    await page
      .getByRole("row")
      .filter({ hasText: participantEmail })
      .getByRole("button", { name: /^Remove/ })
      .click();

    /*
     * The row, not the table's text: a cohort of one leaves no table behind
     * at all, and an assertion about the contents of an element that is not
     * there fails for the wrong reason.
     */
    await expect(
      page.getByRole("row").filter({ hasText: participantEmail })
    ).toHaveCount(0);
  });
});
