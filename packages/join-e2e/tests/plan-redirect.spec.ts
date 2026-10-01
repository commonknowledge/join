import { test, expect, Page } from '@playwright/test';
import { mockRestEndpoints, CONTINUE, SAVED_STATE_KEY } from './helpers';

/**
 * Membership plans that redirect instead of taking payment
 *
 * The plan-redirect page (seeded by setup.php) has one plan of each kind:
 *   Standard   — takes payment as normal
 *   Student    — redirect ticked, page chosen: goes to the redirect target
 *   Concession — redirect ticked, no page chosen: takes payment as normal
 *   Unwaged    — redirect unticked, page still set: takes payment as normal
 *   Retired    — redirect ticked, chosen page since deleted: takes payment as normal
 *
 * The plans are seeded in the shape the block editor saves them, and
 * admin-plan-redirect.spec.ts drives the editor itself, so the two cover each
 * other: if the editor's shape changes, that spec fails.
 */

const PLAN_REDIRECT_PAGE = '/e2e-plan-redirect-join/';
const SUPPORTER_REDIRECT_PAGE = '/e2e-supporter-redirect/';
const REDIRECT_TARGET = '/e2e-redirect-target/';
const REDIRECT_TARGET_COPY = 'You have been redirected here instead of paying.';

async function readEnv(page: Page): Promise<Record<string, any>> {
  return page.evaluate(() => JSON.parse(document.getElementById('env')!.textContent || '{}'));
}

async function choosePlan(page: Page, label: string): Promise<void> {
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
  // Each plan's radio is given the id "membership-<label>".
  await page.locator('label.radio-panel', { has: page.locator(`[id="membership-${label}"]`) }).click();
  await page.locator(CONTINUE).click();
}

/** Records every call to the join endpoint, which a redirect must never make. */
function recordJoinCalls(page: Page): string[] {
  const calls: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/wp-json/join/v1/join')) {
      calls.push(request.url());
    }
  });
  return calls;
}

test.describe('Plan redirect page', () => {
  test.beforeEach(async ({ page }) => {
    await mockRestEndpoints(page);
    await page.goto(PLAN_REDIRECT_PAGE);
    await page.waitForSelector('input#firstName');
  });

  test('only the plan with the box ticked and a page chosen is given a redirect URL', async ({ page }) => {
    const plans = (await readEnv(page)).MEMBERSHIP_PLANS as { value: string; redirectUrl: string | null }[];
    const redirects = Object.fromEntries(plans.map((p) => [p.value, p.redirectUrl]));

    expect(redirects.student).toMatch(new RegExp(`${REDIRECT_TARGET}$`));
    expect(redirects.standard).toBeNull();
    expect(redirects.concession).toBeNull();
    expect(redirects.unwaged).toBeNull();
    expect(redirects.retired).toBeNull();
  });

  test('choosing the redirecting plan lands on the chosen page without joining', async ({ page }) => {
    const joinCalls = recordJoinCalls(page);

    await choosePlan(page, 'Student');

    await page.waitForURL(`**${REDIRECT_TARGET}`);
    await expect(page.getByText(REDIRECT_TARGET_COPY)).toBeVisible();
    expect(joinCalls).toEqual([]);
  });

  test('the continue button only mentions paying for plans that take payment', async ({ page }) => {
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');
    const button = page.locator('button[type="submit"]');

    await page.locator('label.radio-panel', { has: page.locator('[id="membership-Student"]') }).click();
    await expect(button).toHaveText('Continue');

    await page.locator('label.radio-panel', { has: page.locator('[id="membership-Standard"]') }).click();
    await expect(button).toHaveText('Continue and pay £5 monthly');
  });

  test('after being redirected, coming back to the form starts afresh', async ({ page }) => {
    await choosePlan(page, 'Student');
    await page.waitForURL(`**${REDIRECT_TARGET}`);

    expect(await page.evaluate((key) => sessionStorage.getItem(key), SAVED_STATE_KEY)).toBeNull();

    await page.goto(PLAN_REDIRECT_PAGE);
    await expect(page.locator('.progress-step--current')).toContainText('Your Details');
    await expect(page.locator('input#firstName')).toBeVisible();
  });

  for (const label of ['Standard', 'Concession', 'Unwaged', 'Retired']) {
    test(`choosing ${label} goes on to payment as normal`, async ({ page }) => {
      await choosePlan(page, label);

      await expect(page.locator('.progress-step--current')).toContainText('Payment');
      expect(new URL(page.url()).pathname).toBe(PLAN_REDIRECT_PAGE);
    });
  }
});

test.describe('Supporter mode', () => {
  test('a redirecting tier is ignored because supporter mode has no plan step', async ({ page }) => {
    await mockRestEndpoints(page);
    await page.goto(SUPPORTER_REDIRECT_PAGE);

    const plans = (await readEnv(page)).MEMBERSHIP_PLANS as { redirectUrl: string | null }[];
    expect(plans.map((p) => p.redirectUrl)).toEqual([null]);

    // Donation -> Details, as supporter mode always does.
    await page.locator('button[type="submit"]').first().click();
    await page.waitForSelector('input#firstName');
    expect(new URL(page.url()).pathname).toBe(SUPPORTER_REDIRECT_PAGE);
  });
});
