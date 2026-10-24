import { Page } from '@playwright/test';
import { readFileSync } from 'fs';
import { CONTINUE } from '../helpers';
import {
  STANDARD_JOIN_PAGE,
  expect,
  fakeApiRequests,
  getSettings,
  joinFormEnv,
  openJoinForm,
  overrideFakeApi,
  seedFakeStripe,
  stubChargebee,
  stubStripe,
  test,
} from './fixtures';

/**
 * Settings page, Integrations tab: the payment providers' settings (Stripe,
 * GoCardless, Chargebee) and the postcode address lookup.
 *
 * Turning each provider on, its logo and the keys the form is given are
 * covered with the Features tab (features-integrations.spec.ts). Here: the
 * provider settings that change how members pay, and the credentials and
 * environment the plugin uses when it calls each provider (fake API).
 */

/** From the details step, through the plan step, to whatever comes next. */
async function continueToPayment(page: Page): Promise<void> {
  await page.locator(CONTINUE).click();
  await page.waitForSelector('[role="radiogroup"]');
  await page.locator('label.radio-panel').first().click();
  await page.locator(CONTINUE).click();
}

/** The payment methods the form offers through Stripe's payment fields. */
async function stripePaymentMethods(page: Page): Promise<string[]> {
  await stubStripe(page);
  await openJoinForm(page);
  await continueToPayment(page);
  await page.waitForFunction(() => (window as any).stripeElementsOptions);
  return page.evaluate(() => (window as any).stripeElementsOptions.paymentMethodTypes);
}

test.describe('Stripe', () => {
  test('Enable direct debit through Stripe offers Direct Debit as well as card', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.tab('Integrations');
    await settings.setCheckbox('Enable direct debit through Stripe (must be enabled in your Stripe account)', true);
    await settings.setCheckbox('Direct Debit only, hide credit card option', false);
    await settings.save();

    expect(getSettings(['stripe_direct_debit', 'stripe_direct_debit_only'])).toEqual({
      stripe_direct_debit: true,
      stripe_direct_debit_only: false,
    });
    const env = await joinFormEnv(page);
    expect(env).toMatchObject({ STRIPE_DIRECT_DEBIT: true, STRIPE_DIRECT_DEBIT_ONLY: false });

    expect(await stripePaymentMethods(page)).toEqual(['card', 'bacs_debit']);
  });

  test('without it, Stripe only takes cards', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.save();

    expect(getSettings(['stripe_direct_debit']).stripe_direct_debit).toBe(false);
    expect(await stripePaymentMethods(page)).toEqual(['card']);
  });

  test('Direct Debit only takes card away', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.tab('Integrations');
    await settings.setCheckbox('Enable direct debit through Stripe (must be enabled in your Stripe account)', true);
    await settings.setCheckbox('Direct Debit only, hide credit card option', true);
    await settings.save();

    expect(getSettings(['stripe_direct_debit', 'stripe_direct_debit_only'])).toEqual({
      stripe_direct_debit: true,
      stripe_direct_debit_only: true,
    });
    expect(await joinFormEnv(page)).toMatchObject({ STRIPE_DIRECT_DEBIT_ONLY: true });

    expect(await stripePaymentMethods(page)).toEqual(['bacs_debit']);
  });

  test('Direct Debit only is only offered, and only kept, with direct debit enabled', async ({ settings }) => {
    await settings.open('Integrations');
    const directDebitOnly = settings.field('Direct Debit only, hide credit card option');
    await expect(directDebitOnly).toBeHidden();

    // The seeded site has Direct Debit only stored without direct debit
    // enabled (see scripts/setup.php). Saving the page drops it.
    await settings.save();
    expect(getSettings(['stripe_direct_debit', 'stripe_direct_debit_only'])).toEqual({
      stripe_direct_debit: false,
      stripe_direct_debit_only: false,
    });
  });

  test("Download Subscriptions exports Stripe's subscriptions as a spreadsheet", async ({ settings }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.save();

    await seedFakeStripe('customers', { id: 'cus_export', object: 'customer', email: 'exported@example.org' });
    await seedFakeStripe('products', { id: 'prod_export', object: 'product', name: 'Membership: Standard' });
    await seedFakeStripe('prices', {
      id: 'price_export',
      object: 'price',
      product: 'prod_export',
      currency: 'gbp',
      unit_amount: 500,
      nickname: null,
    });
    await seedFakeStripe('subscriptions', {
      id: 'sub_export',
      object: 'subscription',
      customer: 'cus_export',
      status: 'active',
      created: 1767225600,
      current_period_end: 1769904000,
      items: {
        object: 'list',
        data: [{ id: 'si_export', object: 'subscription_item', price: { id: 'price_export', object: 'price' } }],
      },
    });
    // With no invoices the export breaks (Linear JOIN-167, tested below).
    await seedFakeStripe('invoices', {
      id: 'in_export',
      object: 'invoice',
      subscription: 'sub_export',
      customer: 'cus_export',
      status: 'paid',
      created: 1767312000,
    });

    await settings.tab('Integrations');
    const [download] = await Promise.all([
      settings.page.waitForEvent('download'),
      settings.page.getByRole('link', { name: 'Download Subscriptions' }).click(),
    ]);
    const csv = readFileSync((await download.path()) as string, 'utf8')
      .trim()
      .split('\n');

    expect(csv[0]).toBe(
      '"Email","Customer ID","Subscription ID","Subscription Status","Subscription Created","Subscription End","Price ID","Price Name","First Payment","Last Payment"',
    );
    expect(csv[1]).toMatch(/^"exported@example.org","cus_export","sub_export","active",/);
    expect(csv[1]).toContain(
      '"price_export","Membership: Standard - GBP 5.00","2026-01-02 00:00:00","2026-01-02 00:00:00"',
    );

    // The plugin reads Stripe with the secret key set on this tab.
    const [first] = await fakeApiRequests('api.stripe.com');
    expect(first.headers.authorization).toBe('Bearer sk_test_e2e');
  });

  // An empty Stripe list makes the export print a PHP warning into the
  // spreadsheet (Linear JOIN-167). Re-enable once it's fixed.
  test.fixme('Download Subscriptions works when Stripe has no invoices', async ({ settings }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.save();
    await seedFakeStripe('customers', { id: 'cus_export', object: 'customer', email: 'exported@example.org' });
    await seedFakeStripe('subscriptions', {
      id: 'sub_export',
      object: 'subscription',
      customer: 'cus_export',
      status: 'active',
      created: 1767225600,
      current_period_end: 1769904000,
      items: { object: 'list', data: [] },
    });

    await settings.tab('Integrations');
    const [download] = await Promise.all([
      settings.page.waitForEvent('download'),
      settings.page.getByRole('link', { name: 'Download Subscriptions' }).click(),
    ]);
    const csv = readFileSync((await download.path()) as string, 'utf8')
      .trim()
      .split('\n');
    expect(csv[0]).toMatch(/^"Email",/);
    expect(csv[1]).toMatch(/^"exported@example.org","cus_export","sub_export",/);
  });

  test('Download Subscriptions is for admins only', async ({ page }) => {
    const response = await page.request.get('/wp-json/join/v1/stripe/download-subscriptions');
    expect(response.status()).toBe(401);
  });
});

