import { Page } from '@playwright/test';
import { CONTINUE } from '../helpers';
import {
  FREE_JOIN_PAGE,
  STANDARD_JOIN_PAGE,
  expect,
  getSetting,
  getSettings,
  joinFormEnv,
  loadLegacySettings,
  openJoinForm,
  test,
} from './fixtures';

/**
 * Settings page, Copy tab: the join form's wording.
 *
 * For each setting: the default before anything is saved, a new value saved
 * through the settings page, and where it shows on the join form.
 */

const CUSTOM_FIELDS_PAGE = '/e2e-custom-fields/';
const MULTI_CURRENCY_PAGE = '/e2e-multi-currency/';

const details = (page: Page) => page.locator('form.form-content').first();

/** From the details step to the plan step. */
async function goToPlans(page: Page, url = STANDARD_JOIN_PAGE) {
  await openJoinForm(page, url);
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
}

/**
 * Switches on GoCardless with its Custom Pages (bank details taken in the
 * form), the route by which a Direct Debit member reaches the confirmation
 * step. Leaves the settings page unsaved.
 */
async function useGoCardlessCustomPages(settings: import('./support').JoinSettingsPage) {
  await settings.open('Features');
  await settings.enableIntegration('Use GoCardless');
  await settings.setCheckbox('Use GoCardless Custom Pages', true);
}

/** From the details step to the bank details step (GoCardless Custom Pages). */
async function goToBankDetails(page: Page) {
  await goToPlans(page);
  await page.locator(CONTINUE).click();
  await page.waitForSelector('input#ddAccountNumber');
}

/** On to the confirmation step for a £5 a month Direct Debit membership. */
async function goToConfirmStep(page: Page) {
  await goToBankDetails(page);
  await page.locator('input#ddConfirmAccountHolder').check();
  await page.locator('button[type="submit"]').last().click();
  await expect(page.getByText('Confirm your details')).toBeVisible();
}

/** Clicks the confirmation step's join button, holding the join request open. */
async function clickJoin(page: Page, buttonText: string) {
  await page.route('**/wp-json/join/v1/join', () => {
    // Never answered, so the "joining" message stays on screen.
  });
  await page.getByRole('button', { name: buttonText }).click();
}

test.describe('Organisation details', () => {
  // Linear JOIN-164: marked required (*), but a blank value saves.
  test.fixme('are required', async ({ settings }) => {
    await settings.open('Copy');
    await settings.setText('Organisation Name*', '');

    expect((await settings.saveExpectingErrors()).join(' ')).toContain('Organisation Name');
    expect(getSetting('organisation_name')).toBe('E2E Organisation');
  });

  test('show in the Direct Debit wording and on the confirmation step, with the Joining Verb', async ({
    settings,
    page,
  }) => {
    await useGoCardlessCustomPages(settings);
    await settings.tab('Copy');
    await settings.setText('Organisation Name*', 'Test Union');
    await settings.setText('Organisation Bank Name*', 'TESTUNION');
    await settings.setText('Organisation Email Address*', 'members@test-union.example');
    await settings.setText('Joining Verb', 'Signing up to');
    await settings.save();

    expect(
      getSettings(['organisation_name', 'organisation_bank_name', 'organisation_email_address', 'joining_verb']),
    ).toEqual({
      organisation_name: 'Test Union',
      organisation_bank_name: 'TESTUNION',
      organisation_email_address: 'members@test-union.example',
      joining_verb: 'Signing up to',
    });

    await goToBankDetails(page);
    await expect(page.getByText(/your Direct Debit Test Union will notify you/)).toBeVisible();

    await goToConfirmStep(page);
    await expect(page.getByText('You are paying for your membership of Test Union by Direct Debit.')).toBeVisible();
    await expect(page.getByText('On your bank statement the charge will appear as "TESTUNION".')).toBeVisible();
    await expect(page.getByRole('link', { name: 'members@test-union.example' })).toHaveAttribute(
      'href',
      'mailto:members@test-union.example',
    );

    await clickJoin(page, 'Join Test Union');
    await expect(page.getByText('Signing up to Test Union')).toBeVisible();
  });

  test('Joining Verb falls back to "Joining" when blank', async ({ settings, page }) => {
    await useGoCardlessCustomPages(settings);
    await settings.tab('Copy');
    await settings.setText('Joining Verb', '');
    await settings.save();

    expect((await joinFormEnv(page)).JOINING_VERB).toBe('Joining');
    await goToConfirmStep(page);
    await clickJoin(page, 'Join E2E Organisation');
    await expect(page.getByText('Joining E2E Organisation')).toBeVisible();
  });
});

