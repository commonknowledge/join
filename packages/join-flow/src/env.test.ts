import { getPaymentProviders, getPaymentMethods, resolveStripePaymentMethodTypes } from './env';

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

  it('STRIPE_DIRECT_DEBIT_ONLY explicitly false (allow_cards_override) produces creditCard only', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT_ONLY: false }, () => {
      expect(getPaymentProviders().stripe).toEqual(['creditCard']);
    });
  });

  it('STRIPE_DIRECT_DEBIT_ONLY explicitly false with STRIPE_DIRECT_DEBIT true produces both methods', () => {
    withEnv({ USE_STRIPE: true, STRIPE_DIRECT_DEBIT_ONLY: false, STRIPE_DIRECT_DEBIT: true }, () => {
      const methods = getPaymentProviders().stripe;
      expect(methods).toContain('creditCard');
      expect(methods).toContain('directDebit');
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

// ---------------------------------------------------------------------------
// resolveStripePaymentMethodTypes — Stripe Elements paymentMethodTypes
// ---------------------------------------------------------------------------

describe('resolveStripePaymentMethodTypes', () => {
  it('one-off donation is always card-only regardless of direct debit flags', () => {
    withEnv({ STRIPE_DIRECT_DEBIT_ONLY: true }, () => {
      expect(resolveStripePaymentMethodTypes(true, 'gbp')).toEqual(['card']);
    });
  });

  it('subscription with STRIPE_DIRECT_DEBIT_ONLY uses bacs_debit, not card', () => {
    withEnv({ STRIPE_DIRECT_DEBIT_ONLY: true }, () => {
      expect(resolveStripePaymentMethodTypes(false, 'gbp')).toEqual(['bacs_debit']);
    });
  });

  it('subscription with STRIPE_DIRECT_DEBIT (non-only) in GBP includes both card and bacs_debit', () => {
    withEnv({ STRIPE_DIRECT_DEBIT: true }, () => {
      const types = resolveStripePaymentMethodTypes(false, 'gbp');
      expect(types).toContain('card');
      expect(types).toContain('bacs_debit');
    });
  });

  it('subscription with no direct debit flags is card-only', () => {
    withEnv({}, () => {
      expect(resolveStripePaymentMethodTypes(false, 'gbp')).toEqual(['card']);
    });
  });

  it('subscription with STRIPE_DIRECT_DEBIT in non-GBP currency does not include bacs_debit', () => {
    withEnv({ STRIPE_DIRECT_DEBIT: true }, () => {
      expect(resolveStripePaymentMethodTypes(false, 'eur')).toEqual(['card']);
    });
  });

  it('subscription with STRIPE_DIRECT_DEBIT_ONLY explicitly false (allow_cards_override) returns card-only', () => {
    withEnv({ STRIPE_DIRECT_DEBIT_ONLY: false }, () => {
      expect(resolveStripePaymentMethodTypes(false, 'gbp')).toEqual(['card']);
    });
  });
});
