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

  test("a programme still in draft can be previewed", async ({ page }) => {
    // Everything the builder creates starts as a draft; preview used to 404 on it.
    const draft = await wordpress.createNode("pcle_program", "E2E Draft Programme");
    const unit = await wordpress.createNode("pcle_unit", "E2E Draft Unit", draft.id);

    try {
      await signIn(page, people.instructor);
      await page.goto(`/builder/programs/${draft.id}`);
      await page.getByRole("button", { name: "Preview as participant" }).click();

      await expect(page).toHaveURL(new RegExp(`/programs/${draft.id}\\?preview=1$`));
      await expect(page.getByRole("heading", { name: draft.title })).toBeVisible();
      await expect(page.getByText(unit.title)).toBeVisible();
    } finally {
      await wordpress.deleteNode(draft.id, true);
    }
  });
});

test.describe("Reordering by dragging", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let units: { id: number; title: string }[];

  test.beforeEach(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.createNode("pcle_program", "E2E Drag Programme");
    units = [];
    for (const name of ["E2E Unit A", "E2E Unit B", "E2E Unit C"]) {
      units.push(await wordpress.createNode("pcle_unit", name, programme.id));
    }
  });

  test.afterEach(async () => {
    await wordpress.deleteNode(programme.id, true);
  });

  async function unitOrder(): Promise<string[]> {
    const tree = await wordpress.tree(programme.id);
    return (tree.children ?? [])
      .filter((child) => child.type === "pcle_unit")
      .map((child) => child.title);
  }

  test("a unit dragged down by its grip stays where it was put", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    const grip = page.getByRole("button", { name: "Drag to reorder E2E Unit A" });
    const c = page.getByRole("button", { name: "Drag to reorder E2E Unit C" });

    const from = await grip.boundingBox();
    const to = await c.boundingBox();
    if (!from || !to) {
      throw new Error("The grips are not on screen.");
    }

    // In steps: the drag only starts after a few pixels of travel.
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2, to.y + to.height / 2 + 4, { steps: 12 });
    await page.mouse.up();

    await expect
      .poll(unitOrder)
      .toEqual(["E2E Unit B", "E2E Unit C", "E2E Unit A"]);

    // And the page agrees after a reload, not just the optimistic render.
    await page.reload();
    const grips = page.getByRole("button", { name: /^Drag to reorder E2E Unit/ });
    await expect(grips.first()).toHaveAccessibleName("Drag to reorder E2E Unit B");
    await expect(grips.last()).toHaveAccessibleName("Drag to reorder E2E Unit A");
  });

  test("the keyboard can do it too", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    const grip = page.getByRole("button", { name: "Drag to reorder E2E Unit C" });
    await grip.focus();
    // Each key waits for what a screen reader would hear before the next.
    const heard = page.getByRole("status").filter({ hasText: "E2E Unit C" });
    await page.keyboard.press("Space");
    await expect(heard).toHaveText("Picked up E2E Unit C, position 3 of 3.");
    await expect(grip).toHaveAttribute("aria-pressed", "true");
    /*
     * dnd-kit measures the list just after pickup, and an arrow pressed before
     * that finds nowhere to go. No person is that quick; a test is, and there
     * is no DOM signal for "measured" to wait on instead.
     */
    await page.waitForTimeout(250);
    await page.keyboard.press("ArrowUp");
    await expect(heard).toHaveText("E2E Unit C moved to position 2 of 3.");
    await page.keyboard.press("Space");
    await expect(heard).toHaveText("E2E Unit C dropped at position 2 of 3.");

    await expect
      .poll(unitOrder)
      .toEqual(["E2E Unit A", "E2E Unit C", "E2E Unit B"]);
  });

  test("a row alone in its list has no grip", async ({ page }) => {
    const only = await wordpress.createNode("pcle_module", "E2E Only Module", units[0].id);

    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);
    await page.getByRole("button", { name: "Expand E2E Unit A" }).click();

    await expect(page.getByText(only.title)).toBeVisible();
    await expect(
      page.getByRole("button", { name: `Drag to reorder ${only.title}` })
    ).toHaveCount(0);
  });
});