test.describe('Details step', () => {
  test('About You Heading and Copy', async ({ settings, page }) => {
    const defaults = getSettings(['about_you_heading', 'about_you_copy']);
    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: defaults.about_you_heading as string })).toBeVisible();

    await settings.open('Copy');
    await settings.setText('About You Heading', 'Who are you?');
    await settings.setRichText('About You Copy', '<p>Tell us <strong>who you are</strong>.</p>');
    await settings.save();

    expect(getSetting('about_you_heading')).toBe('Who are you?');
    expect(getSetting('about_you_copy')).toContain('Tell us <strong>who you are</strong>.');
    expect((await joinFormEnv(page)).ABOUT_YOU_COPY).toContain('<p>Tell us <strong>who you are</strong>.</p>');

    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: 'Who are you?' })).toBeVisible();
    await expect(details(page).locator('strong', { hasText: 'who you are' })).toBeVisible();
  });

  test('Date Of Birth Heading and Copy (with date of birth collected)', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Date Of Birth', true);
    await settings.tab('Copy');
    await settings.setText('Date Of Birth Heading', 'Your birthday');
    await settings.setRichText('Date Of Birth Copy', '<p>We ask so we can check your age.</p>');
    await settings.save();

    expect(getSettings(['date_of_birth_heading'])).toEqual({ date_of_birth_heading: 'Your birthday' });

    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: 'Your birthday' })).toBeVisible();
    await expect(page.getByText('We ask so we can check your age.')).toBeVisible();
  });

  test('Password Purpose (with Auth0 accounts on)', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Create Auth0 Account');
    await settings.tab('Copy');
    await settings.setRichText('Password Purpose', '<p>Use this password to log in to the members area.</p>');
    await settings.save();

    expect(getSetting('password_purpose')).toContain('Use this password to log in to the members area.');

    await openJoinForm(page);
    await expect(page.getByText('Use this password to log in to the members area.')).toBeVisible();
  });

  // Linear JOIN-162: the form only shows this copy when a build-time
  // HIDE_HOME_ADDRESS_COPY flag is set (the condition is inverted), and the
  // plugin never sets it, so the copy never shows.
  test.fixme('Home Address Copy shows above the address fields', async ({ settings, page }) => {
    await settings.open('Copy');
    await settings.setRichText('Home Address Copy', '<p>We post your membership card here.</p>');
    await settings.save();

    expect(getSetting('home_address_copy')).toContain('We post your membership card here.');
    await openJoinForm(page);
    await expect(page.getByText('We post your membership card here.')).toBeVisible();
  });

  test('Form section heading names the custom fields section, and can be removed', async ({ settings, page }) => {
    expect(getSetting('custom_fields_heading')).toBe('More about you');
    await openJoinForm(page, CUSTOM_FIELDS_PAGE);
    await expect(page.getByRole('heading', { name: 'More about you' })).toBeVisible();

    await settings.open('Copy');
    await settings.setText('Form section heading (leave blank for no heading)', 'About your work');
    await settings.save();
    await openJoinForm(page, CUSTOM_FIELDS_PAGE);
    await expect(page.getByRole('heading', { name: 'About your work' })).toBeVisible();

    await settings.open('Copy');
    await settings.setText('Form section heading (leave blank for no heading)', '');
    await settings.save();
    await openJoinForm(page, CUSTOM_FIELDS_PAGE);
    await expect(page.getByLabel('Branch')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'About your work' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'More about you' })).toHaveCount(0);
  });

  test('Contact Details Heading and Copy', async ({ settings, page }) => {
    await settings.open('Copy');
    await settings.setText('Contact Details Heading', 'How to reach you');
    await settings.setRichText('Contact Details Copy', '<p>We only use these for membership.</p>');
    await settings.save();

    expect(getSetting('contact_details_heading')).toBe('How to reach you');

    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: 'How to reach you' })).toBeVisible();
    await expect(page.getByText('We only use these for membership.')).toBeVisible();
  });

  test('Contact Consent Copy (with contact consent collected)', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Phone And Email Contact Consent', true);
    await settings.tab('Copy');
    await settings.setRichText('Contact Consent Copy', '<p>Tick how we may contact you.</p>');
    await settings.save();

    await openJoinForm(page);
    await expect(page.getByText('Tick how we may contact you.')).toBeVisible();
  });

  test('"How did you hear about us?" heading, options and details label', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox("Ask 'How did you hear about us?'", true);
    await settings.tab('Copy');
    await settings.setText('"How did you hear about us?" heading', 'Where did you find us?');
    await settings.setText('"How did you hear about us?" options', ' A friend ,Social media,, A poster ');
    await settings.setText('"How did you hear about us?" additional details textbox label', 'Tell us more');
    await settings.save();

    expect(getSetting('hear_about_us_options')).toBe(' A friend ,Social media,, A poster ');
    // Trimmed, and empty entries dropped.
    expect((await joinFormEnv(page)).HEAR_ABOUT_US_OPTIONS).toEqual(['A friend', 'Social media', 'A poster']);

    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: 'Where did you find us?' })).toBeVisible();
    const question = page.locator('select#howDidYouHearAboutUs');
    await expect(question.locator('option')).toHaveText([
      'Choose an option',
      'A friend',
      'Social media',
      'A poster',
      'Other',
    ]);
    await question.selectOption('other');
    await expect(page.getByLabel('Tell us more')).toBeVisible();
  });

  test('Privacy Copy', async ({ settings, page }) => {
    await settings.open('Copy');
    await settings.setRichText('Privacy Copy', '<p>Read our <a href="https://example.org/privacy">privacy policy</a>.</p>');
    await settings.save();

    await openJoinForm(page);
    await expect(page.getByRole('link', { name: 'privacy policy' })).toHaveAttribute('href', 'https://example.org/privacy');
  });
});

