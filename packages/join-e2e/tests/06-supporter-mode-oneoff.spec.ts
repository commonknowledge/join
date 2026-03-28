import { test, expect } from '@playwright/test';
import { mockRestEndpoints, captureJoinBodyViaStripeRedirect, injectEnvOverrides, CONTINUE } from './helpers';

/**
 * Supporter mode, one-off donations
 *
 * Tests the one-off donation path within Supporter Mode. When Stripe card
 * payments are available (USE_STRIPE=true, STRIPE_DIRECT_DEBIT_ONLY=false),
 * the One-off tab is enabled. Selecting it changes the CTA to "Donate £X now"
 * (no "/month" suffix) and sets paymentMethod to creditCard.
 *
 * Because Stripe does not create a subscription for one-off donations, the
 * proxy assertion used here is the /join request body:
 *   - recurDonation: false  — the frontend signals a one-off intent
 *   - donationAmount: N     — the PaymentIntent amount (not 0)
 * The backend routes this through createPaymentIntent rather than a
 * subscription, which is the equivalent server-side check.
 *
 * Config: DONATION_SUPPORTER_MODE=true, USE_STRIPE=true, STRIPE_DIRECT_DEBIT_ONLY=false
 * (all injected via env override since these are global plugin settings).
 */

const SUPPORTER_PAGE = '/e2e-supporter/';
const SUPPORTER_CUSTOM_PAGE = '/e2e-supporter-custom/';

async function loadSupporterPage(page: import('@playwright/test').Page, url = SUPPORTER_PAGE) {
  await injectEnvOverrides(page, `**${url}`, {
    USE_STRIPE: true,
    STRIPE_DIRECT_DEBIT_ONLY: false,
  });
  await mockRestEndpoints(page);
  await page.goto(url);
  await page.waitForSelector('h2:has-text("Support us")');
}

test.describe('6.1 — One-off tab is enabled when USE_STRIPE=true, STRIPE_DIRECT_DEBIT_ONLY=false', () => {
  test('One-off button is not disabled', async ({ page }) => {
    await loadSupporterPage(page);
    const oneOffBtn = page.locator('.btn-group button:has-text("One-off")');
    await expect(oneOffBtn).toBeVisible();
    await expect(oneOffBtn).toBeEnabled();
  });
});

test.describe('6.2 — Selecting One-off updates CTA', () => {
  test('clicking One-off changes the CTA to "Donate £X now"', async ({ page }) => {
    await loadSupporterPage(page);

    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('button[type="button"]:has-text("£5")').click();

    const cta = page.locator('button[type="submit"]');
    await expect(cta).toContainText('Donate £5 now');
  });

  test('CTA does not contain "monthly" when One-off is selected', async ({ page }) => {
    await loadSupporterPage(page);

    await page.locator('.btn-group button:has-text("One-off")').click();

    const cta = page.locator('button[type="submit"]');
    await expect(cta).not.toContainText('month');
  });
});

test.describe('6.3 — /join body for one-off supporter donation', () => {
  test('recurDonation=false and donationAmount>0 in /join body for one-off', async ({ page }) => {
    await loadSupporterPage(page);

    // Switch to one-off and select a tier.
    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('button[type="button"]:has-text("£10")').click();
    await page.locator('button[type="submit"]').click();

    // Advance through details.
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_PAGE);

    expect(joinBody.recurDonation).toBe(false);
    expect(Number(joinBody.donationAmount)).toBeGreaterThan(0);
  });

  test('paymentMethod is creditCard for one-off (directDebit override cleared)', async ({ page }) => {
    await loadSupporterPage(page);

    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('button[type="button"]:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_PAGE);

    // The donation page forces paymentMethod: "creditCard" for one-off.
    expect(joinBody.paymentMethod).toBe('creditCard');
  });
});

test.describe('6.4 — One-off custom amount', () => {
  test('custom amount on one-off updates CTA to "Donate £X now"', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_CUSTOM_PAGE}`, {
      USE_STRIPE: true,
      STRIPE_DIRECT_DEBIT_ONLY: false,
    });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_CUSTOM_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('input[type="number"]').fill('7');

    const cta = page.locator('button[type="submit"]');
    await expect(cta).toContainText('Donate £7 now');
  });

  test('custom amount is sent as donationAmount in /join body for one-off', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_CUSTOM_PAGE}`, {
      USE_STRIPE: true,
      STRIPE_DIRECT_DEBIT_ONLY: false,
    });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_CUSTOM_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('input[type="number"]').fill('7');
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_CUSTOM_PAGE);

    expect(Number(joinBody.donationAmount)).toBe(7);
    expect(joinBody.recurDonation).toBe(false);
  });
});
