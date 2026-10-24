import {
  STANDARD_JOIN_PAGE,
  expect,
  fakeApiRequests,
  getSettings,
  joinFormEnv,
  joinThroughForm,
  loadLegacySettings,
  openJoinForm,
  continueToPlans,
  test,
  wpEvalJson,
} from './fixtures';

/**
 * Settings page, Integrations tab: the CRMs (Zetkin, Mailchimp, Action
 * Network), Auth0 and the webhooks.
 *
 * Each test turns the service on, joins through the real join form, and
 * checks what the plugin sent to the service's fake (see fake-api/server.js):
 * where it went, the credentials it carried, and the member's details.
 */

/** The join form's test data, which every join here uses. */
const MEMBER = { email: 'someone@example.com', firstName: 'Test', lastName: 'Person' };

/** Today's date as the plugin writes it in the container, e.g. 2026-10-24. */
const today = () => wpEvalJson<string>('return date("Y-m-d");');

/** Joins through the form, and returns the dates it could have been joined on (it may span midnight). */
async function joinAndNoteDates(page: Parameters<typeof joinThroughForm>[0]): Promise<string[]> {
  const before = today();
  await joinThroughForm(page);
  return [before, today()];
}

test.describe('Zetkin', () => {
  test('a join is sent to the Zetkin join form, with the organisation, form and token set here', async ({
    settings,
    page,
  }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Zetkin');
    await settings.tab('Integrations');
    await settings.setText('Zetkin Membership Tier Custom Field', 'membership_tier');
    await settings.setText('Zetkin Join Date Custom Field', 'joined_on');
    await settings.save();

    expect(
      getSettings([
        'zetkin_organisation_id',
        'zetkin_join_form_id',
        'zetkin_join_form_submit_token',
        'zetkin_membership_custom_field',
        'zetkin_join_date_custom_field',
        'zetkin_environment',
      ]),
    ).toEqual({
      zetkin_organisation_id: '1',
      zetkin_join_form_id: '2',
      zetkin_join_form_submit_token: 'e2e-zetkin-token',
      zetkin_membership_custom_field: 'membership_tier',
      zetkin_join_date_custom_field: 'joined_on',
      zetkin_environment: 'sandbox',
    });

    const joinDates = await joinAndNoteDates(page);

    // Without OAuth credentials, the plugin submits Zetkin's join form. The
    // sandbox environment (the default) is Zetkin's development server.
    const [submission] = await fakeApiRequests('api.dev.zetkin.org');
    expect(submission).toMatchObject({
      method: 'POST',
      path: '/v1/orgs/1/join_forms/2/submissions',
      body: {
        submit_token: 'e2e-zetkin-token',
        form_data: {
          email: MEMBER.email,
          first_name: MEMBER.firstName,
          last_name: MEMBER.lastName,
          membership_tier: 'Standard',
        },
      },
    });
    expect(joinDates).toContain(submission.body.form_data.joined_on);
    expect(await fakeApiRequests('api.zetk.in')).toEqual([]);
  });

  test('without the custom fields, the plan and join date are not sent', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Zetkin');
    await settings.save();

    const joinDates = await joinAndNoteDates(page);

    const [submission] = await fakeApiRequests('api.dev.zetkin.org');
    const sent = Object.values(submission.body.form_data);
    expect(sent).not.toContain('Standard');
    for (const date of joinDates) expect(sent).not.toContain(date);
  });

  test('Zetkin Environment "Live" sends joins to Zetkin\'s live server', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Zetkin');
    await settings.tab('Integrations');
    await settings.setSelect('Zetkin Environment', 'live');
    await settings.save();

    expect(getSettings(['zetkin_environment']).zetkin_environment).toBe('live');

    await joinThroughForm(page);

    expect((await fakeApiRequests('api.zetk.in')).map((request) => request.path)).toEqual([
      '/v1/orgs/1/join_forms/2/submissions',
    ]);
    expect(await fakeApiRequests('api.dev.zetkin.org')).toEqual([]);
  });

  test('with the OAuth credentials, the member is added through Zetkin\'s API and tagged', async ({
    settings,
    page,
  }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Zetkin');
    await settings.tab('Integrations');
    await settings.setText('Zetkin OAuth Client ID', 'e2e-zetkin-client');
    await settings.setText('Zetkin OAuth Client Secret', 'e2e-zetkin-secret');
    await settings.setText('Zetkin OAuth JWT', 'e2e-zetkin-jwt');
    await settings.save();

    await joinThroughForm(page);

    const requests = await fakeApiRequests('api.dev.zetkin.org');
    const [token] = requests;
    expect(token).toMatchObject({
      method: 'POST',
      path: '/v1/oauth/token',
      body: { assertion: 'e2e-zetkin-jwt', grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer' },
    });
    expect(token.headers.authorization).toBe(
      `Basic ${Buffer.from('e2e-zetkin-client:e2e-zetkin-secret').toString('base64')}`,
    );

    const person = requests.find((request) => request.method === 'POST' && request.path === '/v1/orgs/1/people');
    expect(person?.headers.authorization).toBe('Bearer e2e-zetkin-access-token');
    expect(person?.body).toMatchObject({ email: MEMBER.email, first_name: MEMBER.firstName });

    // Every new member is tagged Unconfirmed: the tag is created (the fake
    // numbers tags 1, 2, ... in the order they're created), then put on the
    // person the fake created (4242). The join form was not used.
    const createdTags = requests
      .filter((request) => request.method === 'POST' && request.path === '/v1/orgs/1/people/tags')
      .map((request) => request.body.title);
    const unconfirmedId = createdTags.indexOf('Unconfirmed') + 1;
    expect(unconfirmedId).toBeGreaterThan(0);
    expect(requests.filter((request) => request.method === 'PUT').map((request) => request.path)).toContain(
      `/v1/orgs/1/people/4242/tags/${unconfirmedId}`,
    );
    expect(requests.some((request) => request.path.includes('/join_forms/'))).toBe(false);
  });
});

