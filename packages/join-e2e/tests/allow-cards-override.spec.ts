import { test, expect } from '@playwright/test';
import { mockRestEndpoints, captureJoinBodyViaStripeRedirect, injectEnvOverrides, CONTINUE } from './helpers';

/**
 * allow_cards_override — per-block card payment override
 *
 * When the global "Direct Debit Only" Stripe setting is enabled
 * (STRIPE_DIRECT_DEBIT_ONLY=true), the block editor exposes an
 * "allow_cards_override" checkbox. Checking it causes the PHP render
 * callback to emit STRIPE_DIRECT_DEBIT_ONLY: false in the page's env JSON,
 * re-enabling card (Stripe Elements) payments for that specific form instance.
 *
 * The seed script (setup.php) sets STRIPE_DIRECT_DEBIT_ONLY=true globally via
 * carbon_set_theme_option and creates:
 *   - e2e-allow-cards-override-supporter: supporter mode + allow_cards_override=true
 *   - e2e-supporter: supporter mode, no override (global DD-only applies)
 *
 * Tests verify:
 *   1. The PHP env script output is correct (full-stack, no injectEnvOverrides).
 *   2. The one-off tab is enabled on the override page and disabled without it.
 *   3. The /join request body for a one-off donation on the override page is correct.
 *
 * For behavioural tests, USE_STRIPE=true is injected (a global plugin setting
 * unavailable in the test environment) but STRIPE_DIRECT_DEBIT_ONLY is NOT
 * injected — it is left to the real PHP output to supply the value, which is
 * what these tests are exercising.
 */

const OVERRIDE_PAGE = '/e2e-allow-cards-override-supporter/';
const SUPPORTER_PAGE = '/e2e-supporter/';

// ---------------------------------------------------------------------------
// PHP env script output — no injectEnvOverrides, reads raw PHP output
// ---------------------------------------------------------------------------

test.describe('PHP env script: allow_cards_override overrides STRIPE_DIRECT_DEBIT_ONLY', () => {
  test('page with allow_cards_override emits STRIPE_DIRECT_DEBIT_ONLY: false', async ({ page }) => {
    await page.goto(OVERRIDE_PAGE);
    const envJson = await page.locator('script#env').textContent();
    const env = JSON.parse(envJson || '{}');
    // PHP must have emitted false (not the global true) because allow_cards_override=true.
    expect(env.STRIPE_DIRECT_DEBIT_ONLY).toBe(false);
  });

  test('page without allow_cards_override emits STRIPE_DIRECT_DEBIT_ONLY: true (global applies)', async ({ page }) => {
    await page.goto(SUPPORTER_PAGE);
    const envJson = await page.locator('script#env').textContent();
    const env = JSON.parse(envJson || '{}');
    // No override — the global STRIPE_DIRECT_DEBIT_ONLY=true must be present.
    expect(env.STRIPE_DIRECT_DEBIT_ONLY).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// One-off tab availability — STRIPE_DIRECT_DEBIT_ONLY supplied by real PHP
// ---------------------------------------------------------------------------

test.describe('One-off tab enabled on override page, disabled without it', () => {
  test('one-off tab is enabled on page with allow_cards_override', async ({ page }) => {
    // Inject USE_STRIPE=true (global plugin setting not available in test env).
    // STRIPE_DIRECT_DEBIT_ONLY is intentionally NOT injected — it comes from PHP.
    await injectEnvOverrides(page, `**${OVERRIDE_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(OVERRIDE_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    const oneOffBtn = page.locator('.btn-group button:has-text("One-off")');
    await expect(oneOffBtn).toBeVisible();
    await expect(oneOffBtn).toBeEnabled();
  });

  test('one-off tab is disabled on page without allow_cards_override (global DD-only applies)', async ({ page }) => {
    await injectEnvOverrides(page, `**${SUPPORTER_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    const oneOffBtn = page.locator('.btn-group button:has-text("One-off")');
    await expect(oneOffBtn).toBeVisible();
    await expect(oneOffBtn).toBeDisabled();
  });
});

// ---------------------------------------------------------------------------
// /join request body for a one-off donation via the override page
// ---------------------------------------------------------------------------

test.describe('/join body for one-off donation on override page', () => {
  test('recurDonation=false and donationAmount>0 in /join body', async ({ page }) => {
    await injectEnvOverrides(page, `**${OVERRIDE_PAGE}`, { USE_STRIPE: true });
    await mockRestEndpoints(page);
    await page.goto(OVERRIDE_PAGE);
    await page.waitForSelector('h2:has-text("Support us")');

    // Select one-off, pick a tier, advance through details.
    await page.locator('.btn-group button:has-text("One-off")').click();
    await page.locator('button[type="button"]:has-text("£5")').click();
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('input#firstName');
    await page.locator(CONTINUE).click();

    const joinBody = await captureJoinBodyViaStripeRedirect(page, OVERRIDE_PAGE);

    expect(Object.keys(joinBody).length).toBeGreaterThan(0);
    expect(joinBody.recurDonation).toBe(false);
    expect(Number(joinBody.donationAmount)).toBeGreaterThan(0);
    // paymentMethod must be creditCard (the allow_cards_override unlocks card for one-off).
    expect(joinBody.paymentMethod).toBe('creditCard');
  });
});
