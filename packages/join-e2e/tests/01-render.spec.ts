import { test, expect } from '@playwright/test';

/**
 * Rendering
 *
 * Verifies that the join form block renders correctly: the React mount point
 * is present, the environment JSON is injected and parseable, the first step's
 * input fields are visible, and no console errors are emitted.
 */

const STANDARD_PAGE = '/e2e-standard-join/';

test.describe('1 — Rendering', () => {
  test('1.1 — form container and env script are present', async ({ page }) => {
    await page.goto(STANDARD_PAGE);

    // React mounts into .ck-join-form; the outer .ck-join-flow wrapper is
    // emitted directly by the PHP render callback.
    await expect(page.locator('.ck-join-form')).toBeVisible();

    // The #env <script type="application/json"> tag must be present and contain
    // valid JSON so the frontend can read its configuration.
    const envScript = page.locator('script#env');
    await expect(envScript).toBeAttached();

    const envText = await envScript.innerHTML();
    expect(() => JSON.parse(envText)).not.toThrow();

    const env = JSON.parse(envText);
    expect(env).toHaveProperty('WP_REST_API');
    expect(env).toHaveProperty('MEMBERSHIP_PLANS');
    expect(Array.isArray(env.MEMBERSHIP_PLANS)).toBe(true);
    expect(env.MEMBERSHIP_PLANS.length).toBeGreaterThan(0);
  });

  test('1.2 — first name input is visible and no console errors', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    await page.goto(STANDARD_PAGE);

    // Wait for React to mount and render the details form.
    await expect(page.locator('input#firstName')).toBeVisible();

    expect(
      consoleErrors.filter((e) => !e.includes('favicon')),
      'Unexpected console errors: ' + consoleErrors.join(', '),
    ).toHaveLength(0);
  });

  test('1.3 — progress steps rendered with Your Details as current', async ({ page }) => {
    await page.goto(STANDARD_PAGE);

    await expect(page.locator('.progress-steps')).toBeVisible();

    // The "enter-details" stage has breadcrumb: true with label "Your Details".
    // Its <li> element should carry the --current modifier on first load.
    const currentStep = page.locator('.progress-step--current');
    await expect(currentStep).toBeVisible();
    await expect(currentStep).toContainText('Your Details');
  });
});
