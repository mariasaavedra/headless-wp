import { expect, test } from "@playwright/test";

import { people } from "./support/people";
import { signIn } from "./support/session";
import { WordPress } from "./support/wordpress";

/**
 * A webinar is one video. The author says so with one click, and the
 * participant lands on the video with nothing to navigate through.
 */
test.describe("Webinars", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let participantEmail: string;

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.createNode("pcle_program", "E2E Webinar");
    participantEmail = await wordpress.emailOf(people.participant.username);
  });

  test.afterAll(async () => {
    await wordpress.remove(programme.id, participantEmail);
    await wordpress.deleteNode(programme.id, true);
  });

  test("an author turns a programme into a webinar", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    await page.getByRole("button", { name: "Webinar", exact: true }).click();

    await expect(
      page.getByRole("button", { name: "Webinar", exact: true })
    ).toHaveAttribute("aria-pressed", "true");

    // The one module, named after the programme; no unit to add.
    const tree = await wordpress.tree(programme.id);
    const unit = tree.children?.[0];
    expect(unit?.type).toBe("pcle_unit");
    expect(unit?.children?.[0]?.type).toBe("pcle_module");

    await expect(page.getByRole("button", { name: "Add unit" })).toHaveCount(0);
  });

  test("a participant lands straight on the video", async ({ page }) => {
    const tree = await wordpress.tree(programme.id);
    const courseModule = tree.children![0].children![0];

    await wordpress.updateNode(courseModule.id, { body: "The recording." });

    // Only the programme: its author cannot see the unit to publish it, so
    // publishing the webinar has to be enough.
    await wordpress.updateNode(programme.id, { status: "publish" });

    await wordpress.enrol(programme.id, participantEmail);

    await signIn(page, people.participant);
    await page.goto("/my-training");

    const card = page.getByRole("link", { name: /E2E Webinar/ });
    await expect(card.getByText("Webinar", { exact: true })).toBeVisible();

    await card.click();

    await expect(page).toHaveURL(new RegExp(`/modules/${courseModule.id}$`));
    await expect(page.getByText("The recording.")).toBeVisible();

    // Back to My Training in one step: there is no unit or programme screen
    // to return to.
    const trail = page.getByRole("navigation", { name: /breadcrumb/i });
    await expect(trail.getByRole("link")).toHaveCount(1);
  });
});
