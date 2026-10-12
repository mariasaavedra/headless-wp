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
