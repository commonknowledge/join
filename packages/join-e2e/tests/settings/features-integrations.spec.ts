import { Page } from '@playwright/test';
import {
  STANDARD_JOIN_PAGE,
  continueToPayment,
  expect,
  expectDetailsAccepted,
  expectDetailsRejected,
  fakeApiRequests,
  getSetting,
  joinFormEnv,
  openJoinForm,
  overrideFakeApi,
  stubChargebee,
  test,
  wpEvalJson,
} from './fixtures';

/**
 * Settings page, Features tab: the integration switches.
 *
 * Here: each switch is refused without its credentials, is saved with them,
 * and changes the join form as expected. What the plugin then sends to
 * Mailchimp, Action Network and Zetkin on a join is covered with the
 * Integrations tab.
 */

const SWITCHES: { label: string; key: string; name: string }[] = [
  { label: 'Create Auth0 Account', key: 'create_auth0_account', name: 'Auth0' },
  { label: 'Use Zetkin', key: 'use_zetkin', name: 'Zetkin' },
  { label: 'Use GoCardless', key: 'use_gocardless', name: 'GoCardless' },
  { label: 'Use Stripe', key: 'use_stripe', name: 'Stripe' },
  { label: 'Use Chargebee', key: 'use_chargebee', name: 'ChargeBee' },
  { label: 'Use Action Network', key: 'use_action_network', name: 'Action Network' },
  { label: 'Use Mailchimp', key: 'use_mailchimp', name: 'MailChimp' },
];

test.describe('Integration switches', () => {
  for (const { label, key, name } of SWITCHES) {
    test(`${label} is refused without its credentials, and nothing is saved`, async ({ settings }) => {
      await settings.open('Features');
      await settings.setCheckbox(label, true);
      // Proves the whole save is refused, not just the switch.
      await settings.setCheckbox('Collect County', true);

      expect(await settings.saveExpectingErrors()).toEqual([`${name} credentials are missing (see Integrations tab).`]);
      expect(getSetting(key)).toBe(false);
      expect(getSetting('collect_county')).toBe(false);
    });

    test(`${label} is saved with its credentials`, async ({ settings }) => {
      await settings.open('Features');
      await settings.enableIntegration(label);
      await settings.save();

      expect(getSetting(key)).toBe(true);
    });
  }

  test('Use GoCardless Custom Pages is refused without Use GoCardless', async ({ settings }) => {
    await settings.open('Features');
    await settings.setCheckbox('Use GoCardless Custom Pages', true);

    expect(await settings.saveExpectingErrors()).toEqual([
      'Must select Use GoCardless to use GoCardless Custom Pages.',
    ]);
    expect(getSetting('use_gocardless_api')).toBe(false);
  });

  test('Use Mailchimp reaches the join form settings', async ({ settings, page }) => {
    expect((await joinFormEnv(page)).USE_MAILCHIMP).toBe(false);
    await settings.open('Features');
    await settings.enableIntegration('Use Mailchimp');
    await settings.save();

    expect((await joinFormEnv(page)).USE_MAILCHIMP).toBe(true);
  });
});

test.describe('Use Stripe', () => {
  test('saving with Stripe on sets up each global plan in Stripe, and remembers it', async ({ settings }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.save();

    const stripe = await fakeApiRequests('api.stripe.com');
    const productCreated = stripe.find((request) => request.method === 'POST' && request.path === '/v1/products');
    const priceCreated = stripe.find((request) => request.method === 'POST' && request.path === '/v1/prices');
    expect(productCreated?.body).toMatchObject({
      name: 'Membership: Baseline Plan',
      metadata: { membership_plan: 'baseline-plan_monthly_gbp' },
    });
    expect(priceCreated?.body).toMatchObject({
      product: 'prod_e2e_1',
      unit_amount: '700',
      currency: 'GBP',
      recurring: { interval: 'month' },
    });
    expect(productCreated?.headers.authorization).toBe('Bearer sk_test_e2e');

    const saved = wpEvalJson<Record<string, string>>(
      `return get_option('ck_join_flow_membership_plan_baseline-plan_monthly_gbp');`,
    );
    expect(saved).toMatchObject({ stripe_product_id: 'prod_e2e_1', stripe_price_id: 'price_e2e_2' });
  });

  test('saving again reuses the Stripe product and price rather than creating duplicates', async ({ settings }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.save();
    await settings.save();

    const created = (await fakeApiRequests('api.stripe.com')).filter((request) => request.method === 'POST');
    expect(created.map((request) => request.path)).toEqual(['/v1/products', '/v1/prices']);
  });

  // Linear JOIN-161: a rejected Stripe key (or Stripe being unreachable) makes
  // the settings page save end in a PHP fatal error instead of a message.
  test.fixme('a Stripe key Stripe rejects gives an error message, not a crash', async ({ settings }) => {
    await overrideFakeApi({
      host: 'api.stripe.com',
      status: 401,
      body: { error: { type: 'invalid_request_error', message: 'Invalid API Key provided: sk_test_e2e' } },
    });
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');

    const errors = await settings.saveExpectingErrors();
    expect(errors.join(' ')).toContain('Stripe');
  });
});

