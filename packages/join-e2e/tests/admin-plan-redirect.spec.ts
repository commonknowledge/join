import { test, expect, Locator, Page } from '@playwright/test';
import { execSync } from 'child_process';
import path from 'path';
import { loginAsAdmin, mockRestEndpoints, CONTINUE } from './helpers';

/**
 * Configuring a redirecting plan through wp-admin, end to end
 *
 * The same plan field is used on the Join settings page (global plans) and in
 * the CK Join Form block (custom plans), so both are driven through their real
 * UI here, saved, and then checked on the front end.
 *
 * The settings test replaces the global plans, which no other spec relies on.
 * Saving the settings page also rewrites every other global setting from the
 * form, including ones the seed sets directly (STRIPE_DIRECT_DEBIT_ONLY), so
 * the seed is re-run afterwards to put them back for the specs that follow.
 */

function reseed(): void {
  execSync('npx wp-env run tests-cli wp eval-file /var/www/html/wp-content/e2e-scripts/setup.php', {
    cwd: path.resolve(__dirname, '..'),
    stdio: 'ignore',
  });
}

const SETTINGS_PAGE = '/wp-admin/admin.php?page=crb_carbon_fields_container_ck_join_flow.php';
const GLOBAL_PLANS_PAGE = '/e2e-global-plans-join/';
const REDIRECT_TARGET = '/e2e-redirect-target/';
const REDIRECT_TARGET_TITLE = 'E2E Redirect Target';
const REDIRECT_TARGET_COPY = 'You have been redirected here instead of paying.';
const REDIRECT_CHECKBOX = 'Redirect to a page instead of taking payment';

/** A Carbon Fields field inside `scope`, matched on its exact label. Section headings are skipped. */
function field(scope: Locator, label: string): Locator {
  return scope
    .locator('.cf-field:not(.cf-separator)')
    .filter({ has: scope.page().locator('.cf-field__label', { hasText: new RegExp(`^${label}\\*?$`) }) })
    .first();
}

/** Fills in a plan's name and price. "Price" alone would also match "Price Point ID". */
async function fillPlan(plan: Locator, name: string, price: string): Promise<void> {
  await field(plan, 'Name').locator('input').fill(name);
  await field(plan, 'Price').locator('input').fill(price);
}

function redirectPicker(plan: Locator): Locator {
  return field(plan, 'Page to redirect to');
}

async function chooseRedirectTarget(picker: Locator): Promise<void> {
  await picker.locator('.cf-search-input__inner').fill(REDIRECT_TARGET_TITLE);
  // The search runs over AJAX; clicking before it lands hits the old list.
  await expect(picker.locator('.cf-association__counter')).toHaveText(/Showing 1 of 1 results/);
  await picker
    .locator('.cf-association__option', { hasText: REDIRECT_TARGET_TITLE })
    // The block editor renders this button without an accessible name.
    .locator('button.dashicons-plus-alt')
    .click();
}

/** Selected pages are shown in the association field's second column. */
function selectedPages(picker: Locator): Locator {
  return picker.locator('.cf-association__col').nth(1);
}

async function choosePlan(page: Page, label: string): Promise<void> {
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
  await page.locator('label.radio-panel', { has: page.locator(`[id="membership-${label}"]`) }).click();
  await page.locator(CONTINUE).click();
}

