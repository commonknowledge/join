import { CONTINUE } from '../helpers';
import {
  FREE_JOIN_PAGE,
  expect,
  expectDetailsAccepted,
  expectDetailsRejected,
  getSetting,
  getSettings,
  joinFormEnv,
  loadLegacySettings,
  openJoinForm,
  stubChargebee,
  test,
} from './fixtures';

/**
 * Settings page, Features tab.
 *
 * For each setting: change it on the settings page, check the plugin reads
 * back the new value, that it reaches the join form's settings (env), and that
 * the form behaves accordingly. The integration switches are covered in
 * features-integrations.spec.ts.
 */

test.describe('Collect Date Of Birth', () => {
  test('is off by default: no date of birth fields', async ({ page }) => {
    expect(getSetting('collect_date_of_birth')).toBe(false);
    await openJoinForm(page);
    await expect(page.locator('input#dobDay')).toHaveCount(0);
  });

  test('when on, the form asks for a date of birth and requires it', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Date Of Birth', true);
    await settings.save();

    expect(getSetting('collect_date_of_birth')).toBe(true);
    expect((await joinFormEnv(page)).COLLECT_DATE_OF_BIRTH).toBe(true);

    await openJoinForm(page);
    await expect(page.locator('input#dobDay')).toBeVisible();
    await expect(page.locator('input#dobMonth')).toBeVisible();
    await expect(page.locator('input#dobYear')).toBeVisible();

    await page.locator('input#dobYear').fill('');
    await expectDetailsRejected(page);
    await expect(page.locator('input#dobYear.is-invalid')).toBeVisible();
  });
});

test.describe('Require age 18 or over, and Minimum Age', () => {
  /** A year of birth that makes someone exactly `age` years old today, if born on 1 January. */
  const bornYearsAgo = (age: number) => String(new Date().getFullYear() - age);

  async function enterBirthYear(page: import('@playwright/test').Page, year: string) {
    await page.locator('input#dobDay').fill('1');
    await page.locator('input#dobMonth').fill('1');
    await page.locator('input#dobYear').fill(year);
  }

  test('without it, people of any age can sign up', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Date Of Birth', true);
    await settings.save();

    expect(getSetting('require_age_18_or_over')).toBe(false);
    await openJoinForm(page);
    await enterBirthYear(page, bornYearsAgo(12));
    await expectDetailsAccepted(page);
  });

  test('with it and no minimum age set, under-18s are turned away', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Date Of Birth', true);
    await settings.setCheckbox('Require age 18 or over', true);
    await settings.setText('Minimum Age', '');
    await settings.save();

    expect(getSetting('require_age_18_or_over')).toBe(true);
    const env = await joinFormEnv(page);
    expect(env.REQUIRE_AGE_18_OR_OVER).toBe(true);
    expect(env.MINIMUM_AGE).toBe(18);

    await openJoinForm(page);
    await enterBirthYear(page, bornYearsAgo(17));
    await expectDetailsRejected(page);
    await expect(page.getByText('You must be at least 18 years old to sign up')).toBeVisible();

    await enterBirthYear(page, bornYearsAgo(18));
    await expectDetailsAccepted(page);
  });

  test('the minimum age can be raised', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Date Of Birth', true);
    await settings.setCheckbox('Require age 18 or over', true);
    await settings.setText('Minimum Age', '21');
    await settings.save();

    expect(getSetting('minimum_age')).toBe('21');
    expect((await joinFormEnv(page)).MINIMUM_AGE).toBe(21);

    await openJoinForm(page);
    await enterBirthYear(page, bornYearsAgo(20));
    await expectDetailsRejected(page);
    await expect(page.getByText('You must be at least 21 years old to sign up')).toBeVisible();

    await enterBirthYear(page, bornYearsAgo(21));
    await expectDetailsAccepted(page);
  });
});

