import { expect, type Page } from "@playwright/test";

import type { Person } from "./people";

/**
 * Signs in through the form, not by planting a cookie.
 *
 * The form is one of the things being tested, and a cookie planted directly
 * would skip the exchange with WordPress that the rest of the session
 * depends on.
 */
async function signIn(page: Page, person: Person): Promise<void> {
  await page.goto("/login");
  await page.getByPlaceholder("Username").fill(person.username);
  await page.getByPlaceholder("Password").fill(person.password);
  await page.getByRole("button", { name: "Log in" }).click();

  // Signing in always leaves /login. Where it lands is a test of its own.
  await expect(page).not.toHaveURL(/\/login$/);
}

async function signOut(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Log out" }).click();
  await expect(page).toHaveURL(/\/login$/);
}

export { signIn, signOut };