test.describe('GoCardless', () => {
  const AUTHORISATION_URL = 'https://pay-sandbox.gocardless.example/flow/BRF_E2E';

  /** Makes the fake GoCardless answer the plugin's two calls to start a Direct Debit sign-up. */
  async function fakeGoCardlessSignUp(): Promise<void> {
    await overrideFakeApi({
      method: 'POST',
      path: '^/billing_requests$',
      body: { billing_requests: { id: 'BRQ_E2E', status: 'pending', links: {} } },
    });
    await overrideFakeApi({
      method: 'POST',
      path: '^/billing_request_flows$',
      body: { billing_request_flows: { id: 'BRF_E2E', authorisation_url: AUTHORISATION_URL } },
    });
  }

  /** Goes through the form choosing Direct Debit, and returns where it sent the member. */
  async function payByDirectDebit(page: Page): Promise<string> {
    await page.route('https://pay-sandbox.gocardless.example/**', (route) =>
      route.fulfill({ contentType: 'text/html', body: '<h1>GoCardless</h1>' }),
    );
    await openJoinForm(page);
    await continueToPayment(page);
    await page.waitForURL(AUTHORISATION_URL);
    return page.url();
  }

  test('members are sent to GoCardless, set up with the access token, in the sandbox by default', async ({
    settings,
    page,
  }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use GoCardless');
    await settings.save();

    expect(getSettings(['gc_access_token', 'gc_environment'])).toEqual({
      gc_access_token: 'e2e-gocardless-token',
      gc_environment: 'sandbox',
    });

    await fakeGoCardlessSignUp();
    expect(await payByDirectDebit(page)).toBe(AUTHORISATION_URL);

    const requests = await fakeApiRequests('api-sandbox.gocardless.com');
    expect(requests.map((request) => `${request.method} ${request.path}`)).toEqual([
      'POST /billing_requests',
      'POST /billing_request_flows',
    ]);
    expect(requests[0].headers.authorization).toBe('Bearer e2e-gocardless-token');
    expect(requests[1].body).toMatchObject({ billing_request_flows: { links: { billing_request: 'BRQ_E2E' } } });
    expect(await fakeApiRequests('api.gocardless.com')).toEqual([]);
  });

  test('GoCardless Environment "Live" uses GoCardless\'s live API', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use GoCardless');
    await settings.tab('Integrations');
    await settings.setSelect('GoCardless Environment', 'live');
    await settings.save();

    expect(getSettings(['gc_environment']).gc_environment).toBe('live');

    await fakeGoCardlessSignUp();
    await payByDirectDebit(page);

    expect((await fakeApiRequests('api.gocardless.com')).map((request) => request.path)).toEqual([
      '/billing_requests',
      '/billing_request_flows',
    ]);
    expect(await fakeApiRequests('api-sandbox.gocardless.com')).toEqual([]);
  });
});

