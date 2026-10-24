import { Page } from '@playwright/test';
import { createHash } from 'crypto';
import { CONTINUE } from '../helpers';
import {
  GLOBAL_PLANS_PAGE,
  JoinSettingsPage,
  MEMBER_EMAIL,
  continueToPlans,
  expect,
  fakeApiRequests,
  getSettings,
  joinFormEnv,
  loadLegacySettings,
  openJoinForm,
  postJoin,
  postStripeWebhook,
  seedFakeStripe,
  test,
  wpEvalJson,
} from './fixtures';

/**
 * Settings page, Membership Plans tab: the global membership plans, and the
 * tags members get in Action Network, Mailchimp and Zetkin when their
 * membership lapses, is cancelled, or starts failing.
 *
 * Global plans are offered by join forms that have no plans of their own
 * (GLOBAL_PLANS_PAGE). On save, each plan is also stored on its own, under
 * its ID, which is how the rest of the plugin finds a member's plan when they
 * join, and set up in Stripe when Stripe is on.
 *
 * The tag effects are checked in the requests the plugin makes to a fake
 * Mailchimp (the three CRMs share the tag logic, see JoinService).
 */

const MAILCHIMP = 'us1.api.mailchimp.com';
/** Mailchimp addresses a member by the MD5 of their lower-cased email. */
const MEMBER_TAGS_PATH = `/3.0/lists/e2e-audience/members/${createHash('md5').update(MEMBER_EMAIL.toLowerCase()).digest('hex')}/tags`;

/** Plans as stored under their IDs (ck_join_flow_membership_plan_<id>), false for none, in one WP-CLI call. */
const storedPlans = (ids: string[]) =>
  wpEvalJson<Record<string, Record<string, any> | false>>(`
    $plans = [];
    foreach (json_decode(${JSON.stringify(JSON.stringify(ids))}) as $id) {
      $plans[$id] = get_option('ck_join_flow_membership_plan_' . $id);
    }
    return $plans;
  `);

/** A plan as stored under its ID, or false. */
const storedPlan = (id: string) => storedPlans([id])[id];

/** The plans the join form on GLOBAL_PLANS_PAGE is given. */
const formPlans = async (page: Page) => (await joinFormEnv(page, GLOBAL_PLANS_PAGE)).MEMBERSHIP_PLANS;

/** Opens the join form on GLOBAL_PLANS_PAGE and moves on to choosing a plan. */
async function openPlanStep(page: Page): Promise<void> {
  await openJoinForm(page, GLOBAL_PLANS_PAGE);
  await continueToPlans(page);
}

/** A plan's panel on the join form's plan step. */
const planPanel = (page: Page, label: string) =>
  page.locator('label.radio-panel', { has: page.locator(`[id="membership-${label}"]`) });

/** The tag changes the plugin has sent to the fake Mailchimp, in order, e.g. ['Lapsing: active']. */
async function mailchimpTagChanges(): Promise<string[]> {
  return (await fakeApiRequests(MAILCHIMP))
    .filter((request) => request.method === 'POST' && request.path === MEMBER_TAGS_PATH)
    .flatMap((request) => request.body.tags.map((tag: { name: string; status: string }) => `${tag.name}: ${tag.status}`));
}

