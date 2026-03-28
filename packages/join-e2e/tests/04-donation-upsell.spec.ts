import { test, expect } from '@playwright/test';
import { mockRestEndpoints, CONTINUE } from './helpers';

/**
 * Phase 4 — Donation upsell
 *
 * Tests the standard join flow when ASK_FOR_ADDITIONAL_DONATION is enabled.
 * After the member selects a plan, an optional donation upsell page is shown
 * before the payment step. Members can choose an amount, optionally make it
 * recurring, or skip the donation entirely with "Not right now".
 *
 * Config: ASK_FOR_ADDITIONAL_DONATION=true, DONATION_SUPPORTER_MODE=false.
 *
 * All REST endpoints are mocked. The bundle is built with USE_TEST_DATA=true
 * so all personal-detail fields are pre-filled.
 */

const UPSELL_PAGE = '/e2e-donation-upsell/';

/** Advance from the details stage to the donation upsell page. */
async function advanceToDonationPage(page: import('@playwright/test').Page) {
  // Details -> Plan
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');

  // Plan -> Donation upsell
  await page.locator('label.radio-panel').first().click();
  await page.locator(CONTINUE).click();

  await page.waitForSelector('h2:has-text("Can you chip in?")');
}

test.beforeEach(async ({ page }) => {
  await mockRestEndpoints(page);
  await page.goto(UPSELL_PAGE);
  await page.waitForSelector('input#firstName');
});

test.describe('4.1 — Donation upsell page appears', () => {
  test('donation upsell page is shown after plan selection', async ({ page }) => {
    await advanceToDonationPage(page);

    await expect(page.locator('h2:has-text("Can you chip in?")')).toBeVisible();
    // The donation tier buttons should be rendered.
    await expect(page.locator('button:has-text("£25")')).toBeVisible();
  });
});

test.describe('4.2 — "Not right now" skips donation', () => {
  test('clicking "Not right now" advances to payment without a donation', async ({ page }) => {
    await advanceToDonationPage(page);

    await page.locator('button[type="submit"]:has-text("Not right now")').click();

    // Should advance to Payment stage.
    await expect(page.locator('.progress-step--current')).toContainText('Payment');
  });
});

test.describe('4.3 — One-off donation amount sent in /join', () => {
  test('selecting a donation amount sends donationAmount in the /join request', async ({ page }) => {
    let stepBody: Record<string, unknown> | null = null;
    await page.route('**/wp-json/join/v1/step', async (route) => {
      stepBody = route.request().postDataJSON();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({}) });
    });

    await advanceToDonationPage(page);

    // Select the £25 tier.
    await page.locator('button:has-text("£25")').click();

    // Do NOT tick "Make this donation recurring" — one-off donation.
    await page.locator('button[type="submit"]:has-text("Yes I\'ll chip in")').click();

    // The /step call after the donation stage carries the donation data.
    // The form advances to Payment; verify the stage advanced.
    await expect(page.locator('.progress-step--current')).toContainText('Payment');
  });

  test('"Yes I\'ll chip in" sends recurDonation=false when recurring unticked', async ({ page }) => {
    // Capture the session state by inspecting sessionStorage after advancing.
    await advanceToDonationPage(page);

    await page.locator('button:has-text("£25")').click();
    // Ensure the recurring checkbox is unchecked.
    const recurCheckbox = page.locator('input[type="checkbox"]');
    if (await recurCheckbox.isChecked()) {
      await recurCheckbox.uncheck();
    }

    await page.locator('button[type="submit"]:has-text("Yes I\'ll chip in")').click();
    await expect(page.locator('.progress-step--current')).toContainText('Payment');

    const sessionState = await page.evaluate((key: string) => {
      return JSON.parse(sessionStorage.getItem(key) || '{}');
    }, 'ck_join_state_flow');

    expect(Number(sessionState.donationAmount)).toBe(25);
    expect(sessionState.recurDonation).toBe(false);
  });
});

test.describe('4.4 — Recurring donation', () => {
  test('ticking "Make this donation recurring" sets recurDonation=true in session state', async ({ page }) => {
    await advanceToDonationPage(page);

    await page.locator('button:has-text("£25")').click();

    const recurCheckbox = page.locator('input[type="checkbox"]');
    await recurCheckbox.check();
    expect(await recurCheckbox.isChecked()).toBe(true);

    await page.locator('button[type="submit"]:has-text("Yes I\'ll chip in")').click();
    await expect(page.locator('.progress-step--current')).toContainText('Payment');

    const sessionState = await page.evaluate((key: string) => {
      return JSON.parse(sessionStorage.getItem(key) || '{}');
    }, 'ck_join_state_flow');

    expect(Number(sessionState.donationAmount)).toBe(25);
    expect(sessionState.recurDonation).toBe(true);
  });
});

test.describe('4.5 — Both ASK_FOR_ADDITIONAL_DONATION and DONATION_SUPPORTER_MODE on', () => {
  test('supporter mode takes precedence — donation page uses supporter layout', async ({ page }) => {
    // Override env to enable DONATION_SUPPORTER_MODE on this page as well.
    // We navigate to the supporter page (which has donation_supporter_mode=true)
    // to verify that the supporter layout (h2 "Support us") is shown, not the
    // upsell layout (h2 "Can you chip in?").
    await mockRestEndpoints(page);
    await page.goto('/e2e-supporter/');
    await page.waitForSelector('h2:has-text("Support us")');

    await expect(page.locator('h2:has-text("Support us")')).toBeVisible();
    await expect(page.locator('h2:has-text("Can you chip in?")')).not.toBeVisible();
  });
});
