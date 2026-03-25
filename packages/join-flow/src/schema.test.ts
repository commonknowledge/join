/**
 * Unit tests for renderDonationSummary — pure function for displaying the
 * donation amount in the summary panel.
 *
 * Red-Green-Reverse TDD: tests are written before the function exists.
 */
import { renderDonationSummary } from './schema';
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
