import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

test.describe("Who has an account", () => {
  test("a participant cannot see the list", async ({ page }) => {
    await signIn(page, people.participant);
    await page.goto("/people");

    await expect(
      page.getByText("You do not have access to this list")
    ).toBeVisible();
  });

  test("an instructor can read it and cannot act on it", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto("/people");

    await expect(page.getByRole("heading", { name: "People" })).toBeVisible();
    await expect(page.getByRole("table")).toContainText("CLE Instructor");

    /*
     * Changing a role takes promote_users, which teaching does not grant.
     * The row says so rather than offering a control that would be refused.
     */
    await expect(page.getByRole("button", { name: "Change" })).toHaveCount(0);
    await expect(
      page.getByText("Only an administrator may change a role").first()
    ).toBeVisible();
  });

  test("an administrator is offered it, except on their own row", async ({
    page,
  }) => {
    await signIn(page, people.administrator);
    await page.goto("/people");

    await expect(
      page.getByRole("button", { name: "Change" }).first()
    ).toBeVisible();

    // The most common way to lock everyone out is to demote yourself while
    // alone in the system.
    const ownRow = page
      .getByRole("row")
      .filter({ hasText: "Administrator" })
      .first();

    await expect(ownRow.getByRole("button", { name: "Change" })).toHaveCount(0);
    await expect(ownRow).toContainText("managed in WordPress");
  });
});

test.describe("Changing what someone may do", () => {
  let wordpress: WordPress;
  let subject: string;

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    // Someone enrolled in nothing, so a change of role disturbs no cohort.
    subject = await wordpress.emailOf("demo.outsider");
  });

  test.afterEach(async () => {
    await wordpress.setRole(subject, "pcle_student");
  });

  test("an administrator promotes and is told it took", async ({ page }) => {
    await signIn(page, people.administrator);
    await page.goto("/people");

    const row = page.getByRole("row").filter({ hasText: subject });

    await row.getByRole("combobox").selectOption("pcle_instructor");
    await row.getByRole("button", { name: "Change" }).click();

    await expect(page.getByText(/is now CLE Instructor/)).toBeVisible();
    await expect(row).toContainText("CLE Instructor");
  });
});

test.describe("Where People is offered", () => {
  test("to staff", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto("/reports");

    await expect(
      page.getByRole("banner").getByRole("button", { name: "People" })
    ).toBeVisible();
  });

  test("and not to a participant", async ({ page }) => {
    await signIn(page, people.participant);

    await expect(
      page.getByRole("banner").getByRole("button", { name: "People" })
    ).toHaveCount(0);
  });
});