test.describe("Attaching a file by dropping it on the body", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let lesson: { id: number; title: string };

  test.beforeEach(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.createNode("pcle_program", "E2E Drop Programme");
    const unit = await wordpress.createNode("pcle_unit", "E2E Drop Unit", programme.id);
    lesson = await wordpress.createNode("pcle_module", "E2E Drop Module", unit.id);
  });

  test.afterEach(async () => {
    await wordpress.deleteNode(programme.id, true);
  });

  /** A 1×1 PNG, small enough to write out here. */
  const PNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==";

  /**
   * Drops files on the body as a browser would: dragover first, which is what
   * tells the page a drop is welcome, then the drop itself.
   */
  async function dropOnBody(
    page: import("@playwright/test").Page,
    files: { name: string; type: string; base64: string }[]
  ) {
    const body = page.locator("#node-body");
    const transfer = await page.evaluateHandle((given) => {
      const data = new DataTransfer();
      for (const file of given) {
        const bytes = Uint8Array.from(atob(file.base64), (c) => c.charCodeAt(0));
        data.items.add(new File([bytes], file.name, { type: file.type }));
      }
      return data;
    }, files);

    /*
     * Until the page has hydrated, the field is plain HTML and a dragover goes
     * unanswered — on CI's production build that window is long enough to
     * lose. A browser sends dragover continuously while a file hovers, so
     * sending it again until the field answers is what a real drag does.
     */
    await expect(async () => {
      await body.dispatchEvent("dragover", { dataTransfer: transfer });
      await expect(page.getByText("Drop to attach here")).toBeVisible({ timeout: 500 });
    }).toPass();
    await body.dispatchEvent("drop", { dataTransfer: transfer });
  }

  test("a dropped image is attached and its marker put in the body", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/nodes/${lesson.id}`);

    await dropOnBody(page, [{ name: "diagram.png", type: "image/png", base64: PNG }]);

    await expect(page.locator("#node-body")).toHaveValue(/\[\[media:\d+\]\]/);
    await expect(page.getByText("Attached to this page.")).toBeVisible();
    await expect(page.getByText(/— diagram(-\d+)?\.png/)).toBeVisible();
  });

  test("several files go in one after another", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/nodes/${lesson.id}`);

    await dropOnBody(page, [
      { name: "first.png", type: "image/png", base64: PNG },
      { name: "second.png", type: "image/png", base64: PNG },
    ]);

    await expect(page.locator("#node-body")).toHaveValue(
      /\[\[media:\d+\]\][\s\S]*\[\[media:\d+\]\]/
    );
  });

  test("a file the builder does not take is refused before it is sent", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/nodes/${lesson.id}`);
    const before = await page.locator("#node-body").inputValue();

    await dropOnBody(page, [
      { name: "notes.txt", type: "text/plain", base64: btoa("hello") },
    ]);

    await expect(
      page.getByRole("alert").filter({ hasText: "notes.txt" })
    ).toContainText("notes.txt is not a file the builder takes");
    await expect(page.locator("#node-body")).toHaveValue(before);
  });
});

test.describe("Attaching an image by pasting it", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let lesson: { id: number; title: string };

  test.beforeEach(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.createNode("pcle_program", "E2E Paste Programme");
    const unit = await wordpress.createNode("pcle_unit", "E2E Paste Unit", programme.id);
    lesson = await wordpress.createNode("pcle_module", "E2E Paste Module", unit.id);
  });

  test.afterEach(async () => {
    await wordpress.deleteNode(programme.id, true);
  });

  const PNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==";

  /** Pastes into the body as the browser would, with whatever the clipboard holds. */
  async function pasteIntoBody(
    page: import("@playwright/test").Page,
    clipboard: { image?: boolean; text?: string }
  ) {
    /*
     * A paste cannot be retried the way a dragover can — a second one would
     * upload twice — so first make sure the page has hydrated, using the drop
     * overlay as the signal that the field's handlers are attached. On CI the
     * dev server compiles each route on first visit and hydration is late.
     */
    const body = page.locator("#node-body");
    const probe = await page.evaluateHandle(() => {
      const data = new DataTransfer();
      data.items.add(new File(["x"], "probe.png", { type: "image/png" }));
      return data;
    });
    await expect(async () => {
      await body.dispatchEvent("dragover", { dataTransfer: probe });
      await expect(page.getByText("Drop to attach here")).toBeVisible({ timeout: 500 });
    }).toPass();
    await body.dispatchEvent("dragleave", { dataTransfer: probe });
    await expect(page.getByText("Drop to attach here")).toHaveCount(0);

    await body.evaluate(
      (textarea, { png, clipboard }) => {
        const data = new DataTransfer();
        if (clipboard.text !== undefined) {
          data.setData("text/plain", clipboard.text);
        }
        if (clipboard.image) {
          const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
          data.items.add(new File([bytes], "image.png", { type: "image/png" }));
        }
        textarea.focus();
        textarea.dispatchEvent(
          new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true })
        );
      },
      { png: PNG, clipboard }
    );
  }

  test("a pasted screenshot is attached at the caret, with a name of its own", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/nodes/${lesson.id}`);
    await page.locator("#node-body").fill("Before.\n\nAfter.");
    await page.locator("#node-body").evaluate((textarea: HTMLTextAreaElement) => {
      textarea.setSelectionRange(9, 9);
    });

    await pasteIntoBody(page, { image: true });

    await expect(page.locator("#node-body")).toHaveValue(
      /^Before\.\n\n\[\[media:\d+\]\]\n\nAfter\.$/
    );
    await expect(page.getByText(/— pasted-image-\d{8}-\d{6}(-\d+)?\.png/)).toBeVisible();
  });

  test("text that comes with a picture of itself is left to paste as text", async ({
    page,
  }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/nodes/${lesson.id}`);

    // What copying from Word looks like: the words, and an image of them.
    await pasteIntoBody(page, { image: true, text: "From Word" });

    await expect(page.getByText("Attached to this page.")).toHaveCount(0);
    await expect(page.locator("#node-body")).not.toHaveValue(/\[\[media:/);
  });
});

test.describe("Backing up a programme", () => {
  let wordpress: WordPress;
  let programme: { id: number; title: string };
  let restoredId: number | null = null;

  test.beforeAll(async () => {
    wordpress = await WordPress.asAdministrator();
    programme = await wordpress.firstProgramme();
  });

  test.afterAll(async () => {
    if (restoredId) {
      await wordpress.deleteNode(restoredId, true);
    }
  });

  test("a backup downloads, and restores as a new draft", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto(`/builder/programs/${programme.id}`);

    const [download] = await Promise.all([
      page.waitForEvent("download"),
      // Role "button": the Button component keeps it, even rendered as a link.
      page.getByRole("button", { name: "Download backup" }).click(),
    ]);

    expect(download.suggestedFilename()).toMatch(/-backup-\d{4}-\d{2}-\d{2}\.json$/);
    const file = await download.path();

    await page.goto("/builder");
    await page.getByText("Restore from a backup").click();
    await page.getByLabel("Backup file").setInputFiles(file);
    await page.getByRole("button", { name: "Restore as a new programme" }).click();

    /*
     * A new programme, not the one the backup came from: the restore opens
     * it, and its id is not the original's.
     */
    await expect(page).toHaveURL(/\/builder\/programs\/\d+$/);
    restoredId = Number(page.url().split("/").pop());
    expect(restoredId).not.toBe(programme.id);

    await expect(page.getByRole("heading", { name: programme.title })).toBeVisible();
    await expect(page.getByText("Draft", { exact: true }).first()).toBeVisible();
  });

  test("a file that is not a backup is refused, and says so", async ({ page }) => {
    await signIn(page, people.instructor);
    await page.goto("/builder");
    await page.getByText("Restore from a backup").click();
    await page.getByLabel("Backup file").setInputFiles({
      name: "notes.json",
      mimeType: "application/json",
      buffer: Buffer.from('{"hello":"world"}'),
    });
    await page.getByRole("button", { name: "Restore as a new programme" }).click();

    // By text: Next's route announcer is an alert too.
    await expect(page.getByText(/not a programme backup/)).toBeVisible();
    await expect(page).toHaveURL(/\/builder$/);
  });
});