test.describe('Global membership plans', () => {
  test('a new plan is saved, stored under its ID, and offered on join forms without plans of their own', async ({
    settings,
    page,
  }) => {
    await settings.open('Membership Plans');
    await settings.addPlan({
      name: 'Supporter',
      price: '12',
      frequency: 'yearly',
      currency: 'EUR',
      description: 'For people who back the union',
      addTags: 'Supporter, Yearly',
    });
    await settings.save();

    const plans = getSettings(['membership_plans']).membership_plans as Record<string, any>[];
    expect(plans.map((plan) => plan.label)).toEqual(['Baseline Plan', 'Supporter']);
    expect(plans[1]).toMatchObject({
      label: 'Supporter',
      id: '',
      amount: '12',
      allow_custom_amount: false,
      frequency: 'yearly',
      currency: 'EUR',
      description: 'For people who back the union',
      add_tags: 'Supporter, Yearly',
    });
    // With no Price Point ID, the ID is made from the name, frequency and currency.
    const stored = storedPlans(['supporter_yearly_eur', 'baseline-plan_monthly_gbp']);
    expect(stored['supporter_yearly_eur']).toMatchObject({ label: 'Supporter', amount: '12', currency: 'EUR' });
    expect(stored['baseline-plan_monthly_gbp']).toMatchObject({ label: 'Baseline Plan', amount: '7' });

    expect(await formPlans(page)).toEqual([
      expect.objectContaining({ value: 'baseline-plan_monthly_gbp', label: 'Baseline Plan' }),
      {
        value: 'supporter_yearly_eur',
        label: 'Supporter',
        allowCustomAmount: false,
        amount: '12',
        currency: 'EUR',
        frequency: 'yearly',
        description: 'For people who back the union',
        redirectUrl: null,
      },
    ]);

    await openPlanStep(page);
    const supporter = planPanel(page, 'Supporter');
    await expect(supporter).toContainText('€12, yearly');
    await expect(supporter).toContainText('For people who back the union');
    await expect(planPanel(page, 'Baseline Plan')).toContainText('£7, monthly');
    await supporter.click();
    await expect(page.locator(CONTINUE)).toHaveText('Continue and pay €12 yearly');
  });
});

test.describe('Membership plan fields', () => {
  test('Price Point ID becomes the plan\'s ID', async ({ settings, page, request }) => {
    await settings.open('Membership Plans');
    await settings.addPlan({ name: 'Gold', id: 'gold-tier', price: '20' });
    await settings.save();

    const stored = storedPlans(['gold-tier', 'gold_monthly_gbp']);
    expect(stored['gold-tier']).toMatchObject({ label: 'Gold', id: 'gold-tier' });
    expect(stored['gold_monthly_gbp']).toBe(false);
    expect((await formPlans(page)).map((plan: any) => plan.value)).toContain('gold-tier');

    // Joins name the plan by this ID.
    expect((await postJoin(request, { membership: 'gold-tier' })).ok()).toBe(true);
  });

  test('Frequency and Currency set how the price is shown', async ({ settings, page }) => {
    await settings.open('Membership Plans');
    await settings.addPlan({ name: 'Weekly', price: '2', frequency: 'weekly', currency: 'GBP' });
    await settings.addPlan({ name: 'Dollars', price: '9', frequency: 'monthly', currency: 'USD' });
    await settings.save();

    await openPlanStep(page);
    await expect(planPanel(page, 'Weekly')).toContainText('£2, weekly');
    await expect(planPanel(page, 'Dollars')).toContainText('$9, monthly');
  });

  test('a new plan is monthly and in GBP unless changed', async ({ settings }) => {
    await settings.open('Membership Plans');
    await settings.addPlan({ name: 'Defaults', price: '4' });
    await settings.save();

    expect(storedPlan('defaults_monthly_gbp')).toMatchObject({ frequency: 'monthly', currency: 'GBP' });
  });

  test('Allow users to change the amount lets members pay more than the price, but not less', async ({
    settings,
    page,
    request,
  }) => {
    await settings.open('Membership Plans');
    await settings.editPlan(0, { allowCustomAmount: true });
    await settings.save();

    expect(storedPlan('baseline-plan_monthly_gbp')).toMatchObject({ allow_custom_amount: true });

    await openPlanStep(page);
    const amount = page.locator('input[id="baseline-plan_monthly_gbp-amount"]');
    await expect(amount).toHaveAttribute('min', '7');
    await amount.fill('15');
    await expect(page.locator(CONTINUE)).toHaveText('Continue and pay £15 monthly');

    // The plugin enforces the minimum too, not just the form.
    expect((await postJoin(request, { membership: 'baseline-plan_monthly_gbp', customMembershipAmount: 5 })).status()).toBe(500);
    expect((await postJoin(request, { membership: 'baseline-plan_monthly_gbp', customMembershipAmount: 15 })).ok()).toBe(true);
  });

  test('without it, the price is fixed', async ({ page }) => {
    await openPlanStep(page);
    await expect(page.locator('input[id="baseline-plan_monthly_gbp-amount"]')).toHaveCount(0);
    await expect(planPanel(page, 'Baseline Plan')).toContainText('£7, monthly');
  });
});