test.describe('Global plans on the Join settings page', () => {
  test.describe.configure({ mode: 'serial' });

  test.afterAll(reseed);

  test('the page picker only shows once the box is ticked, and the choice is saved', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await loginAsAdmin(page);
    await page.goto(SETTINGS_PAGE);
    await page.getByRole('tab', { name: 'Membership Plans' }).click();

    const plansField = field(page.locator('.cf-container'), 'Membership Plans');
    const plans = plansField.locator('.cf-complex__group');

    // Start from a single plan, whatever earlier runs left behind. A fresh
    // site has no global plans at all.
    await expect(plansField.locator('.cf-complex__inserter-button')).toBeVisible();
    if ((await plans.count()) === 0) {
      await plansField.locator('.cf-complex__inserter-button').click();
    }
    await expect(plans.first()).toBeVisible();
    while ((await plans.count()) > 1) {
      await plans.last().locator('button[title="Remove"]').click();
    }
    const standard = plans.first();
    if (await standard.evaluate((el) => el.classList.contains('cf-complex__group--collapsed'))) {
      await standard.locator('button[title="Expand"], button[title="Collapse"]').first().click();
    }

    await fillPlan(standard, 'Global Standard', '5');
    await standard.getByLabel(REDIRECT_CHECKBOX).uncheck();
    await expect(redirectPicker(standard)).toBeHidden();

    await plansField.locator('.cf-complex__inserter-button').click();
    const redirecting = plans.last();
    await fillPlan(redirecting, 'Global Redirect', '4');

    await expect(redirectPicker(redirecting)).toBeHidden();
    await redirecting.getByLabel(REDIRECT_CHECKBOX).check();
    await expect(redirectPicker(redirecting)).toBeVisible();

    await chooseRedirectTarget(redirectPicker(redirecting));
    await expect(selectedPages(redirectPicker(redirecting))).toContainText(REDIRECT_TARGET_TITLE);

    // Unticking hides the picker but keeps the chosen page. Wait for it to be
    // redrawn before saving, or the page is not in the submitted form yet.
    await redirecting.getByLabel(REDIRECT_CHECKBOX).uncheck();
    await expect(redirectPicker(redirecting)).toBeHidden();
    await redirecting.getByLabel(REDIRECT_CHECKBOX).check();
    await expect(selectedPages(redirectPicker(redirecting))).toContainText(REDIRECT_TARGET_TITLE);

    await Promise.all([page.waitForNavigation(), page.locator('#publish').click()]);

    await page.getByRole('tab', { name: 'Membership Plans' }).click();
    const saved = field(page.locator('.cf-container'), 'Membership Plans').locator('.cf-complex__group');
    await expect(saved).toHaveCount(2);
    const savedRedirecting = saved.last();
    if (await savedRedirecting.evaluate((el) => el.classList.contains('cf-complex__group--collapsed'))) {
      await savedRedirecting.locator('.cf-complex__group-head').click();
    }
    await expect(savedRedirecting.getByLabel(REDIRECT_CHECKBOX)).toBeChecked();
    await expect(selectedPages(redirectPicker(savedRedirecting))).toContainText(REDIRECT_TARGET_TITLE);
  });

  test('a join form with no plans of its own redirects on the global redirecting plan', async ({ page }) => {
    await mockRestEndpoints(page);
    await page.goto(GLOBAL_PLANS_PAGE);
    await page.waitForSelector('input#firstName');

    await choosePlan(page, 'Global Redirect');

    await page.waitForURL(`**${REDIRECT_TARGET}`);
    await expect(page.getByText(REDIRECT_TARGET_COPY)).toBeVisible();
  });

  test('the other global plan still takes payment', async ({ page }) => {
    await mockRestEndpoints(page);
    await page.goto(GLOBAL_PLANS_PAGE);
    await page.waitForSelector('input#firstName');

    await choosePlan(page, 'Global Standard');

    await expect(page.locator('.progress-step--current')).toContainText('Payment');
  });
});

test.describe('Custom plans in the CK Join Form block', () => {
  test('a redirecting plan set up in the block editor redirects on the published page', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/post-new.php?post_type=page');
    await page.waitForFunction(() => (window as any).wp?.data?.select('core/editor'));

    // New pages open with WordPress's starter pattern picker over the editor.
    const patterns = page.getByRole('dialog', { name: 'Choose a pattern' });
    if (await patterns.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false)) {
      await patterns.getByRole('button', { name: 'Close' }).click();
    }

    await page.evaluate(() => {
      const wp = (window as any).wp;
      wp.data.dispatch('core/editor').editPost({ title: 'E2E Block Editor Redirect' });
      wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('carbon-fields/ck-join-form'));
    });

    const block = page.locator('[data-type="carbon-fields/ck-join-form"]');
    await chooseRedirectTarget(field(block, 'Page to redirect to after joining'));

    const plansField = field(block, 'Custom Membership Plans');
    const plans = plansField.locator('.cf-complex__group');

    await plansField.locator('.cf-complex__inserter-button').click();
    await fillPlan(plans.last(), 'Block Standard', '5');

    await plansField.getByRole('button', { name: 'Add Entry' }).click();
    const redirecting = plans.last();
    await fillPlan(redirecting, 'Block Redirect', '3');

    await expect(redirectPicker(redirecting)).toBeHidden();
    await redirecting.getByLabel(REDIRECT_CHECKBOX).check();
    await expect(redirectPicker(redirecting)).toBeVisible();
    await chooseRedirectTarget(redirectPicker(redirecting));

    const pageId = await page.evaluate(async () => {
      const wp = (window as any).wp;
      await wp.data.dispatch('core/editor').editPost({ status: 'publish' });
      await wp.data.dispatch('core/editor').savePost();
      return wp.data.select('core/editor').getCurrentPostId();
    });
    const link = await page.evaluate(() => (window as any).wp.data.select('core/editor').getPermalink());

    try {
      await mockRestEndpoints(page);
      await page.goto(link);
      await page.waitForSelector('input#firstName');

      await choosePlan(page, 'Block Redirect');

      await page.waitForURL(`**${REDIRECT_TARGET}`);
      await expect(page.getByText(REDIRECT_TARGET_COPY)).toBeVisible();

      await page.goto(link);
      await choosePlan(page, 'Block Standard');
      await expect(page.locator('.progress-step--current')).toContainText('Payment');
    } finally {
      await page.goto('/wp-admin/');
      await page.evaluate(
        (id) => (window as any).wp.apiFetch({ path: `/wp/v2/pages/${id}?force=true`, method: 'DELETE' }),
        pageId,
      );
    }
  });
});
