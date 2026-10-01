import { test, expect, Page } from '@playwright/test';
import {
  choosePage,
  field,
  fillPlan,
  loginAsAdmin,
  mockRestEndpoints,
  openNewPageWithJoinBlock,
  wpCli,
  CONTINUE,
  SAVED_STATE_KEY,
} from './helpers';

/**
 * Income guidance on the join form
 *
 * The income guidance page (seeded by setup.php) has three tiers with income
 * bands and a custom-amount "Other" tier with none. The e2e site switches
 * income guidance on (see mu-plugins/).
 */

const INCOME_GUIDANCE_PAGE = '/e2e-income-guidance-join/';
const DEFAULT_HEADING = 'Below are some recommended monthly dues amounts, based on income';
const NOTE = "Can't afford dues? Email dues@example.org.";

const table = (page: Page) => page.getByRole('table', { name: DEFAULT_HEADING });
const rowButton = (page: Page, fee: string) => table(page).getByRole('button', { name: `Choose ${fee}` });

async function goToPlanStep(page: Page, url = INCOME_GUIDANCE_PAGE): Promise<void> {
  await mockRestEndpoints(page);
  await page.goto(url);
  await page.waitForSelector('input#firstName');
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
}

test.describe('The income guidance table', () => {
  test.beforeEach(async ({ page }) => {
    await goToPlanStep(page);
  });

  test('shows each banded tier against its weekly and monthly income, with the heading and note', async ({ page }) => {
    await expect(page.getByText(DEFAULT_HEADING)).toBeVisible();

    const rows = table(page).locator('tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0).locator('td')).toHaveText(['More than £800', 'More than £3,200', '£25 monthly']);
    await expect(rows.nth(1).locator('td')).toHaveText(['£400 to £800', '£1,600 to £3,200', '£12 monthly']);
    await expect(rows.nth(2).locator('td')).toHaveText(['Less than £400', 'Less than £1,600', '£5 monthly']);

    await expect(page.getByText(NOTE)).toBeVisible();
  });

  test('choosing a row selects its tier, and that tier is the one taken to payment', async ({ page }) => {
    await rowButton(page, '£12 monthly').click();

    await expect(page.locator('[id="membership-Middle"]')).toBeChecked();
    await expect(rowButton(page, '£12 monthly')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('button[type="submit"]')).toHaveText('Continue and pay £12 monthly');

    await page.locator(CONTINUE).click();
    await expect(page.locator('.progress-step--current')).toContainText('Payment');
    const saved = await page.evaluate((key) => JSON.parse(sessionStorage.getItem(key) || '{}'), SAVED_STATE_KEY);
    expect(saved.membership).toBe('middle');
  });

  test('clicking anywhere on a row selects its tier', async ({ page }) => {
    await table(page).locator('tbody tr', { hasText: 'Less than £400' }).locator('td').first().click();

    await expect(page.locator('[id="membership-Lower"]')).toBeChecked();
  });

  test('choosing a tier directly marks its row', async ({ page }) => {
    await page.locator('label.radio-panel', { has: page.locator('[id="membership-Lower"]') }).click();

    await expect(rowButton(page, '£5 monthly')).toHaveAttribute('aria-pressed', 'true');
    await expect(rowButton(page, '£25 monthly')).toHaveAttribute('aria-pressed', 'false');
  });

  test('a row can be chosen with the keyboard', async ({ page }) => {
    await rowButton(page, '£25 monthly').focus();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');

    await expect(page.locator('[id="membership-Middle"]')).toBeChecked();
  });
});

test.describe('On a phone', () => {
  test.use({ viewport: { width: 375, height: 800 } });

  test('each band is shown as its own card, with the column names', async ({ page }) => {
    await goToPlanStep(page);

    await expect(table(page).locator('thead')).toBeHidden();
    const card = table(page).locator('tbody tr').first();
    await expect(card).toBeVisible();
    const label = await card.locator('td').first().evaluate((td) => getComputedStyle(td, '::before').content);
    expect(label).toBe('"Weekly income: "');
  });
});

test.describe('With income guidance switched off', () => {
  test.beforeAll(() => wpCli("wp option update ck_e2e_income_guidance_enabled ''"));
  test.afterAll(() => wpCli("wp option update ck_e2e_income_guidance_enabled '1'"));

  test('the plan step shows the tiers without the table', async ({ page }) => {
    await goToPlanStep(page);

    await expect(page.locator('[id="membership-Higher"]')).toBeAttached();
    await expect(page.getByRole('table')).toHaveCount(0);
    await expect(page.getByText(DEFAULT_HEADING)).toHaveCount(0);
  });
});

test.describe('Bands entered in the block editor', () => {
  test('appear in the table on the published page', async ({ page }) => {
    await loginAsAdmin(page);
    await openNewPageWithJoinBlock(page, 'E2E Income Guidance Editor');

    const block = page.locator('[data-type="carbon-fields/ck-join-form"]');
    await choosePage(field(block, 'Page to redirect to after joining'), 'E2E Redirect Target');

    const plansField = field(block, 'Custom Membership Plans');
    const plans = plansField.locator('.cf-complex__group');
    await plansField.locator('.cf-complex__inserter-button').click();
    await fillPlan(plans.last(), 'Waged', '10');
    await field(plans.last(), 'Weekly income').locator('input').fill('More than £300');
    await plansField.getByRole('button', { name: 'Add Entry' }).click();
    await fillPlan(plans.last(), 'Unwaged', '2');
    await field(plans.last(), 'Weekly income').locator('input').fill('Less than £300');

    const pageId = await page.evaluate(async () => {
      const wp = (window as any).wp;
      await wp.data.dispatch('core/editor').editPost({ status: 'publish' });
      await wp.data.dispatch('core/editor').savePost();
      return wp.data.select('core/editor').getCurrentPostId();
    });
    const link = await page.evaluate(() => (window as any).wp.data.select('core/editor').getPermalink());

    try {
      await goToPlanStep(page, link);

      const rows = table(page).locator('tbody tr');
      await expect(rows).toHaveCount(2);
      await expect(rows.nth(0).locator('td')).toHaveText(['More than £300', '£10 monthly']);
      await expect(rows.nth(1).locator('td')).toHaveText(['Less than £300', '£2 monthly']);

      await rowButton(page, '£2 monthly').click();
      await expect(page.locator('[id="membership-Unwaged"]')).toBeChecked();
    } finally {
      wpCli(`wp post delete ${pageId} --force`);
    }
  });
});
