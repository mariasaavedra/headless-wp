import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

test.describe("Building a curriculum", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
  });

  test("an instructor sees what they may build", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto("/builder");

    await expect(page.getByRole("heading", { name: "Build" })).toBeVisible();
    await expect(page.getByRole("link", { name: programme.title })).toBeVisible();
  });

  test("a programme shows the units it is made of", async ({ page }) => {
    const tree = await wordpress.tree(programme.id);
    const unit = (tree.children ?? []).find(
      (child) => child.type === "pcle_unit"
    );

    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    await expect(
      page.getByRole("heading", { name: programme.title })
    ).toBeVisible();

    if (unit) {
      await expect(page.getByText(unit.title)).toBeVisible();
    }
  });

  test("a participant is refused the builder", async ({ page }) => {
    await signIn(page, people.participant);
    await page.goto("/builder");

    /*
     * The route is guarded server-side regardless of what the header offers,
     * which is the half worth testing: a participant who types the address
     * gets the same answer as one who was never shown the link.
     */
    await expect(page.getByText(/do not have access|not enrolled/i)).toBeVisible();
  });
});

test.describe("Adding to a programme", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
  });

  // Whatever the test added, the curriculum should not keep.
  test.afterEach(async () => {
    const stray = await wordpress.findByTitle(programme.id, "New unit");

    if (stray) {
      await wordpress.deleteNode(stray.id);
    }
  });

  test("adding a unit puts it in the tree", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    await page.getByRole("button", { name: "Add unit" }).click();

    // The builder creates it with a placeholder title and lets the author
    // rename it in place, so this is what a new unit looks like.
    await expect(page.getByText("New unit").first()).toBeVisible();

    const created = await wordpress.findByTitle(programme.id, "New unit");
    expect(created).not.toBeNull();
    expect(created?.type).toBe("pcle_unit");
  });
});

test.describe("Previewing as a participant", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
  });

  test("the editor offers it, and it says what it is", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    await page.getByRole("button", { name: "Preview as participant" }).click();

    await expect(page).toHaveURL(new RegExp(`/programs/${programme.id}\\?preview=1$`));
    await expect(page.getByText("Previewing as a participant")).toBeVisible();
  });

  test("it reports nothing done, whatever the author has done", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/programs/${programme.id}?preview=1`);

    /*
     * The point of the feature: an author's own records shape these screens
     * for them, and none of it is what the cohort meets on day one.
     */
    await expect(page.getByText(/0 of \d+ modules · 0%/).first()).toBeVisible();
  });

  test("the preview survives a click", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/programs/${programme.id}?preview=1`);

    const tree = await wordpress.tree(programme.id);
    const unit = (tree.children ?? []).find((c) => c.type === "pcle_unit");
    const lesson = (unit?.children ?? []).find((c) => c.type === "pcle_module");

    if (!lesson) {
      throw new Error("No module to click into.");
    }

    await page.getByText(lesson.title).first().click();

    // A preview that ends silently one click in is worse than none.
    await expect(page).toHaveURL(/preview=1/);
    await expect(page.getByText("Previewing as a participant")).toBeVisible();
  });

  test("leaving it goes back to the editor", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/programs/${programme.id}?preview=1`);

    await page.getByRole("button", { name: "Leave preview" }).click();

    await expect(page).toHaveURL(new RegExp(`/builder/programs/${programme.id}$`));
  });
});