test.describe('Changing the plan list', () => {
  test('a removed plan is no longer offered', async ({ settings, page, request }) => {
    await settings.open('Membership Plans');
    await settings.addPlan({ name: 'Replacement', price: '8' });
    await settings.save();
    expect(storedPlan('baseline-plan_monthly_gbp')).toMatchObject({ label: 'Baseline Plan' });

    await settings.tab('Membership Plans');
    await settings.removePlan(0);
    await settings.save();

    expect((getSettings(['membership_plans']).membership_plans as any[]).map((plan) => plan.label)).toEqual([
      'Replacement',
    ]);
    expect((await formPlans(page)).map((plan: any) => plan.label)).toEqual(['Replacement']);

    // Its stored copy is kept, so a join form that was opened before the plan
    // was removed can still be completed. This pins today's behaviour, which
    // also leaves the plan joinable for good: whether it should is Linear
    // JOIN-170.
    expect(storedPlan('baseline-plan_monthly_gbp')).toMatchObject({ label: 'Baseline Plan' });
    expect((await postJoin(request, { membership: 'baseline-plan_monthly_gbp' })).ok()).toBe(true);
  });

  test('a changed price reaches the join form and the stored plan', async ({ settings, page }) => {
    await settings.open('Membership Plans');
    await settings.editPlan(0, { price: '9' });
    await settings.save();

    expect(storedPlan('baseline-plan_monthly_gbp')).toMatchObject({ amount: '9' });
    await openPlanStep(page);
    await expect(planPanel(page, 'Baseline Plan')).toContainText('£9, monthly');
  });

  test('the page will not save without at least one plan, and nothing is saved', async ({ settings }) => {
    await settings.open('Membership Plans');
    await settings.removePlan(0);
    await settings.setText('Lapsed Tag', 'Not saved');

    expect(await settings.saveExpectingErrors()).toEqual(['You must add at least one membership plan.']);
    expect(getSettings(['lapsed_tag']).lapsed_tag).toBe('Lapsed - failed payment');
    expect((getSettings(['membership_plans']).membership_plans as any[]).map((plan) => plan.label)).toEqual([
      'Baseline Plan',
    ]);
  });

  for (const [missing, plan] of [
    ['Name', { price: '5' }],
    ['Price', { name: 'No Price' }],
  ] as const) {
    test(`a plan without a ${missing} cannot be saved`, async ({ settings }) => {
      await settings.open('Membership Plans');
      await settings.addPlan(plan);
      await settings.expectSaveRefused();

      expect((getSettings(['membership_plans']).membership_plans as any[]).map((saved) => saved.label)).toEqual([
        'Baseline Plan',
      ]);
    });
  }

  test('a join naming a plan that was never saved is refused', async ({ request }) => {
    // The same join on a stored plan (the seeded join form's) goes through,
    // so the refusal is down to the plan.
    expect((await postJoin(request, { membership: 'standard' })).ok()).toBe(true);
    expect((await postJoin(request, { membership: 'no-such-plan' })).status()).toBe(500);
  });

  // Only the page checks a plan has a Name and Price; the plugin saves one
  // without (Linear JOIN-169). Re-enable once it's fixed.
  test.fixme('the plugin refuses a plan without a Name, even when the page\'s own checks are skipped', async ({
    settings,
  }) => {
    await settings.open('Membership Plans');
    await settings.addPlan({ price: '5' });

    expect(await settings.saveSkippingPageChecksExpectingErrors()).not.toEqual([]);
    expect((getSettings(['membership_plans']).membership_plans as any[]).map((saved) => saved.label)).toEqual([
      'Baseline Plan',
    ]);
  });
});

