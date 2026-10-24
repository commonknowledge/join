import { Page, test as base, expect } from '@playwright/test';
import { mkdtempSync, rmSync } from 'fs';
import os from 'os';
import path from 'path';
import { CONTINUE, captureJoinBodyViaStripeRedirect, loginAsAdmin, mockRestEndpoints } from '../helpers';
import { resetFakeApi } from './fake-api';
import { JoinSettingsPage, STANDARD_JOIN_PAGE, postJoin, resetSettings, setBaselinePlan } from './support';

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

/** Pages whose join form REST calls are already stubbed (stub them once each). */
const pagesWithStubs = new WeakSet<Page>();

/**
 * Opens a join form at its first step as a visitor, with the form's REST
 * calls stubbed. The form remembers progress in the browser tab (session
 * storage, and the history entry, which reopening the same URL keeps), so
 * both are cleared first.
 */
export async function openJoinForm(page: Page, url = STANDARD_JOIN_PAGE): Promise<void> {
  if (!pagesWithStubs.has(page)) {
    await mockRestEndpoints(page);
    pagesWithStubs.add(page);
  }
  if (page.url().startsWith('http')) {
    await page.evaluate(() => {
      sessionStorage.clear();
      history.replaceState(null, '');
    });
  }
  await page.goto(url);
  await page.waitForSelector('input#firstName');
}

/**
 * Serves a stand-in for Chargebee's script (with Chargebee on, the form won't
 * start without it), which records the settings the form initialises
 * Chargebee with in window.chargebeeInitOptions. Its card fields render as
 * nothing, but are there, so the form's payment step works.
 */
export async function stubChargebee(page: Page): Promise<void> {
  const script = `(() => {
    const field = { at() { return field; }, on() { return field; }, update() {}, focus() {}, blur() {}, clear() {} };
    const component = {
      status: 0,
      on() { return component; },
      mount() { component.status = 1; return Promise.resolve(); },
      update() {},
      createField() { return field; },
      tokenize() { return Promise.resolve({ token: 'cb_e2e_token' }); },
      focus() {}, blur() {}, clear() {},
    };
    const instance = {
      load() { return Promise.resolve(); },
      createComponent() { return component; },
    };
    window.Chargebee = {
      init(options) {
        window.chargebeeInitOptions = options;
        Object.assign(instance, { site: options.site, publishableKey: options.publishableKey });
        return instance;
      },
      getInstance() { return instance; },
    };
  })();`;
  await page.route('https://js.chargebee.com/**', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: script }),
  );
}

/**
 * Serves a stand-in for Stripe.js, which records the publishable key the form
 * uses in window.stripePublishableKey and the options it sets Stripe's
 * payment fields up with in window.stripeElementsOptions (including
 * paymentMethodTypes: card, bacs_debit). The payment fields render as nothing.
 */
export async function stubStripe(page: Page): Promise<void> {
  const script = `(() => {
    const element = { mount() {}, unmount() {}, destroy() {}, update() {}, on() { return element; }, off() {}, collapse() {} };
    window.Stripe = function (key) {
      window.stripePublishableKey = key;
      return {
        elements(options) {
          window.stripeElementsOptions = options;
          return {
            create() { return element; },
            getElement() { return element; },
            update(changes) { Object.assign(window.stripeElementsOptions, changes); },
            submit() { return Promise.resolve({}); },
            fetchUpdates() { return Promise.resolve({}); },
          };
        },
        createToken() {}, createPaymentMethod() {}, confirmCardPayment() {}, confirmPayment() {}, confirmSetup() {},
      };
    };
  })();`;
  await page.route('https://js.stripe.com/**', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: script }),
  );
}

/** From the details step, on to the plan step. */
export async function continueToPlans(page: Page): Promise<void> {
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
}

/** From the details step, through the plan step (first plan), to whatever comes next. */
export async function continueToPayment(page: Page): Promise<void> {
  await continueToPlans(page);
  await page.locator('label.radio-panel').first().click();
  await page.locator(CONTINUE).click();
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

/**
 * Joins through the join form on `url` as a visitor, and returns the join the
 * form sent. Payment is skipped: the form's request to /join is caught at its
 * last step (as after a Stripe payment) and passed on to the plugin
 * unchanged, so the plugin handles exactly what the form sends.
 *
 * `fillDetails` can change the details step before moving on.
 */
export async function joinThroughForm(
  page: Page,
  url = STANDARD_JOIN_PAGE,
  fillDetails?: (page: Page) => Promise<void>,
): Promise<Record<string, any>> {
  await openJoinForm(page, url);
  if (fillDetails) {
    await fillDetails(page);
  }
  await continueToPlans(page);
  const join = await captureJoinBodyViaStripeRedirect(page, url);
  const response = await postJoin(page.request, join);
  expect(response.ok(), `the plugin refused the join: ${await response.text()}`).toBe(true);
  return join;
}
