import { test, expect, Locator } from '@playwright/test';
import {
  choosePage,
  choosePlan,
  field,
  fillPlan,
  loginAsAdmin,
  mockRestEndpoints,
  openNewPageWithJoinBlock,
  reseed,
  SETTINGS_PAGE,
  wpCli,
} from './helpers';

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

const GLOBAL_PLANS_PAGE = '/e2e-global-plans-join/';
const REDIRECT_TARGET = '/e2e-redirect-target/';
const REDIRECT_TARGET_TITLE = 'E2E Redirect Target';
const REDIRECT_TARGET_COPY = 'You have been redirected here instead of paying.';
const REDIRECT_CHECKBOX = 'Redirect to a page instead of taking payment';

function redirectPicker(plan: Locator): Locator {
  return field(plan, 'Page to redirect to');
}

async function chooseRedirectTarget(picker: Locator): Promise<void> {
  await choosePage(picker, REDIRECT_TARGET_TITLE);
}

/** Selected pages are shown in the association field's second column. */
function selectedPages(picker: Locator): Locator {
  return picker.locator('.cf-association__col').nth(1);
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

test.describe('Redirect help text', () => {
  test('names the CRMs the site uses, since redirected people are not recorded in them', async ({ page }) => {
    const NOT_RECORDED = 'They are not signed up as members and not recorded in Mailchimp and Zetkin.';
    const setCrms = (on: boolean) =>
      wpCli(`wp eval 'carbon_set_theme_option("use_mailchimp", ${on}); carbon_set_theme_option("use_zetkin", ${on});'`);

    setCrms(true);
    try {
      await loginAsAdmin(page);
      await page.goto(SETTINGS_PAGE);
      await page.getByRole('tab', { name: 'Membership Plans' }).click();

      const checkbox = field(page.locator('.cf-container'), 'Membership Plans')
        .locator('.cf-field.cf-checkbox', { hasText: REDIRECT_CHECKBOX })
        .first();
      await expect(checkbox).toContainText(NOT_RECORDED);

      // The block's own plans are built separately, so check them too.
      await openNewPageWithJoinBlock(page, 'E2E Block Editor Redirect');
      const plansField = field(page.locator('[data-type="carbon-fields/ck-join-form"]'), 'Custom Membership Plans');
      await plansField.locator('.cf-complex__inserter-button').click();
      await expect(
        plansField.locator('.cf-field.cf-checkbox', { hasText: REDIRECT_CHECKBOX }).first(),
      ).toContainText(NOT_RECORDED);
    } finally {
      setCrms(false);
    }
  });
});

test.describe('Custom plans in the CK Join Form block', () => {
  test('a redirecting plan set up in the block editor redirects on the published page', async ({ page }) => {
    await loginAsAdmin(page);
    await openNewPageWithJoinBlock(page, 'E2E Block Editor Redirect');

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
      wpCli(`wp post delete ${pageId} --force`);
    }
  });
});
