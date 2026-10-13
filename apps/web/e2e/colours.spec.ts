import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

/**
 * An author colours a programme, everything inside it follows, and one item
 * can be given a colour of its own and then handed back.
 */
test.describe("Colours", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let unit: { id: number; title: string };
  let courseModule: { id: number; title: string };

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.createNode("pcle_program", "E2E Colour Programme");
    unit = await wordpress.createNode("pcle_unit", "E2E Colour Unit", programme.id);
    courseModule = await wordpress.createNode("pcle_module", "E2E Colour Module", unit.id);
  });

  test.afterAll(async () => {
    await wordpress.deleteNode(programme.id, true);
  });

  /** Opens the colour menu (D3) for one titled item and picks a swatch. */
  async function choose(page: import("@playwright/test").Page, title: string, swatch: string) {
    await page.getByRole("button", { name: new RegExp(`^Colour for ${title}:`) }).click();
    await page.getByRole("menuitem", { name: swatch, exact: true }).click();
  }

  test("a programme's colour reaches everything inside it", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    await choose(page, programme.title, "Teal strong");

    await expect(
      page.getByRole("button", { name: `Colour for ${programme.title}: Teal strong, its own colour` })
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: `Colour for ${unit.title}: Teal strong, from the programme` })
    ).toBeVisible();
  });

  test("an item can have its own colour, and give it back", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    await choose(page, unit.title, "Plum soft");

    await expect(
      page.getByRole("button", { name: `Colour for ${unit.title}: Plum soft, its own colour` })
    ).toBeVisible();

    // Units arrive folded; the module inside takes the unit's colour.
    await page.getByRole("button", { name: `Expand ${unit.title}` }).click();
    await expect(
      page.getByRole("button", { name: `Colour for ${courseModule.title}: Plum soft, from the unit` })
    ).toBeVisible();

    await page.getByRole("button", { name: new RegExp(`^Colour for ${unit.title}:`) }).click();
    await page.getByRole("menuitem", { name: "Use the programme's colour" }).click();

    await expect(
      page.getByRole("button", { name: `Colour for ${unit.title}: Teal strong, from the programme` })
    ).toBeVisible();
  });
});

/**
 * The Colours screen (P16): the badges' colours are an administrator's, and
 * each state keeps to its own family.
 */
test.describe("Colour settings", () => {
  let wordpress: WordPress;

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
  });

  test.afterAll(async () => {
    await wordpress.setColourSettings({});
  });

  test("an administrator recolours every Draft badge", async ({ page }) => {
    await signIn(page, people.administrator);
    await page.goto("/colours");

    await page.getByRole("button", { name: /^Colour for “Draft”:/ }).click();

    // Draft keeps to warm colours: a green is not offered.
    await expect(page.getByRole("menuitem", { name: "Emerald soft", exact: true })).toBeDisabled();

    await page.getByRole("menuitem", { name: "Gold soft", exact: true }).click();

    await expect(page.getByRole("button", { name: "Colour for “Draft”: Gold soft" })).toBeVisible();

    // The colour reaches every screen from the root layout, the builder included.
    await page.goto("/builder");
    const style = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue("--state-draft-bg").trim()
    );
    expect(style).toBe("oklch(0.935 0.045 95)");
  });

  test("an instructor sees the defaults but cannot change them", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto("/colours");

    await expect(page.getByText("Only administrators change these.").first()).toBeVisible();
    await expect(page.getByRole("button", { name: /^Colour for “Draft”:/ })).toBeDisabled();
    await expect(page.getByRole("button", { name: "Hide colours from participants" })).toBeDisabled();
  });
});