test.describe('Chargebee', () => {
  test('Use Chargebee Hosted Pages sends card payments to a Chargebee page, set up with the site and API key', async ({
    settings,
    page,
  }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Chargebee');
    await settings.tab('Integrations');
    await settings.setCheckbox('Use Chargebee Hosted Pages', true);
    await settings.save();

    expect(getSettings(['use_chargebee_hosted_pages', 'chargebee_site_name', 'chargebee_api_key'])).toEqual({
      use_chargebee_hosted_pages: true,
      chargebee_site_name: 'e2e',
      chargebee_api_key: 'e2e-chargebee-key',
    });
    expect(await joinFormEnv(page)).toMatchObject({ USE_CHARGEBEE_HOSTED_PAGES: true });

    const hostedPageUrl = 'https://e2e.chargebee.example/pages/hp_e2e';
    await overrideFakeApi({
      host: 'e2e.chargebee.com',
      method: 'POST',
      path: '/hosted_pages/checkout_new_for_items$',
      body: {
        hosted_page: {
          id: 'hp_e2e',
          type: 'checkout_new',
          url: hostedPageUrl,
          state: 'created',
          object: 'hosted_page',
        },
      },
    });
    await page.route('https://e2e.chargebee.example/**', (route) =>
      route.fulfill({ contentType: 'text/html', body: '<h1>Chargebee</h1>' }),
    );
    await stubChargebee(page);

    await openJoinForm(page);
    await continueToPayment(page);
    await page.waitForURL(hostedPageUrl);

    const [checkout] = await fakeApiRequests('e2e.chargebee.com');
    expect(checkout).toMatchObject({
      method: 'POST',
      path: '/api/v2/hosted_pages/checkout_new_for_items',
      body: { subscription_items: { item_price_id: { 0: 'standard' } } },
    });
    expect(Buffer.from(checkout.headers.authorization.replace(/^Basic /, ''), 'base64').toString()).toBe(
      'e2e-chargebee-key:',
    );
  });

  test('without it, card details are taken in the form', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Chargebee');
    await settings.save();

    expect(await joinFormEnv(page)).toMatchObject({ USE_CHARGEBEE_HOSTED_PAGES: false });

    await stubChargebee(page);
    await openJoinForm(page);
    await continueToPayment(page);
    await expect(page.getByText('Card Number', { exact: true })).toBeVisible();
    expect(page.url()).toContain(STANDARD_JOIN_PAGE);
    expect(await fakeApiRequests('e2e.chargebee.com')).toEqual([]);
  });
});