test.describe('Mailchimp', () => {
  test('a join adds the member to the audience set here, with the API key set here', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Mailchimp');
    await settings.save();

    expect(getSettings(['mailchimp_api_key', 'mailchimp_audience_id'])).toEqual({
      mailchimp_api_key: 'e2e-mailchimp-key-us1',
      mailchimp_audience_id: 'e2e-audience',
    });

    await joinThroughForm(page);

    // The API key's suffix (us1) names the Mailchimp server to use.
    const signup = (await fakeApiRequests('us1.api.mailchimp.com')).find(
      (request) => request.method === 'POST' && request.path === '/3.0/lists/e2e-audience/members',
    );
    expect(signup?.body).toMatchObject({
      email_address: MEMBER.email,
      status: 'subscribed',
      merge_fields: { FNAME: MEMBER.firstName, LNAME: MEMBER.lastName },
    });
    expect(Buffer.from(signup!.headers.authorization.replace(/^Basic /, ''), 'base64').toString()).toMatch(
      /:e2e-mailchimp-key-us1$/,
    );
  });
});

test.describe('Action Network', () => {
  test('a join adds the member to Action Network, with the API key set here, and updates their tags', async ({
    settings,
    page,
  }) => {
    // Another plan's tags, which a member on the form's own plan shouldn't have.
    await settings.open('Membership Plans');
    await settings.editPlan(0, { addTags: 'Member' });
    await settings.tab('Features');
    await settings.enableIntegration('Use Action Network');
    await settings.save();

    expect(getSettings(['action_network_api_key']).action_network_api_key).toBe('e2e-action-network-key');

    await joinThroughForm(page);

    const signup = (await fakeApiRequests('actionnetwork.org')).find(
      (request) => request.method === 'POST' && request.path === '/api/v2/people/',
    );
    expect(signup?.headers['osdi-api-token']).toBe('e2e-action-network-key');
    expect(signup?.body).toMatchObject({
      person: {
        email_addresses: [expect.objectContaining({ address: MEMBER.email })],
        given_name: MEMBER.firstName,
        family_name: MEMBER.lastName,
      },
      // The other plan's tags, and the lapsed, cancelled and lapsing tags, come off.
      remove_tags: expect.arrayContaining(['Member', 'Lapsed - failed payment', 'Cancelled', 'Lapsing']),
    });
  });

  // The plugin sends [""] instead of no tags (Linear JOIN-171). Re-enable once it's fixed.
  test.fixme('a join on a plan without Add Tags sends Action Network no empty tag', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Use Action Network');
    await settings.save();

    // The form's own plan has no Add Tags.
    await joinThroughForm(page);

    const signup = (await fakeApiRequests('actionnetwork.org')).find(
      (request) => request.method === 'POST' && request.path === '/api/v2/people/',
    );
    expect(signup?.body.add_tags).toEqual([]);
  });
});