test.describe('Global plans in Stripe', () => {
  test('each plan gets a Stripe product, and a recurring price in its currency, amount and frequency', async ({
    settings,
  }) => {
    await settings.open('Membership Plans');
    await settings.editPlan(0, { frequency: 'weekly', price: '2' });
    await settings.addPlan({ name: 'Dollars', price: '9.50', currency: 'USD', description: 'Paid in dollars' });
    await settings.addPlan({ name: 'Euros', price: '60', frequency: 'yearly', currency: 'EUR' });
    await settings.enableIntegration('Use Stripe');
    await settings.save();

    const created = (await fakeApiRequests('api.stripe.com')).filter((request) => request.method === 'POST');
    const products = created.filter((request) => request.path === '/v1/products').map((request) => request.body);
    const prices = created.filter((request) => request.path === '/v1/prices').map((request) => request.body);

    expect(products).toEqual([
      expect.objectContaining({ name: 'Membership: Baseline Plan', metadata: { membership_plan: 'baseline-plan_weekly_gbp' } }),
      expect.objectContaining({
        name: 'Membership: Dollars',
        description: 'Paid in dollars',
        metadata: { membership_plan: 'dollars_monthly_usd' },
      }),
      expect.objectContaining({ name: 'Membership: Euros', metadata: { membership_plan: 'euros_yearly_eur' } }),
    ]);
    expect(products[0]).not.toHaveProperty('description');
    expect(prices).toEqual([
      expect.objectContaining({ unit_amount: '200', currency: 'GBP', recurring: { interval: 'week' } }),
      expect.objectContaining({ unit_amount: '950', currency: 'USD', recurring: { interval: 'month' } }),
      expect.objectContaining({ unit_amount: '6000', currency: 'EUR', recurring: { interval: 'year' } }),
    ]);

    // Each stored plan remembers its Stripe product and price.
    const stored = storedPlans(['baseline-plan_weekly_gbp', 'dollars_monthly_usd', 'euros_yearly_eur']);
    for (const plan of Object.values(stored)) {
      expect(plan).toMatchObject({
        stripe_product_id: expect.stringMatching(/^prod_/),
        stripe_price_id: expect.stringMatching(/^price_/),
      });
    }
  });

  test('a changed price gets a new Stripe price on the same product', async ({ settings }) => {
    await settings.open('Membership Plans');
    await settings.enableIntegration('Use Stripe');
    await settings.save();
    const before = storedPlan('baseline-plan_monthly_gbp') as Record<string, any>;

    await settings.tab('Membership Plans');
    await settings.editPlan(0, { price: '8' });
    await settings.save();

    const after = storedPlan('baseline-plan_monthly_gbp') as Record<string, any>;
    expect(after.stripe_product_id).toBe(before.stripe_product_id);
    expect(after.stripe_price_id).not.toBe(before.stripe_price_id);
    const prices = (await fakeApiRequests('api.stripe.com')).filter(
      (request) => request.method === 'POST' && request.path === '/v1/prices',
    );
    expect(prices.map((request) => request.body.unit_amount)).toEqual(['700', '800']);
  });

  // Each save makes another Stripe price for a plan whose price has pence in
  // it (Linear JOIN-166). Re-enable once it's fixed.
  test.fixme('saving again reuses the Stripe price of a plan priced in pence', async ({ settings }) => {
    await settings.open('Membership Plans');
    await settings.editPlan(0, { price: '9.50' });
    await settings.enableIntegration('Use Stripe');
    await settings.save();
    await settings.save();

    const prices = (await fakeApiRequests('api.stripe.com')).filter(
      (request) => request.method === 'POST' && request.path === '/v1/prices',
    );
    expect(prices.map((request) => request.body.unit_amount)).toEqual(['950']);
  });
});