test.describe('Postcode address lookup', () => {
  const POSTCODE = 'OX1 4PE';

  /** The list of addresses found, which the member picks from. */
  const addressChoice = (page: Page) =>
    page.locator('select', { has: page.locator('option', { hasText: 'Choose your address' }) });

  async function findAddress(page: Page): Promise<void> {
    await openJoinForm(page);
    await page.locator('input[name="postcode"]').fill(POSTCODE);
    await page.getByRole('button', { name: 'Find address' }).click();
  }

  test('is off by default: the address is typed in', async ({ page }) => {
    expect(getSettings(['postcode_address_provider']).postcode_address_provider).toBe('');
    expect(await joinFormEnv(page)).toMatchObject({ USE_POSTCODE_LOOKUP: false });

    await openJoinForm(page);
    await expect(page.getByRole('button', { name: 'Find address' })).toHaveCount(0);
    await expect(page.locator('input#addressLine1')).toBeVisible();
  });

  test('getAddress.io finds addresses with its API key, and fills in the one chosen', async ({ settings, page }) => {
    await settings.open('Integrations');
    await settings.setSelect('Postcode Address Provider', 'get_address_io');
    await settings.setText('getAddress.io API Key', 'e2e-getaddress-key');
    await settings.save();

    expect(getSettings(['postcode_address_provider', 'get_address_io_api_key'])).toEqual({
      postcode_address_provider: 'get_address_io',
      get_address_io_api_key: 'e2e-getaddress-key',
    });
    expect(await joinFormEnv(page)).toMatchObject({
      USE_POSTCODE_LOOKUP: true,
      POSTCODE_ADDRESS_PROVIDER: 'get_address_io',
    });

    await overrideFakeApi({
      host: 'api.getaddress.io',
      path: '^/autocomplete/',
      body: { suggestions: [{ id: 'gA_e2e', address: '1 Lookup Lane, Oxford' }] },
    });
    await overrideFakeApi({
      host: 'api.getaddress.io',
      path: '^/get/gA_e2e$',
      body: { line_1: '1 Lookup Lane', line_2: '', town_or_city: 'Oxford', county: 'Oxfordshire', postcode: 'OX1 4PE' },
    });

    await findAddress(page);
    await addressChoice(page).selectOption('gA_e2e');
    await expect(page.locator('input#addressLine1')).toHaveValue('1 Lookup Lane');
    await expect(page.locator('input#addressCity')).toHaveValue('Oxford');

    const requests = await fakeApiRequests('api.getaddress.io');
    expect(requests.map((request) => request.path)).toEqual(['/autocomplete/OX14PE', '/get/gA_e2e']);
    expect(requests[0].query).toMatchObject({ 'api-key': 'e2e-getaddress-key', all: 'true' });
    expect(requests[1].query).toMatchObject({ 'api-key': 'e2e-getaddress-key' });
  });

  test('Ideal Postcodes finds addresses with its API key', async ({ settings, page }) => {
    await settings.open('Integrations');
    await settings.setSelect('Postcode Address Provider', 'ideal_postcodes');
    await settings.setText('Ideal Postcodes API Key', 'e2e-ideal-key');
    await settings.save();

    expect(getSettings(['postcode_address_provider', 'ideal_postcodes_api_key'])).toEqual({
      postcode_address_provider: 'ideal_postcodes',
      ideal_postcodes_api_key: 'e2e-ideal-key',
    });
    expect(await joinFormEnv(page)).toMatchObject({
      USE_POSTCODE_LOOKUP: true,
      POSTCODE_ADDRESS_PROVIDER: 'ideal_postcodes',
    });

    await overrideFakeApi({
      host: 'api.ideal-postcodes.co.uk',
      path: '^/v1/postcodes/',
      body: {
        result: [
          {
            line_1: '2 Ideal Street',
            line_2: '',
            post_town: 'Oxford',
            county: 'Oxfordshire',
            postcode: 'OX1 4PE',
            country: 'England',
          },
        ],
      },
    });

    await findAddress(page);
    await expect(addressChoice(page).locator('option')).toContainText([
      'Choose your address',
      '2 Ideal Street, OX1 4PE, Oxfordshire, England',
    ]);

    const [lookup] = await fakeApiRequests('api.ideal-postcodes.co.uk');
    expect(lookup).toMatchObject({ path: '/v1/postcodes/OX14PE', query: { api_key: 'e2e-ideal-key' } });
  });

  for (const [provider, value, message] of [
    ['getAddress.io', 'get_address_io', 'Missing getAddress.io API key.'],
    ['ideal-postcodes.co.uk', 'ideal_postcodes', 'Missing Ideal Postcodes key.'],
  ]) {
    test(`${provider} is refused without its API key`, async ({ settings }) => {
      await settings.open('Integrations');
      await settings.setSelect('Postcode Address Provider', value);

      expect(await settings.saveExpectingErrors()).toEqual([message]);
      expect(getSettings(['postcode_address_provider']).postcode_address_provider).toBe('');
    });
  }
});