test.describe('Webhooks', () => {
  // The fake API answers webhooks too: inside the Docker network it is
  // reachable as http://fake-api.
  const JOIN_WEBHOOK = 'http://fake-api/join-complete';
  const STEP_WEBHOOK = 'http://fake-api/step';

  test('Join Complete Webhook URL is sent the join, but the form only ever sees an ID for it', async ({
    settings,
    page,
  }) => {
    await settings.open('Integrations');
    await settings.setText('Join Complete Webhook URL', JOIN_WEBHOOK);
    await settings.save();

    expect(getSettings(['webhook_url']).webhook_url).toBe(JOIN_WEBHOOK);

    const env = await joinFormEnv(page, STANDARD_JOIN_PAGE);
    expect(env.WEBHOOK_UUID).toMatch(/^[0-9a-f-]{36}$/);
    expect(JSON.stringify(env)).not.toContain('join-complete');

    const join = await joinThroughForm(page);
    expect(join.webhookUuid).toBe(env.WEBHOOK_UUID);

    const [delivery] = await fakeApiRequests('fake-api');
    expect(delivery).toMatchObject({
      method: 'POST',
      path: '/join-complete',
      body: { email: MEMBER.email, firstName: MEMBER.firstName, membership: 'standard' },
    });
    // Bank details are never passed on.
    expect(delivery.body).not.toHaveProperty('ddAccountNumber');
    expect(delivery.body).not.toHaveProperty('ddSortCode');
  });

  test('with no Join Complete Webhook URL, nothing is sent', async ({ page }) => {
    expect((await joinFormEnv(page, STANDARD_JOIN_PAGE)).WEBHOOK_UUID).toBe('');
    await joinThroughForm(page);
    expect(await fakeApiRequests('fake-api')).toEqual([]);
  });

  test('Step Webhook Url is sent the form\'s data after each step', async ({ settings, page }) => {
    await settings.open('Integrations');
    await settings.setText('Step Webhook Url', STEP_WEBHOOK);
    await settings.save();

    expect(getSettings(['step_webhook_url']).step_webhook_url).toBe(STEP_WEBHOOK);

    // The form reports each step to the plugin's /step endpoint: let that
    // request through to the plugin, rather than the usual stub.
    await openJoinForm(page);
    await page.route('**/wp-json/join/v1/step', (route) => route.continue());
    await continueToPlans(page);

    await expect.poll(async () => (await fakeApiRequests('fake-api')).length).toBeGreaterThan(0);
    const [delivery] = await fakeApiRequests('fake-api');
    expect(delivery).toMatchObject({
      method: 'POST',
      path: '/step',
      body: { email: MEMBER.email, firstName: MEMBER.firstName, stage: 'enter-details' },
    });
    // The form's test data includes bank details; they're never passed on.
    expect(delivery.body).not.toHaveProperty('ddAccountNumber');
    expect(delivery.body).not.toHaveProperty('ddSortCode');
  });
});

test.describe('Auth0', () => {
  test('a join creates the member\'s Auth0 account, with the credentials set here', async ({ settings, page }) => {
    await settings.open('Features');
    await settings.enableIntegration('Create Auth0 Account');
    await settings.save();

    expect(
      getSettings(['auth0_domain', 'auth0_client_id', 'auth0_client_secret', 'auth0_management_audience']),
    ).toEqual({
      auth0_domain: 'e2e.eu.auth0.com',
      auth0_client_id: 'e2e-auth0-client-id',
      auth0_client_secret: 'e2e-auth0-client-secret',
      auth0_management_audience: 'https://e2e.eu.auth0.com/api/v2/',
    });

    await joinThroughForm(page, STANDARD_JOIN_PAGE, async (form) => {
      await form.locator('input#password').fill('Str0ng!password');
    });

    const requests = await fakeApiRequests('e2e.eu.auth0.com');
    expect(requests.find((request) => request.path === '/oauth/token')?.body).toMatchObject({
      grant_type: 'client_credentials',
      client_id: 'e2e-auth0-client-id',
      client_secret: 'e2e-auth0-client-secret',
      audience: 'https://e2e.eu.auth0.com/api/v2/',
    });
    const created = requests.find((request) => request.method === 'POST' && request.path === '/api/v2/users');
    expect(created?.headers.authorization).toBe('Bearer e2e-auth0-access-token');
    expect(created?.body).toMatchObject({
      email: MEMBER.email,
      password: 'Str0ng!password',
      connection: 'Username-Password-Authentication',
    });
  });
});