test.describe('Plan step', () => {
  test('Membership Tiers Heading and Copy, with the copy\'s built-in default', async ({ settings, page }) => {
    expect(getSetting('membership_tiers_copy')).toBe('');
    await goToPlans(page);
    await expect(page.getByText('You can change or cancel whenever you want.')).toBeVisible();

    await settings.open('Copy');
    await settings.setText('Membership Tiers Heading', 'Pick a plan');
    await settings.setRichText('Membership Tiers Copy', '<p>All plans include voting rights.</p>');
    await settings.save();

    await goToPlans(page);
    await expect(page.getByRole('heading', { name: 'Pick a plan' })).toBeVisible();
    await expect(page.getByText('All plans include voting rights.')).toBeVisible();
    await expect(page.getByText('You can change or cancel whenever you want.')).toHaveCount(0);
  });

  test('Currency Selection Copy shows when there is a choice of currency', async ({ settings, page }) => {
    await settings.open('Copy');
    await settings.setRichText('Currency Selection Copy', '<p>Pay in the currency of your bank account.</p>');
    await settings.save();

    await goToPlans(page, MULTI_CURRENCY_PAGE);
    await expect(page.getByText('Pay in the currency of your bank account.')).toBeVisible();

    await goToPlans(page);
    await expect(page.getByText('Pay in the currency of your bank account.')).toHaveCount(0);
  });

  // Linear JOIN-163: passed to the form, but nothing in the form shows it.
  test.fixme('"Only valid for monthly subscriptions" (day of month copy) labels the payment day', async ({ settings, page }) => {
    await settings.open('Copy');
    await settings.setText('Only valid for monthly subscriptions', 'Which day should we take payment?');
    await settings.save();

    expect((await joinFormEnv(page)).SUBSCRIPTION_DAY_OF_MONTH_COPY).toBe('Which day should we take payment?');
    await goToPlans(page);
    await page.locator(CONTINUE).click();
    await expect(page.getByText('Which day should we take payment?')).toBeVisible();
  });
});

test.describe('Sidebar and steps', () => {
  test('Join Form Sidebar Heading and Membership Stage Label', async ({ settings, page }) => {
    await openJoinForm(page);
    await expect(page.locator('.progress-steps h6')).toHaveText('Join Us');
    await expect(page.locator('.progress-steps')).toContainText('Your Membership');

    await settings.open('Copy');
    await settings.setText('Join Form Sidebar Heading', 'Become a member');
    await settings.setText('Membership Stage Label', 'Your Plan');
    await settings.save();

    expect(getSettings(['join_form_sidebar_heading', 'membership_stage_label'])).toEqual({
      join_form_sidebar_heading: 'Become a member',
      membership_stage_label: 'Your Plan',
    });

    await goToPlans(page, FREE_JOIN_PAGE);
    await expect(page.locator('.progress-steps h6')).toHaveText('Become a member');
    await expect(page.locator('.progress-step--current')).toContainText('Your Plan');
  });

  test('both fall back to their defaults when blank', async ({ settings, page }) => {
    await settings.open('Copy');
    await settings.setText('Join Form Sidebar Heading', '');
    await settings.setText('Membership Stage Label', '');
    await settings.save();

    const env = await joinFormEnv(page);
    expect([env.JOIN_FORM_SIDEBAR_HEADING, env.MEMBERSHIP_STAGE_LABEL]).toEqual(['Join Us', 'Your Membership']);
  });
});

test.describe('Copy saved before the settings page was rebuilt', () => {
  // See tests/settings/legacy/README.md.
  test('is still read and still shows on the form', async ({ page }) => {
    loadLegacySettings('copy');

    expect(getSettings(['organisation_name', 'about_you_heading', 'hear_about_us_options'])).toEqual({
      organisation_name: 'Legacy Union',
      about_you_heading: 'Legacy about you',
      hear_about_us_options: 'Legacy friend,Legacy poster',
    });

    const env = await joinFormEnv(page);
    expect(env.ABOUT_YOU_COPY).toContain('Legacy <em>about</em> copy');
    expect(env.HEAR_ABOUT_US_OPTIONS).toEqual(['Legacy friend', 'Legacy poster']);

    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: 'Legacy about you' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Legacy contact details' })).toBeVisible();
    await expect(page.locator('.progress-steps h6')).toHaveText('Legacy sidebar');
    await expect(page.locator('.progress-steps')).toContainText('Legacy stage');
  });
});
