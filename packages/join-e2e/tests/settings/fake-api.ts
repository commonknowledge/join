/**
 * The fake third-party APIs (fake-api/server.js): what the plugin sent them,
 * and how they should answer.
 */

const CONTROL_URL = process.env.FAKE_API_URL || 'http://localhost:8890';

export type FakeApiRequest = {
  time: string;
  host: string;
  method: string;
  path: string;
  query: Record<string, string>;
  headers: Record<string, string>;
  body: any;
};

async function control(method: string, path: string, body?: unknown): Promise<any> {
  const response = await fetch(`${CONTROL_URL}${path}`, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!response.ok) {
    throw new Error(`Fake API control ${method} ${path} failed: ${response.status}`);
  }
  return response.json();
}

/** Forgets recorded requests, response overrides and fake Stripe objects. */
export async function resetFakeApi(): Promise<void> {
  await control('POST', '/reset');
}

/** Requests the plugin has made, optionally only those to one host. */
export async function fakeApiRequests(host?: string): Promise<FakeApiRequest[]> {
  const requests: FakeApiRequest[] = await control('GET', '/requests');
  return host ? requests.filter((request) => request.host === host) : requests;
}

/**
 * Makes the fake answer matching requests with the given status and body,
 * until the next reset. `path` is a regular expression.
 */
export async function overrideFakeApi(rule: {
  host?: string;
  method?: string;
  path?: string;
  status?: number;
  body?: unknown;
}): Promise<void> {
  await control('POST', '/overrides', rule);
}

/**
 * Puts an object straight into the fake Stripe, as if it had been created
 * there earlier, e.g. seedFakeStripe('customers', { id: 'cus_1', email: '...' }).
 */
export async function seedFakeStripe(
  type: 'products' | 'prices' | 'customers' | 'subscriptions' | 'invoices',
  object: Record<string, unknown>,
): Promise<void> {
  await control('POST', `/stripe/${type}`, object);
}
