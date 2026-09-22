import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn, signOut } from "./support/session";

test.describe("Signing in", () => {
  test("a participant goes straight to their training", async ({ page }) => {
    await signIn(page, people.participant);

    /*
     * Not the menu. A participant has exactly one path, and a menu of one is
     * a screen asking them to confirm the only thing they could have wanted.
     */
    await expect(page).toHaveURL(/\/my-training$/);
    await expect(
      page.getByRole("heading", { name: "My Training" })
    ).toBeVisible();
  });

  test("an administrator lands on the menu, which offers a choice", async ({
    page,
  }) => {
    await signIn(page, people.administrator);

    await expect(page).toHaveURL(new RegExp(`${page.url().split("/")[2]}/?$`));
    await expect(page.getByRole("link", { name: /My Training/ })).toBeVisible();
    await expect(page.getByRole("link", { name: /^Build/ })).toBeVisible();
    await expect(page.getByRole("link", { name: /^Reports/ })).toBeVisible();
  });

  test("a bad password is told apart from a broken backend", async ({
    page,
  }) => {
    await page.goto("/login");
    await page.getByPlaceholder("Username").fill("nobody-by-this-name");
    await page.getByPlaceholder("Password").fill("wrong");
    await page.getByRole("button", { name: "Log in" }).click();

    /*
     * The wording matters more than it looks. "Unable to log in right now"
     * is what the app says when it could not reach WordPress at all, and a
     * production incident was diagnosed from that difference.
     */
    await expect(page.getByText("Invalid username or password.")).toBeVisible();
  });

  test("signing in again is not asked of someone already signed in", async ({
    page,
  }) => {
    await signIn(page, people.participant);
    await page.goto("/login");

    await expect(page).not.toHaveURL(/\/login$/);
  });

  test("signing out returns to the form", async ({ page }) => {
    await signIn(page, people.participant);
    await signOut(page);

    await expect(page.getByPlaceholder("Username")).toBeVisible();
  });
});

test.describe("Screens nobody has signed in for", () => {
  for (const path of ["/my-training", "/reports", "/builder"]) {
    test(`${path} sends an anonymous visitor to the form`, async ({ page }) => {
      await page.goto(path);
      await expect(page).toHaveURL(/\/login$/);
    });
  }

  test("the front door still opens", async ({ page }) => {
    const response = await page.goto("/");

    expect(response?.status()).toBe(200);
    await expect(page.getByRole("button", { name: "Sign in" })).toBeVisible();
  });
});