test.describe('Integrations saved before the settings page was rebuilt', () => {
  // See tests/settings/legacy/README.md. The payment providers' settings are
  // checked here as read; their effects are covered in the other specs.
  test.beforeEach(() => loadLegacySettings('integrations'));

  test('are still read', () => {
    expect(
      getSettings([
        'zetkin_organisation_id',
        'zetkin_join_form_id',
        'zetkin_join_form_submit_token',
        'zetkin_membership_custom_field',
        'zetkin_join_date_custom_field',
        'zetkin_environment',
        'use_chargebee_hosted_pages',
        'chargebee_site_name',
        'chargebee_api_key',
        'chargebee_api_publishable_key',
        'gc_access_token',
        'gc_environment',
        'stripe_publishable_key',
        'stripe_secret_key',
        'stripe_direct_debit',
        'stripe_direct_debit_only',
        'postcode_address_provider',
        'ideal_postcodes_api_key',
        'get_address_io_api_key',
        'mailchimp_api_key',
        'mailchimp_audience_id',
        'action_network_api_key',
        'step_webhook_url',
        'webhook_url',
        'auth0_domain',
        'auth0_client_id',
        'auth0_client_secret',
        'auth0_management_audience',
        'sentry_dsn',
        'loggly_token',
        'loggly_tags',
      ]),
    ).toEqual({
      zetkin_organisation_id: '11',
      zetkin_join_form_id: '22',
      zetkin_join_form_submit_token: 'legacy-zetkin-token',
      zetkin_membership_custom_field: 'legacy_tier',
      zetkin_join_date_custom_field: 'legacy_joined',
      zetkin_environment: 'live',
      use_chargebee_hosted_pages: true,
      chargebee_site_name: 'legacy',
      chargebee_api_key: 'legacy-chargebee-key',
      chargebee_api_publishable_key: 'legacy-chargebee-publishable-key',
      gc_access_token: 'legacy-gocardless-token',
      gc_environment: 'live',
      stripe_publishable_key: 'pk_test_legacy',
      stripe_secret_key: 'sk_test_legacy',
      stripe_direct_debit: true,
      stripe_direct_debit_only: true,
      postcode_address_provider: 'get_address_io',
      ideal_postcodes_api_key: 'legacy-ideal-key',
      get_address_io_api_key: 'legacy-getaddress-key',
      mailchimp_api_key: 'legacy-mailchimp-key-us1',
      mailchimp_audience_id: 'legacy-audience',
      action_network_api_key: 'legacy-action-network-key',
      step_webhook_url: 'http://fake-api/legacy-step',
      webhook_url: 'http://fake-api/legacy-join-complete',
      auth0_domain: 'legacy.eu.auth0.com',
      auth0_client_id: 'legacy-auth0-client-id',
      auth0_client_secret: 'legacy-auth0-client-secret',
      auth0_management_audience: 'https://legacy.eu.auth0.com/api/v2/',
      sentry_dsn: 'https://legacykey@sentry.e2e.example/7',
      loggly_token: 'legacy-loggly-token',
      loggly_tags: 'legacy-site, join',
    });
  });

  test('a join still reaches Zetkin, Mailchimp, Action Network and the webhook as set up', async ({ page }) => {
    expect(await joinFormEnv(page, STANDARD_JOIN_PAGE)).toMatchObject({
      SENTRY_DSN: 'https://legacykey@sentry.e2e.example/7',
      POSTCODE_ADDRESS_PROVIDER: 'get_address_io',
      USE_POSTCODE_LOOKUP: true,
    });

    const joinDates = await joinAndNoteDates(page);

    const [zetkin] = await fakeApiRequests('api.zetk.in');
    expect(zetkin).toMatchObject({
      path: '/v1/orgs/11/join_forms/22/submissions',
      body: { submit_token: 'legacy-zetkin-token', form_data: { legacy_tier: 'Standard' } },
    });
    expect(joinDates).toContain(zetkin.body.form_data.legacy_joined);
    expect(
      (await fakeApiRequests('us1.api.mailchimp.com')).some(
        (request) => request.path === '/3.0/lists/legacy-audience/members',
      ),
    ).toBe(true);
    const [actionNetwork] = await fakeApiRequests('actionnetwork.org');
    expect(actionNetwork.headers['osdi-api-token']).toBe('legacy-action-network-key');
    expect((await fakeApiRequests('fake-api')).map((request) => request.path)).toContain('/legacy-join-complete');
    // Logging reads its settings on its own path, early in each request.
    const [loggly] = await fakeApiRequests('logs-01.loggly.com');
    expect(loggly).toMatchObject({ path: '/bulk/legacy-loggly-token/' });
    expect(loggly.headers['x-loggly-tag']).toBe('legacy-site,join');
  });
});
