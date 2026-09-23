/**
 * Unit tests for renderDonationSummary — pure function for displaying the
 * donation amount in the summary panel.
 *
 * Red-Green-Reverse TDD: tests are written before the function exists.
 */
import { getTestDataIfEnabled, renderDonationSummary } from './schema';
import { FormSchema } from './schema';

const PLAN_STANDARD = {
  value: 'standard',
  label: 'Twenty a month',
  amount: 20,
  currency: 'GBP',
  frequency: 'monthly',
  allowCustomAmount: false,
};

function withEnv(overrides: Record<string, any>, fn: () => void) {
  const original = (window as any).process?.env;
  (window as any).process = { env: overrides };
  try {
    fn();
  } finally {
    (window as any).process = { env: original ?? {} };
  }
}

const withPlan = (fn: () => void) =>
  withEnv({ MEMBERSHIP_PLANS: [PLAN_STANDARD] }, fn);

// ---------------------------------------------------------------------------
// Supporter mode — monthly (recurDonation: true)
// Amount comes from the plan, not donationAmount
// ---------------------------------------------------------------------------

describe('renderDonationSummary — supporter mode monthly', () => {
  it('shows plan amount when no custom amount', () => {
    withPlan(() => {
      const data: FormSchema = {
        donationSupporterMode: true,
        recurDonation: true,
        membership: 'standard',
      };
      expect(renderDonationSummary(data)).toBe('£20 a month donation');
    });
  });

  it('shows customMembershipAmount when provided', () => {
    withPlan(() => {
      const data: FormSchema = {
        donationSupporterMode: true,
        recurDonation: true,
        membership: 'standard',
        customMembershipAmount: 35,
      };
      expect(renderDonationSummary(data)).toBe('£35 a month donation');
    });
  });

  it('does NOT show "None right now" when plan amount is available', () => {
    withPlan(() => {
      const data: FormSchema = {
        donationSupporterMode: true,
        recurDonation: true,
        membership: 'standard',
      };
      expect(renderDonationSummary(data)).not.toBe('None right now');
    });
  });
});

// ---------------------------------------------------------------------------
// Supporter mode — one-off (recurDonation: false)
// Amount comes from donationAmount
// ---------------------------------------------------------------------------

describe('renderDonationSummary — supporter mode one-off', () => {
  it('shows donationAmount as one time donation', () => {
    withPlan(() => {
      const data: FormSchema = {
        donationSupporterMode: true,
        recurDonation: false,
        donationAmount: 50,
      };
      expect(renderDonationSummary(data)).toBe('£50 one time donation');
    });
  });

  it('returns "None right now" when donationAmount is zero', () => {
    withPlan(() => {
      const data: FormSchema = {
        donationSupporterMode: true,
        recurDonation: false,
        donationAmount: 0,
      };
      expect(renderDonationSummary(data)).toBe('None right now');
    });
  });

  it('returns "None right now" when donationAmount is absent', () => {
    withPlan(() => {
      const data: FormSchema = {
        donationSupporterMode: true,
        recurDonation: false,
      };
      expect(renderDonationSummary(data)).toBe('None right now');
    });
  });
});

// ---------------------------------------------------------------------------
// Standard mode — additional donation
// ---------------------------------------------------------------------------

describe('renderDonationSummary — standard mode', () => {
  it('shows donationAmount as one time donation', () => {
    withPlan(() => {
      const data: FormSchema = { donationAmount: 10, recurDonation: false };
      expect(renderDonationSummary(data)).toBe('£10 one time donation');
    });
  });

  it('shows donationAmount as monthly donation when recurDonation is true', () => {
    withPlan(() => {
      const data: FormSchema = { donationAmount: 10, recurDonation: true };
      expect(renderDonationSummary(data)).toBe('£10 a month donation');
    });
  });

  it('returns "None right now" when donationAmount is absent', () => {
    withPlan(() => {
      const data: FormSchema = {};
      expect(renderDonationSummary(data)).toBe('None right now');
    });
  });
});

