import { test, expect } from '@playwright/test';
import { mockRestEndpoints, captureJoinBodyViaStripeRedirect, injectEnvOverrides, CONTINUE } from './helpers';

/**
 * Phase 7 — Supporter mode edge cases and product naming
 *
 * PR #59 test plan sections 9, 10, and 13.
 *
 * Section 9:  One-off tab disabled when STRIPE_DIRECT_DEBIT_ONLY=true.
 * Section 10: No plans configured shows a warning.
 * Section 13: Product naming — /join request body carries the correct
 *             membership value prefix for standard vs supporter mode.
 *
 * Note: Section 11 (free membership) is already covered by Phase 3
 * (03-free-membership.spec.ts). Section 12 (Mailchimp non-fatal errors)
 * is a backend concern verified by PHP unit tests; the frontend always
 * receives success:true regardless of Mailchimp state.
 * Section 6 (supporter mode monthly via Direct Debit) requires a live
 * GoCardless integration and is covered by manual testing.
 */

const SUPPORTER_PAGE = '/e2e-supporter/';
const SUPPORTER_NO_PLANS_PAGE = '/e2e-supporter-no-plans/';
const STANDARD_PAGE = '/e2e-standard-join/';

// ---------------------------------------------------------------------------
// Section 9 — One-off disabled when Direct Debit only
// ---------------------------------------------------------------------------

test.describe('7.1 — One-off tab disabled when STRIPE_DIRECT_DEBIT_ONLY=true (PR #59 section 9)', () => {
  test.beforeEach(async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_PAGE}`, {
      USE_STRIPE: true,
      STRIPE_DIRECT_DEBIT_ONLY: true,
    });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');
  });

  test('One-off button is disabled', async ({ page }) => {
    const oneOffBtn = page.locator('.btn-group button:has-text("One-off")');
    await expect(oneOffBtn).toBeVisible();
    await expect(oneOffBtn).toBeDisabled();
  });

  test('explanatory note about Direct Debit is shown', async ({ page }) => {
    await expect(
      page.locator('p:has-text("One-off donations are not available with Direct Debit")'),
    ).toBeVisible();
  });

  test('Monthly tab still works and advances the form', async ({ page }) => {
    await page.locator('.btn-group button:has-text("Monthly")').click();
    await page.locator('button:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();

    await page.waitForSelector('input#firstName');
    await expect(page.locator('.progress-step--current')).toContainText('Your Details');
  });
});

// ---------------------------------------------------------------------------
// Section 10 — No plans configured
// ---------------------------------------------------------------------------

test.describe('7.2 — No plans configured warning (PR #59 section 10)', () => {
  test.beforeEach(async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_NO_PLANS_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_NO_PLANS_PAGE);
  });

  test('"No donation amounts configured" warning is shown', async ({ page }) => {
    await expect(
      page.locator('[role="alert"]:has-text("No donation amounts configured")'),
    ).toBeVisible();
  });

  test('no tier buttons or frequency toggle are rendered', async ({ page }) => {
    await expect(page.locator('.btn-group button:has-text("Monthly")')).not.toBeVisible();
    await expect(page.locator('button:has-text("£5")')).not.toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Section 13 — Product naming
// ---------------------------------------------------------------------------

test.describe('7.3 — Product naming: standard join (PR #59 section 13)', () => {
  test('/join body membership does not contain "Donation:" prefix for standard join', async ({ page }) => {
    await injectEnvOverrides(page, `**${STANDARD_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(STANDARD_PAGE);
    await page.waitForSelector('input#firstName');

    // Advance through details -> plan.
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');
    await page.locator('label.radio-panel').first().click();
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, STANDARD_PAGE);

    // Standard join: membership value is the plan ID, not prefixed with "Donation:".
    // The backend uses "Membership: <plan label>" as the product name.
    const membership = String(joinBody.membership ?? '');
    expect(membership).not.toMatch(/^Donation:/);
    expect(membership.length).toBeGreaterThan(0);
  });
});

test.describe('7.4 — Product naming: supporter mode monthly (PR #59 section 13)', () => {
  test('/join body signals a recurring donation (recurDonation=true, donationAmount=0)', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_PAGE}`, {
      USE_STRIPE: true,
      STRIPE_DIRECT_DEBIT_ONLY: false,
    });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    // Monthly is default — select a tier and advance.
    await page.locator('button:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_PAGE);

    // Monthly supporter: the plan price IS the donation — donationAmount=0 signals
    // to the backend to use the plan's price directly (no separate donation line).
    // The backend will name the Stripe product "Donation: <tier label>".
    expect(joinBody.recurDonation).toBe(true);
    expect(Number(joinBody.donationAmount)).toBe(0);
  });
});

test.describe('7.5 — Product naming: supporter mode one-off (PR #59 section 13)', () => {
  test('/join body signals a one-off donation (recurDonation=false, donationAmount>0)', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_PAGE}`, {
      USE_STRIPE: true,
      STRIPE_DIRECT_DEBIT_ONLY: false,
    });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('button:has-text("£10")').click();
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, SUPPORTER_PAGE);

    // One-off: recurDonation=false signals no subscription; donationAmount>0 is
    // used to create a one-time PaymentIntent. The backend uses the
    // "Supporter Donation" product (no subscription created).
    expect(joinBody.recurDonation).toBe(false);
    expect(Number(joinBody.donationAmount)).toBeGreaterThan(0);
  });
});