test.describe('Member tags', () => {
  const CUSTOMER = { id: 'cus_member', object: 'customer', email: MEMBER_EMAIL };

  /** Turns on Stripe (whose webhooks report lapses) and Mailchimp, and sets the three tags. Does not save. */
  async function setUpTagging(
    settings: JoinSettingsPage,
    tags: { lapsed?: string; cancelled?: string; lapsing?: string } = {},
  ): Promise<void> {
    await settings.open('Features');
    await settings.enableIntegration('Use Stripe');
    await settings.enableIntegration('Use Mailchimp');
    await settings.tab('Membership Plans');
    await settings.setText('Lapsed Tag', tags.lapsed ?? 'Lapsed');
    await settings.setText('Cancelled Tag', tags.cancelled ?? 'Left');
    await settings.setText('Lapsing Tag', tags.lapsing ?? 'Payment trouble');
  }

  const subscriptionDeleted = (reason: string) => ({
    object: { id: 'sub_old', object: 'subscription', customer: CUSTOMER.id, status: 'canceled', cancellation_details: { reason } },
  });

  test('have defaults', () => {
    expect(getSettings(['lapsed_tag', 'cancelled_tag', 'lapsing_tag'])).toEqual({
      lapsed_tag: 'Lapsed - failed payment',
      cancelled_tag: 'Cancelled',
      lapsing_tag: 'Lapsing',
    });
  });

  test('joining adds the plan\'s Add Tags, and takes off the other plans\' tags and the lapsed, cancelled and lapsing tags', async ({
    settings,
    request,
  }) => {
    await setUpTagging(settings);
    await settings.editPlan(0, { addTags: 'Member, Monthly' });
    await settings.addPlan({ name: 'Other', price: '3', addTags: 'Other Tier' });
    await settings.tab('Features');
    // Stripe would want a paid subscription to check the join against.
    await settings.setCheckbox('Use Stripe', false);
    await settings.save();

    expect(getSettings(['lapsed_tag', 'cancelled_tag', 'lapsing_tag'])).toEqual({
      lapsed_tag: 'Lapsed',
      cancelled_tag: 'Left',
      lapsing_tag: 'Payment trouble',
    });

    expect((await postJoin(request, { membership: 'baseline-plan_monthly_gbp' })).ok()).toBe(true);

    const signup = (await fakeApiRequests(MAILCHIMP)).find(
      (request) => request.method === 'POST' && request.path === '/3.0/lists/e2e-audience/members',
    );
    expect(signup?.body).toMatchObject({ email_address: MEMBER_EMAIL, tags: ['Member', 'Monthly'] });
    expect(await mailchimpTagChanges()).toEqual([
      'Lapsed: inactive',
      'Left: inactive',
      'Payment trouble: inactive',
      'Other Tier: inactive',
    ]);
  });

  test('a subscription ended by failed payments gets the Lapsed Tag', async ({ settings, request }) => {
    await setUpTagging(settings);
    await settings.save();
    await seedFakeStripe('customers', CUSTOMER);

    await postStripeWebhook(request, 'customer.subscription.deleted', subscriptionDeleted('payment_failed'));

    expect(await mailchimpTagChanges()).toEqual(['Lapsed: active', 'Left: inactive', 'Payment trouble: inactive']);
  });

  test('a member with another active subscription is not tagged when one ends', async ({ settings, request }) => {
    await setUpTagging(settings);
    await settings.save();
    await seedFakeStripe('customers', CUSTOMER);
    // For example the replacement /join creates when a member changes plan.
    await seedFakeStripe('subscriptions', { id: 'sub_new', object: 'subscription', customer: CUSTOMER.id, status: 'active' });

    await postStripeWebhook(request, 'customer.subscription.deleted', subscriptionDeleted('cancellation_requested'));

    // It did look, so it's the other subscription that stopped the tagging.
    expect((await fakeApiRequests('api.stripe.com')).some((request) => request.path === '/v1/subscriptions')).toBe(true);
    expect(await mailchimpTagChanges()).toEqual([]);
  });

  test('a subscription the member cancelled gets the Cancelled Tag', async ({ settings, request }) => {
    await setUpTagging(settings);
    await settings.save();
    await seedFakeStripe('customers', CUSTOMER);

    await postStripeWebhook(request, 'customer.subscription.deleted', subscriptionDeleted('cancellation_requested'));

    expect(await mailchimpTagChanges()).toEqual(['Left: active', 'Lapsed: inactive', 'Payment trouble: inactive']);
  });

  test('a failed payment that Stripe will retry gets the Lapsing Tag', async ({ settings, request }) => {
    await setUpTagging(settings);
    await settings.save();
    await seedFakeStripe('customers', CUSTOMER);

    await postStripeWebhook(request, 'invoice.payment_failed', {
      object: {
        id: 'in_1',
        object: 'invoice',
        customer: CUSTOMER.id,
        billing_reason: 'subscription_cycle',
        next_payment_attempt: Math.floor(Date.now() / 1000) + 86400,
      },
    });

    expect(await mailchimpTagChanges()).toEqual(['Payment trouble: active']);
  });

  test('a blank Lapsed Tag means lapsed members are not tagged', async ({ settings, request }) => {
    await setUpTagging(settings, { lapsed: '' });
    await settings.save();
    expect(getSettings(['lapsed_tag']).lapsed_tag).toBe('');
    await seedFakeStripe('customers', CUSTOMER);

    await postStripeWebhook(request, 'customer.subscription.deleted', subscriptionDeleted('payment_failed'));

    expect(await mailchimpTagChanges()).toEqual(['Left: inactive', 'Payment trouble: inactive']);
  });

  test('moving to another plan in Stripe swaps the old plan\'s Add Tags for the new one\'s', async ({
    settings,
    request,
  }) => {
    await setUpTagging(settings);
    await settings.editPlan(0, { addTags: 'Member, Monthly' });
    await settings.addPlan({ name: 'Gold', price: '20', addTags: 'Member, Gold' });
    await settings.save();
    await seedFakeStripe('customers', CUSTOMER);

    const stored = storedPlans(['baseline-plan_monthly_gbp', 'gold_monthly_gbp']) as Record<string, Record<string, any>>;
    const oldPrice = stored['baseline-plan_monthly_gbp'].stripe_price_id;
    const newPrice = stored['gold_monthly_gbp'].stripe_price_id;
    await postStripeWebhook(request, 'customer.subscription.updated', {
      object: {
        id: 'sub_1',
        object: 'subscription',
        customer: CUSTOMER.id,
        status: 'active',
        items: { data: [{ price: { id: newPrice } }] },
      },
      previous_attributes: { items: { data: [{ price: { id: oldPrice } }] } },
    });

    expect(await mailchimpTagChanges()).toEqual(['Member: active', 'Gold: active', 'Monthly: inactive']);
  });
});

