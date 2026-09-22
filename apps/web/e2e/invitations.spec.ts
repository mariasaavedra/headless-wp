import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

const INVITE = "Create accounts for addresses that have none";

test.describe("Inviting people who have no account", () => {
  let programme: { id: number; title: string };

  test.beforeAll(async () => {
    const wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
  });

  test("an administrator is offered it", async ({ page }) => {
    await signIn(page, people.administrator);
    await page.goto(`/reports/${programme.id}`);

    await expect(page.getByLabel(INVITE)).toBeVisible();
  });

  test("an instructor is not, and is told why", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/reports/${programme.id}`);

    /*
     * Creating accounts takes create_users, which teaching does not grant.
     * The endpoint refuses regardless; hiding the control is about not
     * offering a button whose request would come back having done nothing.
     */
    await expect(page.getByLabel(INVITE)).toHaveCount(0);
    await expect(
      page.getByText("creating accounts is an administrator's to do")
    ).toBeVisible();
  });

  test("the box is never ticked when the screen loads", async ({ page }) => {
    await signIn(page, people.administrator);
    await page.goto(`/reports/${programme.id}`);

    // Inviting strangers is not a setting anyone should inherit from the
    // last time they used this box.
    await expect(page.getByLabel(INVITE)).not.toBeChecked();
  });
});
