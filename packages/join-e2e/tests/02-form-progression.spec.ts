import { test, expect } from '@playwright/test';

/**
 * Form progression
 *
 * All tests start on the standard test page.  Because the bundle is built with
 * REACT_APP_USE_TEST_DATA=true, getTestDataIfEnabled() pre-fills every field
 * with deterministic values (firstName="Test", lastName="Person",
 * email="someone@example.com", addressPostcode="OX14PE", etc.).
 *
 * The /step and /join endpoints are intercepted and fulfilled with a 200
 * response so that network errors never block progression.
 */

const STANDARD_PAGE = '/e2e-standard-join/';
const CONTINUE = 'button[type="submit"]:has-text("Continue")';

/** Intercepts both REST endpoints that the form calls during navigation. */
async function mockRestEndpoints(page: import('@playwright/test').Page) {
  await page.route('**/wp-json/join/v1/step', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({}),
    });
  });
  await page.route('**/wp-json/join/v1/join', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true }),
    });
  });
}

test.beforeEach(async ({ page }) => {
  await mockRestEndpoints(page);
  await page.goto(STANDARD_PAGE);
  // Wait for React to fully mount before each test.
  await page.waitForSelector('input#firstName');
});

test.describe('Pre-filled values', () => {
  test('form is pre-filled with test data', async ({ page }) => {
    await expect(page.locator('input#firstName')).toHaveValue('Test');
    await expect(page.locator('input#lastName')).toHaveValue('Person');
    await expect(page.locator('input#email')).toHaveValue('someone@example.com');
    await expect(page.locator('input#addressPostcode')).toHaveValue('OX14PE');
  });
});

test.describe('Validation', () => {
  /**
   * Helper: clear a field, click Continue, assert at least one .invalid-feedback
   * is shown, and the stage has NOT advanced past details.
   */
  async function assertRequiredValidation(
    page: import('@playwright/test').Page,
    fieldSelector: string,
    restoreValue: string,
  ) {
    await page.locator(fieldSelector).fill('');
    await page.locator(CONTINUE).click();
    // React Hook Form adds the `is-invalid` CSS class to inputs that fail
    // validation.  The <input> (or <select>) itself is always a visible
    // element, so this check does not depend on Bootstrap's CSS showing the
    // .invalid-feedback sibling (which requires display:block via the
    // .is-invalid ~ .invalid-feedback rule and can be tricky to assert).
    await expect(page.locator(`${fieldSelector}.is-invalid`)).toBeVisible();
    // Progress step must still show "Your Details" as current.
    await expect(page.locator('.progress-step--current')).toContainText('Your Details');
    // Restore the field.
    await page.locator(fieldSelector).fill(restoreValue);
  }

  test('first name required', async ({ page }) => {
    await assertRequiredValidation(page, 'input#firstName', 'Test');
  });

  test('last name required', async ({ page }) => {
    await assertRequiredValidation(page, 'input#lastName', 'Person');
  });

  test('email required', async ({ page }) => {
    await assertRequiredValidation(page, 'input#email', 'someone@example.com');
  });

  test('email format validated', async ({ page }) => {
    await page.locator('input#email').fill('notanemail');
    await page.locator(CONTINUE).click();
    await expect(page.locator('input#email.is-invalid')).toBeVisible();
    await expect(page.locator('.progress-step--current')).toContainText('Your Details');
    await page.locator('input#email').fill('someone@example.com');
  });

  test('phone number required (requirePhoneNumber=true)', async ({ page }) => {
    await assertRequiredValidation(page, 'input#phoneNumber', '02036919400');
  });

  test('address line 1 required (requireAddress=true)', async ({ page }) => {
    await assertRequiredValidation(page, 'input#addressLine1', '54 Abingdon Road');
  });

  test('address city required', async ({ page }) => {
    await assertRequiredValidation(page, 'input#addressCity', 'Oxford');
  });

  test('postcode required', async ({ page }) => {
    await assertRequiredValidation(page, 'input#addressPostcode', 'OX14PE');
  });

  test('country field is present and pre-filled to GB', async ({ page }) => {
    // The country select has no empty option so a truly empty value cannot be
    // triggered.  Assert instead that the field is present and defaults to GB
    // (confirming it participates in the form).
    const select = page.locator('select#addressCountry');
    await expect(select).toBeVisible();
    await expect(select).toHaveValue('GB');
  });
});

test.describe('Advance details to plan', () => {
  test('clicking Continue advances to the plan stage', async ({ page }) => {
    await page.locator(CONTINUE).click();

    // The plan breadcrumb should now be the current step.
    await expect(page.locator('.progress-step--current')).toContainText('Your Membership');
    // The plan radio group should be visible.
    await expect(page.locator('[role="radiogroup"]')).toBeVisible();
  });
});

test.describe('Select plan and advance', () => {
  test('selecting the Standard plan and clicking Continue advances the stage', async ({ page }) => {
    // Advance from details to plan.
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');

    // The "Standard" plan radio panel is already selected by default (the
    // default membership value matches the first plan).  Click it explicitly
    // to confirm selection, then continue.
    await page.locator('label.radio-panel').first().click();
    await page.locator(CONTINUE).click();

    // After plan the flow skips donation (ASK_FOR_ADDITIONAL_DONATION=false)
    // and payment-method (single provider auto-skip), landing on payment-details.
    // With no real payment provider the page shows an error paragraph.  Either
    // way the "Payment" breadcrumb should now be current.
    await expect(page.locator('.progress-step--current')).toContainText('Payment');
  });
});

test.describe('/step request body', () => {
  test('continuing from details POSTs the expected fields to /step', async ({ page }) => {
    // Override the /step route so we can inspect the request body.
    let stepBody: Record<string, unknown> | null = null;
    await page.route('**/wp-json/join/v1/step', async (route) => {
      stepBody = route.request().postDataJSON();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({}),
      });
    });

    await page.locator(CONTINUE).click();

    // Wait for the stage to advance (confirms the request was sent and returned).
    await expect(page.locator('.progress-step--current')).toContainText('Your Membership');

    expect(stepBody).not.toBeNull();
    expect(stepBody!.stage).toBe('enter-details');
    expect(stepBody!.email).toBe('someone@example.com');
    expect(stepBody!.firstName).toBe('Test');
    expect(stepBody!.lastName).toBe('Person');
    expect(stepBody!.addressPostcode).toBe('OX14PE');
    expect(stepBody!.addressCity).toBe('Oxford');
  });
});
