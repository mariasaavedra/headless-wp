import { expect, test } from "@playwright/test";

import { signIn, signOut } from "./support/session";
import { WordPress } from "./support/wordpress";

/*
 * Each test gets an account of its own. These tests change usernames and
 * passwords; doing that to a demo account would break every other spec.
 */
test.describe("Your account", () => {
  let wordpress: WordPress;
  let person: { id: number; username: string; password: string };

  test.beforeEach(async () => {
    wordpress = await WordPress.asAdministrator();
    const suffix = Math.random().toString(36).slice(2, 8);
    person = await wordpress.createUser(`e2e.account.${suffix}`, `first-password-${suffix}`);
  });

  test.afterEach(async () => {
    await wordpress.deleteUser(person.id);
  });

  test("the header leads to it", async ({ page }) => {
    await signIn(page, person);
    // Role "button": the Button component keeps it, even rendered as a link.
    await page.getByRole("button", { name: "Account" }).click();

    await expect(page.getByRole("heading", { name: "Your account" })).toBeVisible();
    await expect(page.getByText(`Signed in as ${person.username}`)).toBeVisible();
  });

  test("a new username is the one that signs in", async ({ page }) => {
    const renamed = `${person.username}.new`;

    await signIn(page, person);
    await page.goto("/account");
    await page.getByLabel("New username").fill(renamed);
    await page.locator("#account-username-password").fill(person.password);
    await page.getByRole("button", { name: "Change username" }).click();

    await expect(page.getByRole("status")).toContainText(`Your username is now ${renamed}`);

    await signOut(page);
    await signIn(page, { username: renamed, password: person.password });
  });

  test("the wrong current password changes nothing", async ({ page }) => {
    await signIn(page, person);
    await page.goto("/account");
    await page.getByLabel("New username").fill(`${person.username}.nope`);
    await page.locator("#account-username-password").fill("not-my-password");
    await page.getByRole("button", { name: "Change username" }).click();

    await expect(
      page.getByRole("alert").filter({ hasText: "current password" })
    ).toHaveText("Your current password is not right.");
  });

  test("a new password keeps this session and ends every other", async ({
    page,
    browser,
  }) => {
    // Another device, signed in before the change.
    const elsewhere = await browser.newPage();
    await signIn(elsewhere, person);

    await signIn(page, person);
    await page.goto("/account");
    const next = `second-password-${person.id}`;
    await page.locator("#account-current-password").fill(person.password);
    await page.locator("#account-new-password").fill(next);
    await page.locator("#account-confirm-password").fill(next);
    await page.getByRole("button", { name: "Change password" }).click();

    await expect(page.getByRole("status")).toContainText("Your password is changed");

    // Still signed in here.
    await page.goto("/my-training");
    await expect(page).toHaveURL(/\/my-training$/);

    // Signed out there, and told so rather than bounced about.
    await elsewhere.goto("/my-training");
    await expect(elsewhere).toHaveURL(/\/login\?session=ended$/);
    await expect(elsewhere.getByText("You were signed out")).toBeVisible();

    // And the new password is the one that works.
    await signOut(page);
    await signIn(page, { username: person.username, password: next });

    await elsewhere.close();
  });

  test("two new passwords that differ are caught", async ({ page }) => {
    await signIn(page, person);
    await page.goto("/account");
    await page.locator("#account-current-password").fill(person.password);
    await page.locator("#account-new-password").fill("one-long-password");
    await page.locator("#account-confirm-password").fill("another-long-password");
    await page.getByRole("button", { name: "Change password" }).click();

    await expect(
      page.getByRole("alert").filter({ hasText: "do not match" })
    ).toBeVisible();
  });
});
