import { Page, test as base, expect } from '@playwright/test';
import { mkdtempSync, rmSync } from 'fs';
import os from 'os';
import path from 'path';
import { CONTINUE, loginAsAdmin, mockRestEndpoints } from '../helpers';
import { resetFakeApi } from './fake-api';
import { JoinSettingsPage, STANDARD_JOIN_PAGE, resetSettings, setBaselinePlan } from './support';

export * from './support';
export * from './fake-api';

/**
 * Fixtures for the settings regression tests.
 *
 * - `settings`: the WP Admin > Join settings page, in a browser logged in as
 *   the admin (logged in once per worker).
 * - `page`: a separate, logged-out browser for the join form, as a visitor
 *   sees it.
 *
 * Every test starts from the seeded settings plus setBaselinePlan(), with the
 * fake APIs reset. Afterwards the settings are put back as the seed left them
 * (which is also what the next test starts from), so other specs are
 * unaffected.
 */
export const test = base.extend<{ settings: JoinSettingsPage; baseline: void }, { adminStorageState: string }>({
  baseline: [
    async ({}, use) => {
      await resetFakeApi();
      setBaselinePlan();
      await use();
      resetSettings();
    },
    { auto: true },
  ],

  adminStorageState: [
    async ({ browser }, use) => {
      // Holds the admin's session cookies: removed when the worker is done.
      const dir = mkdtempSync(path.join(os.tmpdir(), 'join-e2e-'));
      const file = path.join(dir, 'admin.json');
      const page = await browser.newPage();
      await loginAsAdmin(page);
      await page.context().storageState({ path: file });
      await page.close();
      await use(file);
      rmSync(dir, { recursive: true, force: true });
    },
    { scope: 'worker' },
  ],

  settings: async ({ browser, adminStorageState }, use) => {
    const context = await browser.newContext({ storageState: adminStorageState });
    await use(new JoinSettingsPage(await context.newPage()));
    await context.close();
  },
});

export { expect };

/** Opens a join form as a visitor, with the form's REST calls stubbed. */
export async function openJoinForm(page: Page, url = STANDARD_JOIN_PAGE): Promise<void> {
  await mockRestEndpoints(page);
  await page.goto(url);
  await page.waitForSelector('input#firstName');
}

/**
 * Serves a stand-in for Chargebee's script (with Chargebee on, the form won't
 * start without it), which records the settings the form initialises
 * Chargebee with in window.chargebeeInitOptions.
 */
export async function stubChargebee(page: Page): Promise<void> {
  await page.route('https://js.chargebee.com/**', (route) =>
    route.fulfill({
      contentType: 'application/javascript',
      body: 'window.Chargebee = { init: function (options) { window.chargebeeInitOptions = options; return {}; }, getInstance: function () { return {}; } };',
    }),
  );
}

/**
 * Clicks Continue and checks the form rejected the details: it marks a field
 * invalid and stays on the details step.
 */
export async function expectDetailsRejected(page: Page): Promise<void> {
  await page.locator(CONTINUE).click();
  await expect(page.locator('.is-invalid, .invalid-feedback:visible').first()).toBeVisible();
  await expect(page.locator('.progress-step--current')).toContainText('Your Details');
}

/** Clicks Continue and checks the form moved on: the details step is done. */
export async function expectDetailsAccepted(page: Page): Promise<void> {
  await page.locator(CONTINUE).click();
  await expect(page.locator('.progress-step--done').first()).toContainText('Your Details');
}