test.describe('Create Auth0 Account', () => {
  test('is off by default: no password is asked for', async ({ page }) => {
    await openJoinForm(page);
    await expect(page.locator('input#password')).toHaveCount(0);
  });

  test('when on, the form asks for a strong password', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Create Auth0 Account');
    await settings.save();

    expect((await joinFormEnv(page)).CREATE_AUTH0_ACCOUNT).toBe(true);

    await openJoinForm(page);
    const password = page.locator('input#password');
    await expect(password).toBeVisible();

    await password.fill('weakpassword');
    await expectDetailsRejected(page);
    await expect(page.locator('input#password.is-invalid')).toBeVisible();

    await password.fill('Str0ng!password');
    await expectDetailsAccepted(page);
  });
});

test.describe('Payment providers', () => {
  const logo = (page: Page, provider: string) => page.locator(`.progress-steps img[alt="${provider}"]`);

  test('none is on by default: no provider logos', async ({ page }) => {
    const env = await joinFormEnv(page);
    expect([env.USE_STRIPE, env.USE_GOCARDLESS, env.USE_CHARGEBEE]).toEqual([false, false, false]);
    await openJoinForm(page);
    for (const provider of ['Stripe', 'GoCardless', 'Chargebee']) {
      await expect(logo(page, provider)).toHaveCount(0);
    }
  });

  test('Use Stripe shows the Stripe logo and passes the publishable key to the form', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.save();

    const env = await joinFormEnv(page);
    expect(env.USE_STRIPE).toBe(true);
    expect(env.STRIPE_PUBLISHABLE_KEY).toBe('pk_test_e2e');

    await openJoinForm(page);
    await expect(logo(page, 'Stripe')).toBeVisible();
  });

  test('Use GoCardless sends Direct Debit members to GoCardless\'s hosted pages', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use GoCardless');
    await settings.save();

    expect((await joinFormEnv(page)).USE_GOCARDLESS).toBe(true);

    await openJoinForm(page);
    await expect(logo(page, 'GoCardless')).toBeVisible();

    const redirectTo = `${STANDARD_JOIN_PAGE}?gocardless-hosted-page`;
    let redirectRequest: Record<string, unknown> | null = null;
    await page.route('**/wp-json/join/v1/gocardless/auth', async (route) => {
      redirectRequest = route.request().postDataJSON();
      await route.fulfill({ json: { href: redirectTo, gcBillingRequestId: 'BRQ-E2E' } });
    });

    await continueToPayment(page);
    await page.waitForURL(`**${redirectTo}`);
    expect(redirectRequest).toMatchObject({ paymentMethod: 'directDebit', membership: 'standard' });
  });

  test('Use GoCardless Custom Pages takes bank details in the form instead', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use GoCardless');
    await settings.setCheckbox('Use GoCardless Custom Pages', true);
    await settings.save();

    expect(getSetting('use_gocardless_api')).toBe(true);
    expect((await joinFormEnv(page)).USE_GOCARDLESS_API).toBe(true);

    await openJoinForm(page);
    let redirected = false;
    await page.route('**/wp-json/join/v1/gocardless/auth', async (route) => {
      redirected = true;
      await route.abort();
    });

    await continueToPayment(page);
    await expect(page.locator('.progress-step--current')).toContainText('Payment');
    await expect(page.locator('input#ddAccountNumber')).toBeVisible();
    expect(redirected).toBe(false);
  });

  test('Use Chargebee shows the Chargebee logo and sets Chargebee up with the site and publishable key', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Chargebee');
    await settings.save();

    const env = await joinFormEnv(page);
    expect(env.USE_CHARGEBEE).toBe(true);
    expect(env.CHARGEBEE_SITE_NAME).toBe('e2e');
    expect(env.CHARGEBEE_API_PUBLISHABLE_KEY).toBe('e2e-chargebee-publishable-key');

    const html = await (await page.request.get(STANDARD_JOIN_PAGE)).text();
    expect(html).toContain('https://js.chargebee.com/v2/chargebee.js');

    await stubChargebee(page);
    await openJoinForm(page);
    await expect(logo(page, 'Chargebee')).toBeVisible();
    expect(await page.evaluate(() => (window as any).chargebeeInitOptions)).toEqual({
      site: 'e2e',
      publishableKey: 'e2e-chargebee-publishable-key',
    });
  });

  test('with two providers offering different methods, members choose how to pay', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use GoCardless');
    await settings.enableIntegration('Use Chargebee');
    await settings.save();

    await stubChargebee(page);
    await openJoinForm(page);
    await continueToPayment(page);

    await expect(page.getByText('How would you like to pay?')).toBeVisible();
    await expect(page.locator('[id="paymentMethod-directDebit"]')).toBeAttached();
    await expect(page.locator('[id="paymentMethod-creditCard"]')).toBeAttached();
    await expect(page.locator('[role="radiogroup"]')).toContainText('Direct Debit');
    await expect(page.locator('[role="radiogroup"]')).toContainText('Credit or Debit Card');
  });
});