test.describe("Ask 'How did you hear about us?'", () => {
  test('is off by default', async ({ page }) => {
    expect(getSetting('collect_hear_about_us')).toBe(false);
    await openJoinForm(page);
    await expect(page.locator('select#howDidYouHearAboutUs')).toHaveCount(0);
  });

  test('when on, the form asks, with a details box for "Other"', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox("Ask 'How did you hear about us?'", true);
    await settings.save();

    expect(getSetting('collect_hear_about_us')).toBe(true);
    expect((await joinFormEnv(page)).COLLECT_HEAR_ABOUT_US).toBe(true);

    await openJoinForm(page);
    await expect(page.getByRole('heading', { name: 'How did you hear about us?' })).toBeVisible();
    const question = page.locator('select#howDidYouHearAboutUs');
    await expect(question).toBeVisible();
    await expect(page.locator('#howDidYouHearAboutUsDetails')).toHaveCount(0);
    await question.selectOption('other');
    await expect(page.locator('#howDidYouHearAboutUsDetails')).toBeVisible();
  });
});

test.describe('Collect County', () => {
  test('is off by default', async ({ page }) => {
    expect(getSetting('collect_county')).toBe(false);
    await openJoinForm(page);
    await expect(page.locator('input#addressCounty')).toHaveCount(0);
  });

  test('when on, the form asks for a county and requires it', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect County', true);
    await settings.save();

    expect(getSetting('collect_county')).toBe(true);
    expect((await joinFormEnv(page)).COLLECT_COUNTY).toBe(true);

    await openJoinForm(page);
    const county = page.locator('input#addressCounty');
    await expect(county).toBeVisible();
    await county.fill('');
    await expectDetailsRejected(page);
    await expect(page.locator('input#addressCounty.is-invalid')).toBeVisible();
  });
});

test.describe('Contact consent', () => {
  const emailConsent = (page: import('@playwright/test').Page) => page.locator('input#contactByEmail');
  const phoneConsent = (page: import('@playwright/test').Page) => page.locator('input#contactByPhone');

  test('is not asked for by default', async ({ page }) => {
    expect(getSetting('collect_phone_and_email_contact_consent')).toBe(false);
    await openJoinForm(page);
    await expect(emailConsent(page)).toHaveCount(0);
    await expect(phoneConsent(page)).toHaveCount(0);
  });

  test('Collect Phone And Email Contact Consent shows unticked, optional checkboxes', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Phone And Email Contact Consent', true);
    await settings.save();

    expect(getSetting('collect_phone_and_email_contact_consent')).toBe(true);
    expect((await joinFormEnv(page)).COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT).toBe(true);

    await openJoinForm(page);
    await expect(emailConsent(page)).not.toBeChecked();
    await expect(phoneConsent(page)).not.toBeChecked();
    await expectDetailsAccepted(page);
  });

  test('Consent checkboxes checked by default pre-ticks them', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Phone And Email Contact Consent', true);
    await settings.setCheckbox('Consent checkboxes checked by default', true);
    await settings.save();

    expect(getSetting('consent_checked_by_default')).toBe(true);
    expect((await joinFormEnv(page)).CONSENT_CHECKED_BY_DEFAULT).toBe(true);

    await openJoinForm(page);
    await expect(emailConsent(page)).toBeChecked();
    await expect(phoneConsent(page)).toBeChecked();
  });

  test('Email consent required blocks the form until email consent is given', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Phone And Email Contact Consent', true);
    await settings.setCheckbox('Email consent required', true);
    await settings.save();

    expect(getSetting('require_email_consent')).toBe(true);
    expect((await joinFormEnv(page)).REQUIRE_EMAIL_CONSENT).toBe(true);

    await openJoinForm(page);
    await expectDetailsRejected(page);
    await expect(page.locator('input#contactByEmail.is-invalid')).toBeVisible();
    await emailConsent(page).check();
    await expectDetailsAccepted(page);
  });

  test('Phone consent required blocks the form until phone consent is given', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Collect Phone And Email Contact Consent', true);
    await settings.setCheckbox('Phone consent required', true);
    await settings.save();

    expect(getSetting('require_phone_consent')).toBe(true);
    expect((await joinFormEnv(page)).REQUIRE_PHONE_CONSENT).toBe(true);

    await openJoinForm(page);
    await expectDetailsRejected(page);
    await expect(page.locator('input#contactByPhone.is-invalid')).toBeVisible();
    await phoneConsent(page).check();
    await expectDetailsAccepted(page);
  });
});

