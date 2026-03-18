import { test, expect } from '@playwright/test';

/**
 * Phase 3 — Free membership happy path
 *
 * Uses the free-membership test page (amount="0").  Because the plan amount is
 * zero and no additional donation is requested, shouldSkipPayment fires in
 * app.tsx and the flow jumps directly from plan to confirm, bypassing all
 * payment stages.
 */

const FREE_PAGE = '/e2e-free-join/';
const CONTINUE = 'button[type="submit"]:has-text("Continue")';

test.beforeEach(async ({ page }) => {
  // Intercept the step endpoint so navigation is never blocked by the server.
  await page.route('**/wp-json/join/v1/step', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({}),
    });
  });

  await page.goto(FREE_PAGE);
  await page.waitForSelector('input#firstName');
});

test.describe('3.1 — Confirm stage reached without payment', () => {
  test('continuing through Details and Plan skips payment and shows confirm stage', async ({
    page,
  }) => {
    // Intercept the /join endpoint so the confirm page does not error on submit.
    await page.route('**/wp-json/join/v1/join', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    // Details -> Plan.
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');

    // Plan -> Confirm (payment skipped because amount=0).
    await page.locator(CONTINUE).click();

    // The confirm page heading must be visible; no payment step should appear.
    await expect(page.locator('h2:has-text("Confirm your details")')).toBeVisible();
  });
});

test.describe('3.2 — Confirm stage summary', () => {
  test('confirm stage shows member email and a Confirm button', async ({ page }) => {
    await page.route('**/wp-json/join/v1/join', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    // Navigate to confirm.
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');
    await page.locator(CONTINUE).click();
    await page.waitForSelector('h2:has-text("Confirm your details")');

    // The Summary component renders the email from the details stage.
    await expect(page.locator('text=someone@example.com')).toBeVisible();

    // The confirm button must be present and enabled.
    const confirmBtn = page.locator('button[type="submit"]:has-text("Join")');
    await expect(confirmBtn).toBeVisible();
    await expect(confirmBtn).toBeEnabled();
  });
});

test.describe('3.3 — Join request body', () => {
  test('clicking Confirm POSTs the expected fields to /join', async ({ page }) => {
    let capturedBody: Record<string, unknown> | null = null;

    await page.route('**/wp-json/join/v1/join', async (route) => {
      capturedBody = route.request().postDataJSON();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    // Navigate to confirm.
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');
    await page.locator(CONTINUE).click();
    await page.waitForSelector('button[type="submit"]:has-text("Join")');

    // Submit the confirm form.
    await page.locator('button[type="submit"]:has-text("Join")').click();

    // Wait until the route interceptor has fired.
    await expect.poll(() => capturedBody, { timeout: 5000 }).not.toBeNull();

    expect(capturedBody!.email).toBe('someone@example.com');
    expect(capturedBody!.firstName).toBe('Test');
    expect(capturedBody!.lastName).toBe('Person');
    expect(capturedBody!.addressPostcode).toBe('OX14PE');
    expect(capturedBody!.addressCity).toBe('Oxford');
    expect(capturedBody!.membership).toBe('free');

    // No donation should be present (or it should be falsy / zero).
    const donationAmount = capturedBody!.donationAmount;
    expect(!donationAmount || Number(donationAmount) === 0).toBe(true);
  });
});