test.describe('Membership plans saved before the settings page was rebuilt', () => {
  // See tests/settings/legacy/README.md.
  test.beforeEach(() => loadLegacySettings('membership-plans'));

  test('are still read', () => {
    const saved = getSettings(['membership_plans', 'lapsed_tag', 'cancelled_tag', 'lapsing_tag']);
    expect(saved.membership_plans).toEqual([
      expect.objectContaining({
        label: 'Legacy Standard',
        id: '',
        amount: '6',
        allow_custom_amount: false,
        frequency: 'monthly',
        currency: 'GBP',
        description: 'The usual',
        add_tags: 'Legacy member',
      }),
      expect.objectContaining({
        label: 'Legacy Pay What You Can',
        id: 'legacy-pwyc',
        amount: '3',
        allow_custom_amount: true,
        frequency: 'yearly',
        currency: 'EUR',
      }),
    ]);
    expect(saved).toMatchObject({
      lapsed_tag: 'Legacy lapsed',
      cancelled_tag: 'Legacy cancelled',
      lapsing_tag: 'Legacy lapsing',
    });
  });

  test('are still offered on the join form', async ({ page }) => {
    await openPlanStep(page);
    await expect(planPanel(page, 'Legacy Standard')).toContainText('£6, monthly');
    await expect(planPanel(page, 'Legacy Standard')).toContainText('The usual');
    await planPanel(page, 'Legacy Pay What You Can').click();
    await expect(page.locator('input[id="legacy-pwyc-amount"]')).toHaveAttribute('min', '3');
  });

  test('can still be joined on', async ({ request }) => {
    expect((await postJoin(request, { membership: 'legacy-standard_monthly_gbp' })).ok()).toBe(true);
    expect((await postJoin(request, { membership: 'legacy-pwyc', customMembershipAmount: 2 })).status()).toBe(500);
    expect((await postJoin(request, { membership: 'legacy-pwyc', customMembershipAmount: 4 })).ok()).toBe(true);
  });
});