test.describe('Enable international phone numbers', () => {
  const US_NUMBER = '2025550123';

  test('is off by default: only UK numbers are accepted', async ({ page }) => {
    expect(getSetting('enable_international_phone_numbers')).toBe(false);
    await openJoinForm(page);
    await expect(page.locator('select#phoneCountry')).toHaveCount(0);
    await page.locator('input#phoneNumber').fill(US_NUMBER);
    await expectDetailsRejected(page);
    await expect(page.getByText('Please enter a valid UK phone number')).toBeVisible();
  });

  test('when on, a country code can be chosen and other countries\' numbers are accepted', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Enable international phone numbers', true);
    await settings.save();

    expect(getSetting('enable_international_phone_numbers')).toBe(true);
    expect((await joinFormEnv(page)).ENABLE_INTERNATIONAL_PHONE_NUMBERS).toBe(true);

    await openJoinForm(page);
    const country = page.locator('select#phoneCountry');
    await expect(country).toBeVisible();
    await page.locator('input#phoneNumber').fill(US_NUMBER);
    await expectDetailsRejected(page);
    await expect(page.getByText('Please enter a valid phone number')).toBeVisible();

    await country.selectOption('US');
    await expectDetailsAccepted(page);
  });
});

test.describe('Hide price display for zero-price items', () => {
  async function goToPlans(page: import('@playwright/test').Page) {
    await openJoinForm(page, FREE_JOIN_PAGE);
    await page.locator(CONTINUE).click();
    await page.waitForSelector('[role="radiogroup"]');
  }

  test('is off by default: a free plan shows its £0 price', async ({ page }) => {
    expect(getSetting('hide_zero_price_display')).toBe(false);
    await goToPlans(page);
    await expect(page.locator('[role="radiogroup"]')).toContainText('£0');
  });

  test('when on, a free plan shows no price', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.setCheckbox('Hide price display for zero-price items', true);
    await settings.save();

    expect(getSetting('hide_zero_price_display')).toBe(true);
    expect((await joinFormEnv(page, FREE_JOIN_PAGE)).HIDE_ZERO_PRICE_DISPLAY).toBe(true);

    await goToPlans(page);
    await expect(page.locator('[role="radiogroup"]')).not.toContainText('£0');
  });
});

test.describe('Features saved before the settings page was rebuilt', () => {
  // See tests/settings/legacy/README.md.
  test('are still read, reach the join form, and change it', async ({ page }) => {
    loadLegacySettings('features');

    const switches = [
      'collect_date_of_birth',
      'require_age_18_or_over',
      'collect_hear_about_us',
      'collect_county',
      'collect_phone_and_email_contact_consent',
      'consent_checked_by_default',
      'require_email_consent',
      'require_phone_consent',
      'enable_international_phone_numbers',
      'hide_zero_price_display',
      'create_auth0_account',
      'use_zetkin',
      'use_gocardless',
      'use_gocardless_api',
      'use_chargebee',
      'use_stripe',
      'use_action_network',
      'use_mailchimp',
    ];
    const saved = getSettings([...switches, 'minimum_age']);
    for (const key of switches) {
      expect(saved[key], key).toBe(true);
    }
    expect(saved.minimum_age).toBe('21');

    const env = await joinFormEnv(page);
    for (const key of switches.filter((key) => !['use_zetkin', 'use_action_network'].includes(key))) {
      expect(env[key.toUpperCase()], key).toBe(true);
    }
    expect(env.MINIMUM_AGE).toBe(21);

    await stubChargebee(page);
    await openJoinForm(page);
    await expect(page.locator('input#dobYear')).toBeVisible();
    await expect(page.locator('input#addressCounty')).toBeVisible();
    await expect(page.locator('select#howDidYouHearAboutUs')).toBeVisible();
    await expect(page.locator('select#phoneCountry')).toBeVisible();
    await expect(page.locator('input#contactByEmail')).toBeChecked();
    await expect(page.locator('input#password')).toBeVisible();
  });
});
