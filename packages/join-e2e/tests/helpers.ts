import { Page } from '@playwright/test';

export const SAVED_STATE_KEY = 'ck_join_state_flow';
export const CONTINUE = 'button[type="submit"]:has-text("Continue")';

/**
 * Intercepts both REST endpoints the form calls during navigation and fulfils
 * them with empty 200 responses so that network errors never block progression.
 */
export async function mockRestEndpoints(page: Page): Promise<void> {
  await page.route('**/wp-json/join/v1/step', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({}),
    });
  });
  await page.route('**/wp-json/join/v1/join', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true }),
    });
  });
}

/**
 * Intercepts the HTML response for the given URL pattern and injects
 * additional key/value pairs into the <script id="env"> JSON block.
 *
 * Must be called BEFORE page.goto() so the route is in place when the page
 * loads. The overrides are merged on top of whatever the server returns.
 */
export async function injectEnvOverrides(
  page: Page,
  urlPattern: string,
  overrides: Record<string, unknown>,
): Promise<void> {
  await page.route(urlPattern, async (route) => {
    const response = await route.fetch();
    let body = await response.text();
    body = body.replace(
      /(<script type="application\/json" id="env">)([\s\S]*?)(<\/script>)/,
      (_, open: string, json: string, close: string) => {
        try {
          const env = JSON.parse(json);
          return `${open}${JSON.stringify({ ...env, ...overrides })}${close}`;
        } catch {
          return `${open}${json}${close}`;
        }
      },
    );
    await route.fulfill({ response, body });
  });
}

/**
 * Simulates a completed Stripe payment by injecting a fake stripePaymentIntentId
 * into sessionStorage, then reloading the page with ?stripe_success=true.
 *
 * The app detects the query param + sessionStorage flag and skips straight to
 * the confirm stage. The /join endpoint is intercepted and the request body is
 * captured and returned.
 *
 * Call this after the form has advanced past the details stage (so that
 * sessionStorage already contains the accumulated form data).
 */
export async function captureJoinBodyViaStripeRedirect(
  page: Page,
  pageUrl: string,
): Promise<Record<string, unknown>> {
  // Inject the fake payment intent ID into the existing session state.
  await page.evaluate((key: string) => {
    const state = JSON.parse(sessionStorage.getItem(key) || '{}');
    state.stripePaymentIntentId = 'pi_test_fake_e2e';
    sessionStorage.setItem(key, JSON.stringify(state));
  }, SAVED_STATE_KEY);

  let joinBody: Record<string, unknown> | null = null;

  await page.route('**/wp-json/join/v1/join', async (route) => {
    joinBody = route.request().postDataJSON();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true }),
    });
  });

  // Reload with stripe_success=true — the app jumps to confirm and auto-submits.
  await page.goto(`${pageUrl}?stripe_success=true`);

  // Wait until the /join body has been captured.
  await page.waitForFunction(
    () => document.querySelector('h2') !== null,
    { timeout: 10000 },
  );

  // Poll briefly for the body (the confirm page fires /join on mount).
  await page.waitForTimeout(500);

  return joinBody ?? {};
}
