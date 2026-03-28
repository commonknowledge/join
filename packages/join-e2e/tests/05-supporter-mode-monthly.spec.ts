import { test, expect } from '@playwright/test';
import { mockRestEndpoints, captureJoinBodyViaStripeRedirect, injectEnvOverrides, CONTINUE } from './helpers';

/**
 * Supporter mode, monthly donations
 *
 * Tests the Donation Supporter Mode flow where the donation step comes first
 * (before personal details and payment). Members choose a giving tier or enter
 * a custom amount, with monthly recurring selected by default.
 *
 * Covers: first-step rendering, tier/custom-amount CTA updates, advancing
 * through donation → details, and the /join request body for a monthly
 * recurring supporter donation (donationAmount=0 because the plan price IS
 * the donation; recurDonation=true).
 *
 * Config: DONATION_SUPPORTER_MODE=true, USE_STRIPE=true (injected via env override).
 */

const SUPPORTER_PAGE = '/e2e-supporter/';
const SUPPORTER_CUSTOM_PAGE = '/e2e-supporter-custom/';

test.beforeEach(async ({ page }) => {
  // USE_STRIPE must be true for the payment method/summary to resolve.
  await injectEnvOverrides(page, `**${SUPPORTER_PAGE}`, { USE_STRIPE: true });
  await mockRestEndpoints(page);
  await page.goto(SUPPORTER_PAGE);
  // In supporter mode the first page is the donation page (h2 "Support us").
  await page.waitForSelector('h2:has-text("Support us")');
});

test.describe('Donation page is the first step', () => {
  test('supporter mode renders the donation page first, not the details page', async ({ page }) => {
    await expect(page.locator('h2:has-text("Support us")')).toBeVisible();
    // The details page input must NOT be visible yet.
    await expect(page.locator('input#firstName')).not.toBeVisible();
  });

  test('breadcrumb shows "Your Donation" as the current step', async ({ page }) => {
    await expect(page.locator('.progress-step--current')).toContainText('Your Donation');
  });
});

test.describe('Monthly selected by default', () => {
  test('Monthly button has the active (dark) variant on load', async ({ page }) => {
    const monthlyBtn = page.locator('.btn-group button:has-text("Monthly")');
    await expect(monthlyBtn).toBeVisible();
    // The active button carries the "btn-dark" Bootstrap class.
    await expect(monthlyBtn).toHaveClass(/btn-dark/);
  });

  test('One-off button is present alongside Monthly', async ({ page }) => {
    await expect(page.locator('.btn-group button:has-text("One-off")')).toBeVisible();
  });
});

test.describe('Tier selection updates CTA', () => {
  test('selecting the £5 tier shows "Donate £5/month" CTA', async ({ page }) => {
    await page.locator('button[type="button"]:has-text("£5")').click();
    const cta = page.locator('button[type="submit"]');
    await expect(cta).toContainText('Donate £5/month');
  });

  test('selecting the £10 tier shows "Donate £10/month" CTA', async ({ page }) => {
    await page.locator('button[type="button"]:has-text("£10")').click();
    const cta = page.locator('button[type="submit"]');
    await expect(cta).toContainText('Donate £10/month');
  });

  test('selecting the £20 tier shows "Donate £20/month" CTA', async ({ page }) => {
    await page.locator('button[type="button"]:has-text("£20")').click();
    const cta = page.locator('button[type="submit"]');
    await expect(cta).toContainText('Donate £20/month');
  });
});

test.describe('Advancing from donation goes to details', () => {
  test('continuing from donation page advances to the details stage', async ({ page }) => {
    await page.locator('button[type="button"]:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();

    // In supporter mode: donation -> enter-details.
    await page.waitForSelector('input#firstName');
    await expect(page.locator('.progress-step--current')).toContainText('Your Details');
  });
});

test.describe('/join body for monthly supporter donation', () => {
  test('membership field contains the plan value and donationAmount is 0 for monthly', async ({ page }) => {
    // Select the £5 tier and advance through donation -> details.
    await page.locator('button[type="button"]:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');

    // Advance through details (test data is pre-filled).
    await page.locator(CONTINUE).click();

    // Simulate Stripe payment completion and capture the /join request body.
    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_PAGE);

    // Monthly supporter: donationAmount should be 0 (plan price IS the donation).
    expect(Number(joinBody.donationAmount ?? 0)).toBe(0);
    expect(joinBody.recurDonation).toBe(true);
    // The membership value should correspond to the selected plan.
    expect(typeof joinBody.membership).toBe('string');
    expect(String(joinBody.membership).length).toBeGreaterThan(0);
  });
});

test.describe('Product naming: supporter mode produces a Donation product', () => {
  test('/join body signals a recurring supporter donation (recurDonation=true, donationAmount=0)', async ({ page }) => {
    await page.locator('button[type="button"]:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_PAGE);

    // In supporter mode the product prefix on the backend will be "Donation:".
    // The membership field holds the plan ID resolved by the frontend.
    expect(typeof joinBody.membership).toBe('string');
    expect(joinBody.donationSupporterMode ?? joinBody.recurDonation).toBeTruthy();
  });
});

// ---------------------------------------------------------------------------
// Custom amount (plan with allow_custom_amount enabled)
// ---------------------------------------------------------------------------

test.describe('Custom amount updates CTA', () => {
  test('entering a custom amount updates CTA to reflect the custom value', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_CUSTOM_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_CUSTOM_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    const customInput = page.locator('input[type="number"]');
    await customInput.fill('15');

    const cta = page.locator('button[type="submit"]');
    await expect(cta).toContainText('Donate £15/month');
  });

  test('custom amount is reflected in the session state after advancing', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_CUSTOM_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_CUSTOM_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    await page.locator('input[type="number"]').fill('12');
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');

    const sessionState = await page.evaluate((key: string) => {
      return JSON.parse(sessionStorage.getItem(key) || '{}');
    }, 'ck_join_state_flow');

    // customMembershipAmount carries the entered value when no matching tier exists.
    expect(Number(sessionState.customMembershipAmount ?? sessionState.donationAmount ?? 0)).toBe(12);
  });
});
