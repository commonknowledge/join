import { test, expect, Page } from '@playwright/test';
import { field, loginAsAdmin, openNewPageWithJoinBlock, SETTINGS_PAGE, wpCli } from './helpers';

/**
 * Income guidance settings in wp-admin
 *
 * Income guidance is off unless an add-on switches it on. The e2e site
 * switches it on (see mu-plugins/), and these tests turn it off to check
 * that nothing is added for sites that have not opted in.
 */

const DEFAULT_HEADING = 'Below are some recommended monthly dues amounts, based on income';

function setIncomeGuidance(on: boolean): void {
  wpCli(`wp option update ck_e2e_income_guidance_enabled '${on ? '1' : ''}'`);
}

async function openSettingsTab(page: Page, tab: string): Promise<void> {
  await page.goto(SETTINGS_PAGE);
  await page.getByRole('tab', { name: tab }).click();
}

/** The first global plan, adding one if the site has none yet. */
async function firstGlobalPlan(page: Page) {
  const plansField = page.locator('.cf-field.cf-complex', {
    has: page.locator('.cf-field__label', { hasText: /^Membership Plans$/ }),
  });
  await expect(plansField.locator('.cf-complex__inserter-button')).toBeVisible();
  const plans = plansField.locator('.cf-complex__group');
  if ((await plans.count()) === 0) {
    await plansField.locator('.cf-complex__inserter-button').click();
  }
  return plans.first();
}

test.describe('With income guidance switched on', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('each global plan can be given a weekly and a monthly income band', async ({ page }) => {
    await openSettingsTab(page, 'Membership Plans');
    const plan = await firstGlobalPlan(page);

    await expect(field(plan, 'Weekly income').locator('input')).toBeVisible();
    await expect(field(plan, 'Monthly income').locator('input')).toBeVisible();
  });

  test('the Copy tab has an editable heading, with a default, and an optional note', async ({ page }) => {
    await openSettingsTab(page, 'Copy');
    const container = page.locator('.cf-container');

    await expect(field(container, 'Income guidance heading').locator('input')).toHaveValue(DEFAULT_HEADING);
    await expect(field(container, 'Income guidance note')).toBeVisible();
  });

  test('a block\'s own plans can be given income bands too', async ({ page }) => {
    await openNewPageWithJoinBlock(page, 'E2E Income Guidance Fields');
    const plansField = field(page.locator('[data-type="carbon-fields/ck-join-form"]'), 'Custom Membership Plans');
    await plansField.locator('.cf-complex__inserter-button').click();
    const plan = plansField.locator('.cf-complex__group').first();

    await expect(field(plan, 'Weekly income').locator('input')).toBeVisible();
    await expect(field(plan, 'Monthly income').locator('input')).toBeVisible();
  });
});

test.describe('With income guidance switched off', () => {
  test.beforeAll(() => setIncomeGuidance(false));
  test.afterAll(() => setIncomeGuidance(true));

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('plans have no income band fields', async ({ page }) => {
    await openSettingsTab(page, 'Membership Plans');
    const plan = await firstGlobalPlan(page);

    await expect(field(plan, 'Name').locator('input')).toBeVisible();
    await expect(field(plan, 'Weekly income')).toHaveCount(0);
    await expect(field(plan, 'Monthly income')).toHaveCount(0);
  });

  test('the Copy tab has no income guidance settings', async ({ page }) => {
    await openSettingsTab(page, 'Copy');
    const container = page.locator('.cf-container');

    await expect(field(container, 'Organisation Name').locator('input')).toBeVisible();
    await expect(field(container, 'Income guidance heading')).toHaveCount(0);
    await expect(field(container, 'Income guidance note')).toHaveCount(0);
  });
});
