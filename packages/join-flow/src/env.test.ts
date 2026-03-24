import { getPaymentProviders, getPaymentMethods } from './env';

function withEnv(overrides: Record<string, any>, fn: () => void) {
  const original = (window as any).process?.env;
  (window as any).process = { env: overrides };
  try {
    fn();
  } finally {
    (window as any).process = { env: original ?? {} };
  }
}

describe('getPaymentProviders — Stripe direct debit flags', () => {
  it('STRIPE_DIRECT_DEBIT_ONLY alone includes directDebit', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT_ONLY: true }, () => {
      expect(getPaymentProviders().stripe).toContain('directDebit');
    });
  });

  it('STRIPE_DIRECT_DEBIT_ONLY alone excludes creditCard', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT_ONLY: true }, () => {
      expect(getPaymentProviders().stripe).not.toContain('creditCard');
    });
  });

  it('STRIPE_DIRECT_DEBIT_ONLY alone produces a non-empty stripe provider so a provider is always resolved', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT_ONLY: true }, () => {
      expect(getPaymentProviders().stripe?.length).toBeGreaterThan(0);
    });
  });

  it('STRIPE_DIRECT_DEBIT without STRIPE_DIRECT_DEBIT_ONLY includes both methods', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT: true }, () => {
      const methods = getPaymentProviders().stripe;
      expect(methods).toContain('creditCard');
      expect(methods).toContain('directDebit');
    });
  });

  it('neither direct debit flag produces creditCard only', () => {
    withEnv({ USE_STRIPE: true }, () => {
      expect(getPaymentProviders().stripe).toEqual(['creditCard']);
    });
  });
});

describe('getPaymentMethods — STRIPE_DIRECT_DEBIT_ONLY', () => {
  it('returns directDebit and not creditCard', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT_ONLY: true }, () => {
      const methods = getPaymentMethods();
      expect(methods).toContain('directDebit');
      expect(methods).not.toContain('creditCard');
    });
  });
});
