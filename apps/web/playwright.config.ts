import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end tests for the app, against a real WordPress.
 *
 * The plugin has 546 assertions and the app had none: every release was
 * verified by a person driving a browser, which is neither repeatable nor
 * something a second person can do the same way twice. These cover the paths
 * that would be embarrassing to break — signing in, what each role is offered,
 * and managing a cohort.
 *
 * They need the stack up (`docker compose up -d wordpress`), because the
 * things worth testing here are precisely the ones that depend on what
 * WordPress says: who you are, what you may see, what happens when you
 * enrol someone.
 */
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  /*
   * One worker. These tests enrol and remove real people in a shared
   * WordPress; run two at once and they arrange each other's fixtures out
   * from under themselves.
   */
  workers: 1,
  reporter: process.env.CI ? [["github"], ["list"]] : [["list"]],

  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },

  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],

  /*
   * Reuses a dev server that is already running, which is what happens on a
   * laptop; starts one otherwise, which is what happens in CI.
   */
  webServer: {
    command: "npm run dev",
    url: "http://localhost:3000/login",
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
