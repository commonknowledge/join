import { execSync } from 'child_process';
import { test, expect, Page } from '@playwright/test';

/**
 * wp-admin smoke test
 *
 * The plugin registers exactly one admin surface via Carbon Fields:
 *
 *   /wp-admin/admin.php?page=crb_carbon_fields_container_ck_join_flow
 *
 * with six tabs: Features, Membership Plans, Theme, Copy, Integrations,
 * Logging (defined in packages/join-block/src/Settings.php:236-242).
 *
 * The most recent tag was cut after fixing a regression that made this admin
 * page unusable — clicking around the backend with the plugin activated
 * produced errors. This spec is the minimum coverage that would have caught
 * that: prove every tab loads and that a Carbon Fields field round-trips
 * through the Save button.
 */

const SETTINGS_URL =
  '/wp-admin/admin.php?page=crb_carbon_fields_container_ck_join_flow.php';

const TABS = [
  'Features',
  'Membership Plans',
  'Theme',
  'Copy',
  'Integrations',
  'Logging',
] as const;

/**
 * Log in via the standard wp-login form using wp-env defaults.
 *
 * Inlined rather than factored into a shared helper because there is only
 * one admin spec so far; once there are two, extracting this to a helper is
 * worthwhile.
 */
async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.waitForSelector('input[name="log"]');
  // WordPress' login form autofocuses #user_login and some Chromium builds
  // race with autocomplete. Use the form-field names directly and clear
  // explicitly before typing, so the fill survives any late-arriving JS.
  const userInput = page.locator('input[name="log"]');
  const passInput = page.locator('input[name="pwd"]');
  await userInput.click();
  await userInput.fill('');
  await userInput.fill('admin');
  await passInput.click();
  await passInput.fill('');
  await passInput.fill('password');
  await page.click('input[name="wp-submit"]');
  // The admin bar appears once the authenticated dashboard has rendered.
  await page.waitForSelector('#wpadminbar', { timeout: 15000 });
}

/**
 * Errors we expect on a freshly-installed test WordPress with no credentials
 * filled in. These come from third-party SDKs that the plugin initialises
 * with empty keys; they are pre-existing, documented, and not the kind of
 * regression this smoke test is chartered to catch.
 */
const IGNORED_ERROR_PATTERNS: RegExp[] = [
  /Please call Stripe\(\) with your publishable key/i,
];

/**
 * Attach listeners that fail the test if WordPress returns a 5xx or if the
 * page fires an uncaught JS error originating from the plugin or WordPress
 * itself (as opposed to third-party SDKs complaining about empty test creds).
 */
function guardAgainstAdminBreakage(page: Page): { errors: Error[] } {
  const errors: Error[] = [];
  page.on('pageerror', (err) => {
    if (IGNORED_ERROR_PATTERNS.some((re) => re.test(err.message))) {
      return;
    }
    errors.push(err);
  });
  page.on('response', (response) => {
    const status = response.status();
    if (status >= 500 && response.url().includes('/wp-admin/')) {
      errors.push(
        new Error(`5xx on ${response.request().method()} ${response.url()}: ${status}`),
      );
    }
  });
  return { errors };
}

test.describe('Settings page loads', () => {
  test('every tab renders without a PHP error or 5xx', async ({ page }) => {
    const { errors } = guardAgainstAdminBreakage(page);
    await loginAsAdmin(page);
    await page.goto(SETTINGS_URL);

    // The Carbon Fields React app hydrates the settings container on load;
    // the tab list is rendered inside it.
    await page.waitForSelector('li[role="tab"]');

    for (const label of TABS) {
      const tab = page.locator(`li[role="tab"]:has(button:text-is("${label}"))`);
      await expect(tab).toBeVisible();
      await tab.locator('button').click();
      await expect(tab).toHaveAttribute('aria-selected', 'true');

      // Exactly one field panel must be visible after the click. Carbon Fields
      // toggles panels with the `hidden` attribute, so ":not([hidden])" is the
      // truth source here.
      const visiblePanels = page.locator('.cf-container__fields:not([hidden])');
      await expect(visiblePanels).toHaveCount(1);
    }

    expect(errors, `page errors: ${errors.map((e) => e.message).join(' | ')}`).toHaveLength(0);
  });
});

/**
 * The Carbon Fields save button submits the full container (every tab), so
 * any option that isn't mirrored as a visible form field gets cleared on
 * save. In particular, stripe_direct_debit_only — seeded as true by
 * setup.php for the allow-cards-override spec — is inside a conditional tab
 * and gets wiped once the round-trip test saves the form.
 *
 * Re-seed by re-running the admin-fixture portion of setup.php after every
 * round-trip save so later specs see the state they expect. This is a
 * cross-spec compatibility concern rather than a smoke-test concern.
 */
function restoreAdminFixtures(): void {
  execSync(
    [
      'npx wp-env run tests-cli wp eval',
      `"carbon_set_theme_option('stripe_direct_debit_only', true);"`,
    ].join(' '),
    { stdio: 'ignore' },
  );
}

test.describe('Carbon Fields round-trip', () => {
  test.afterAll(() => {
    restoreAdminFixtures();
  });

  test('updating organisation_name on the Copy tab persists after reload', async ({
    page,
  }) => {
    const { errors } = guardAgainstAdminBreakage(page);
    await loginAsAdmin(page);
    await page.goto(SETTINGS_URL);
    await page.waitForSelector('li[role="tab"]');

    // Switch to the Copy tab.
    await page
      .locator('li[role="tab"]:has(button:text-is("Copy"))')
      .locator('button')
      .click();

    // Find the organisation_name field via its label (Carbon Fields titleizes
    // the field name into "Organisation Name").
    const input = page.getByRole('textbox', { name: /^Organisation Name\*?$/ }).first();
    await expect(input).toBeVisible();

    const original = (await input.inputValue()) ?? '';
    const testValue = `CK Smoke Test ${Date.now()}`;

    await input.click();
    await input.fill(testValue);
    // Carbon Fields' Redux store picks up dirty state from the input's native
    // change event, which React's controlled-input plumbing fires on blur.
    // Press Tab explicitly so the Save button transitions to enabled before
    // we try to click it.
    await input.press('Tab');
    await expect(page.locator('input[type="submit"]#publish')).toBeEnabled();
    await page.click('input[type="submit"]#publish');

    // After save, Carbon Fields reloads the page with settings-updated=true.
    await page.waitForURL(/settings-updated=true/);
    await expect(page.locator('.settings-error.updated')).toBeVisible();

    // Reload to prove the new value is what came back from the database,
    // not just what is still in the browser's form state.
    await page.reload();
    await page.waitForSelector('li[role="tab"]');
    await page
      .locator('li[role="tab"]:has(button:text-is("Copy"))')
      .locator('button')
      .click();
    await expect(
      page.getByRole('textbox', { name: /^Organisation Name\*?$/ }).first(),
    ).toHaveValue(testValue);

    // Restore the original value so repeated local runs stay idempotent.
    const restoreInput = page
      .getByRole('textbox', { name: /^Organisation Name\*?$/ })
      .first();
    await restoreInput.click();
    await restoreInput.fill(original);
    await restoreInput.press('Tab');
    await expect(page.locator('input[type="submit"]#publish')).toBeEnabled();
    await page.click('input[type="submit"]#publish');
    await page.waitForURL(/settings-updated=true/);

    expect(errors, `page errors: ${errors.map((e) => e.message).join(' | ')}`).toHaveLength(0);
  });
});
