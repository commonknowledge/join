import { test, expect } from '@playwright/test';
import { CONTINUE, mockRestEndpoints } from './helpers';

/**
 * Multi-currency plan selection
 *
 * When two plans share the same label but have different currencies the
 * frontend groups them under a single radio panel and renders a <select>
 * dropdown so the user can pick a currency.
 *
 * These tests verify that both currency options are present, that switching
 * between them updates the displayed price, and that the form can advance
 * after either option is chosen.
 *
 * The test page (e2e-multi-currency-join) is seeded by setup.php with:
 *   - "Standard" GBP £5/month  (id: standard-gbp)
 *   - "Standard" EUR €7/month  (id: standard-eur)
 */

const MULTI_CURRENCY_PAGE = '/e2e-multi-currency-join/';

async function advanceToPlanStep(page: import('@playwright/test').Page) {
  await mockRestEndpoints(page);
  await page.goto(MULTI_CURRENCY_PAGE);
  await page.waitForSelector('input#firstName');

  // Test data auto-fills the details form; click Continue to advance.
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
}

test.describe('Multi-currency plan selection', () => {
  test('currency select is rendered with GBP and EUR options', async ({ page }) => {
    await advanceToPlanStep(page);

    const currencySelect = page.locator('select.form-control');
    await expect(currencySelect).toBeVisible();

    await expect(currencySelect.locator('option[value="standard-gbp"]')).toHaveText('GBP, monthly');
    await expect(currencySelect.locator('option[value="standard-eur"]')).toHaveText('EUR, monthly');
  });

  test('GBP option is selected by default and shows £5', async ({ page }) => {
    await advanceToPlanStep(page);

    const currencySelect = page.locator('select.form-control');
    await expect(currencySelect).toHaveValue('standard-gbp');

    const panel = page.locator('label.radio-panel').first();
    await expect(panel).toContainText('£5');
  });

  test('switching to EUR updates the displayed price to €7', async ({ page }) => {
    await advanceToPlanStep(page);

    await page.locator('select.form-control').selectOption('standard-eur');

    const panel = page.locator('label.radio-panel').first();
    await expect(panel).toContainText('€7');
  });

  test('switching back to GBP restores £5', async ({ page }) => {
    await advanceToPlanStep(page);

    await page.locator('select.form-control').selectOption('standard-eur');
    await page.locator('select.form-control').selectOption('standard-gbp');

    const panel = page.locator('label.radio-panel').first();
    await expect(panel).toContainText('£5');
  });

  test('can advance past the plan step with GBP selected', async ({ page }) => {
    await advanceToPlanStep(page);

    await page.locator('select.form-control').selectOption('standard-gbp');
    await page.locator(CONTINUE).click();

    await expect(page.locator('.progress-step--current')).toContainText('Payment');
  });

  test('can advance past the plan step with EUR selected', async ({ page }) => {
    await advanceToPlanStep(page);

    await page.locator('select.form-control').selectOption('standard-eur');
    await page.locator(CONTINUE).click();

    await expect(page.locator('.progress-step--current')).toContainText('Payment');
  });
});