describe('getTestDataIfEnabled', () => {
  it('returns test data when USE_TEST_DATA is set', () => {
    withEnv({ USE_TEST_DATA: true }, () => {
      expect(getTestDataIfEnabled().email).toBe('someone@example.com');
      expect(console).toHaveLogged();
    });
  });

  it('returns nothing when USE_TEST_DATA is not set', () => {
    withEnv({}, () => {
      expect(getTestDataIfEnabled()).toEqual({});
    });
  });

  it('DISABLE_TEST_DATA overrides USE_TEST_DATA', () => {
    withEnv({ USE_TEST_DATA: true, DISABLE_TEST_DATA: true }, () => {
      expect(getTestDataIfEnabled()).toEqual({});
    });
  });
});

// ---------------------------------------------------------------------------
// Conditional custom fields — trigger matching across every field type
// ---------------------------------------------------------------------------

import { getFieldCondition, matchesTrigger, parseTriggerValues } from './schema';

describe('parseTriggerValues', () => {
  it('splits on commas and trims whitespace', () => {
    expect(parseTriggerValues(' red , blue,,green ')).toEqual(['red', 'blue', 'green']);
  });

  it('returns an empty list for blank input', () => {
    expect(parseTriggerValues(undefined)).toEqual([]);
    expect(parseTriggerValues('')).toEqual([]);
  });
});

describe('matchesTrigger', () => {
  it('matches select/radio option values', () => {
    expect(matchesTrigger('red', ['red', 'blue'])).toBe(true);
    expect(matchesTrigger('green', ['red', 'blue'])).toBe(false);
    expect(matchesTrigger('', ['red', 'blue'])).toBe(false);
  });

  it('matches checkbox booleans against "true"/"false"', () => {
    expect(matchesTrigger(true, ['true'])).toBe(true);
    expect(matchesTrigger(false, ['true'])).toBe(false);
    expect(matchesTrigger(false, ['false'])).toBe(true);
    expect(matchesTrigger(undefined, ['false'])).toBe(false);
  });

  it('matches text values ignoring case and surrounding whitespace', () => {
    expect(matchesTrigger('  Yes ', ['yes'])).toBe(true);
    expect(matchesTrigger('yes', ['YES'])).toBe(true);
    expect(matchesTrigger('no', ['yes'])).toBe(false);
  });

  it('matches number values whether given as numbers or strings', () => {
    expect(matchesTrigger(5, ['5'])).toBe(true);
    expect(matchesTrigger('5', ['5'])).toBe(true);
    expect(matchesTrigger(6, ['5'])).toBe(false);
  });

  it('matches month/year values in MM/YYYY form', () => {
    expect(matchesTrigger('01/2024', ['01/2024'])).toBe(true);
    expect(matchesTrigger('02/2024', ['01/2024'])).toBe(false);
  });

  it('shows the field for any non-empty value when no trigger values are set', () => {
    expect(matchesTrigger('anything', [])).toBe(true);
    expect(matchesTrigger(true, [])).toBe(true);
    expect(matchesTrigger(0, [])).toBe(true);
    expect(matchesTrigger('', [])).toBe(false);
    expect(matchesTrigger('   ', [])).toBe(false);
    expect(matchesTrigger(false, [])).toBe(false);
    expect(matchesTrigger(undefined, [])).toBe(false);
  });
});

describe('getFieldCondition', () => {
  it('returns null when no trigger field is configured', () => {
    expect(getFieldCondition({ id: 'a' })).toBeNull();
    expect(getFieldCondition({ id: 'a', conditional_trigger_field: '  ' })).toBeNull();
  });

  it('returns null when "display conditionally" is explicitly off', () => {
    expect(
      getFieldCondition({
        id: 'a',
        display_conditionally: false,
        conditional_trigger_field: 'b',
        conditional_trigger_values: 'x',
      })
    ).toBeNull();
  });

  it('returns the parsed condition when enabled', () => {
    expect(
      getFieldCondition({
        id: 'a',
        display_conditionally: true,
        conditional_trigger_field: ' b ',
        conditional_trigger_values: 'x, y',
      })
    ).toEqual({ triggerField: 'b', triggerValues: ['x', 'y'] });
  });

  it('treats a missing "display conditionally" flag as enabled', () => {
    expect(getFieldCondition({ id: 'a', conditional_trigger_field: 'b' })).toEqual({
      triggerField: 'b',
      triggerValues: [],
    });
  });
});
