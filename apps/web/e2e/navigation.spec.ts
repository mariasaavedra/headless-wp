import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

test.describe("What each role is offered", () => {
  test("a participant is offered their training and nothing else", async ({
    page,
  }) => {
    await signIn(page, people.participant);

    const header = page.getByRole("banner");
    await expect(header.getByRole("button", { name: "My Training" })).toBeVisible();
    await expect(header.getByRole("button", { name: "Build" })).toHaveCount(0);
    await expect(header.getByRole("button", { name: "Reports" })).toHaveCount(0);
  });

  test("an instructor is offered the builder and reports", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto("/reports");

    const header = page.getByRole("banner");
    await expect(header.getByRole("button", { name: "Build" })).toBeVisible();
    await expect(header.getByRole("button", { name: "Reports" })).toBeVisible();

    /*
     * Administration is WordPress, and teaching does not grant it. The
     * header and the menu render one list, so this also asserts they agree.
     */
    await expect(
      header.getByRole("button", { name: "Administration" })
    ).toHaveCount(0);
  });

  test("an administrator is offered WordPress too", async ({ page }) => {
    await signIn(page, people.administrator);
    await page.goto("/reports");

    await expect(
      page.getByRole("banner").getByRole("button", { name: "Administration" })
    ).toBeVisible();
  });
});

test.describe("The three views of one programme", () => {
  test("the cohort report reaches the builder and comes back", async ({
    page,
  }) => {
    const wordpress = await WordPress.asAdministrator();
    const programme = await wordpress.firstProgramme();

    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);

    /*
     * The same programme has three screens, and until they were linked an
     * instructor reading a report had to return to the menu and find the
     * programme again by name.
     */
    await page.getByRole("button", { name: "Open in builder" }).click();
    await expect(page).toHaveURL(new RegExp(`/builder/programs/${programme.id}$`));

    await page.getByRole("button", { name: "Cohort report" }).click();
    await expect(page).toHaveURL(new RegExp(`/reports/${programme.id}$`));
  });

  test("the cohort report reaches the participant's view", async ({ page }) => {
    const wordpress = await WordPress.asAdministrator();
    const programme = await wordpress.firstProgramme();

    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);

    await page.getByRole("button", { name: "View as participant" }).click();
    await expect(page).toHaveURL(new RegExp(`/programs/${programme.id}$`));
  });
});
