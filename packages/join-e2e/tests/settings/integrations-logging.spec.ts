import { generateKeyPairSync } from 'crypto';
import {
  PHP_LOG_FILES,
  expect,
  fakeApiRequests,
  getSettings,
  joinFormEnv,
  logInfo,
  logInvalidPlan,
  openJoinForm,
  test,
  wpEvalJson,
} from './fixtures';

/**
 * Settings page, Integrations tab: where the plugin's logs go (Sentry, Google
 * Cloud Logging, Loggly). The Logging tab (log viewer, retention) is covered
 * separately.
 *
 * Logging is set up at the start of each request, so each test saves the
 * settings, makes a fresh request that logs something, and checks what reached
 * the service's fake.
 */

/** The size of the plugin's log files on disk, in bytes. */
const logFilesSize = () =>
  wpEvalJson<number>(`clearstatcache(); return array_sum(array_map('filesize', ${PHP_LOG_FILES}));`);

const LOGGING_HOSTS = ['logs-01.loggly.com', 'sentry.e2e.example', 'logging.googleapis.com'];

test('by default, logs are written to disk and sent nowhere else', async ({ request }) => {
  const before = logFilesSize();
  await logInfo(request);
  await logInvalidPlan(request);

  expect(logFilesSize()).toBeGreaterThan(before);
  const sent = await fakeApiRequests();
  expect(sent.filter((request) => LOGGING_HOSTS.includes(request.host))).toEqual([]);
});

test.describe('Loggly', () => {
  test('Loggly Customer Token sends logs to Loggly instead of to disk, tagged with Loggly Tags', async ({
    settings,
    request,
  }) => {
    await settings.open('Integrations');
    await settings.setText('Loggly Customer Token', 'e2e-loggly-token');
    await settings.setText('Loggly Tags', 'e2e-site, join');
    await settings.save();

    expect(getSettings(['loggly_token', 'loggly_tags'])).toEqual({
      loggly_token: 'e2e-loggly-token',
      loggly_tags: 'e2e-site, join',
    });

    const before = logFilesSize();
    await logInfo(request);

    const sent = await fakeApiRequests('logs-01.loggly.com');
    expect(sent.length).toBeGreaterThan(0);
    expect(sent[0]).toMatchObject({ method: 'POST', path: '/bulk/e2e-loggly-token/' });
    expect(sent[0].headers['x-loggly-tag']).toBe('e2e-site,join');
    expect(sent.map((request) => JSON.stringify(request.body)).join('\n')).toContain('Recording CK Join form step');
    expect(logFilesSize()).toBe(before);
  });

  test('without Loggly Tags, logs are tagged join-block', async ({ settings, request }) => {
    await settings.open('Integrations');
    await settings.setText('Loggly Customer Token', 'e2e-loggly-token');
    await settings.save();

    await logInfo(request);

    const [first] = await fakeApiRequests('logs-01.loggly.com');
    expect(first.headers['x-loggly-tag']).toBe('join-block');
  });

  test('the Logging tab says where the logs went', async ({ settings }) => {
    await settings.open('Integrations');
    await settings.setText('Loggly Customer Token', 'e2e-loggly-token');
    await settings.setText('Loggly Tags', 'e2e-site');
    await settings.save();

    await settings.tab('Logging');
    await expect(settings.page.getByText('Logs are sent to Loggly and are not stored on this server.')).toBeVisible();
    await expect(settings.page.getByText('e2e-site', { exact: true })).toBeVisible();
  });
});

test.describe('Sentry', () => {
  const DSN = 'https://e2epublickey@sentry.e2e.example/42';

  test("Sentry Dsn sends the plugin's errors to Sentry, and sets Sentry up in the join form", async ({
    settings,
    request,
    page,
  }) => {
    await settings.open('Integrations');
    await settings.setText('Sentry Dsn', DSN);
    await settings.save();

    expect(getSettings(['sentry_dsn']).sentry_dsn).toBe(DSN);

    await logInfo(request);
    expect(await fakeApiRequests('sentry.e2e.example')).toEqual([]);

    await logInvalidPlan(request);
    const [event] = await fakeApiRequests('sentry.e2e.example');
    expect(event).toMatchObject({ method: 'POST', path: '/api/42/envelope/' });
    expect(event.headers['x-sentry-auth']).toContain('sentry_key=e2epublickey');
    expect(event.body).toContain('Invalid membership plan: no-such-plan');

    // The form reports its own errors to the same project.
    expect((await joinFormEnv(page)).SENTRY_DSN).toBe(DSN);
    const reported: string[] = [];
    await page.route('https://sentry.e2e.example/**', (route) => {
      reported.push(route.request().url());
      return route.fulfill({ status: 200, body: '{}' });
    });
    await openJoinForm(page);
    await page.evaluate(() =>
      setTimeout(() => {
        throw new Error('e2e form error');
      }),
    );
    await expect.poll(() => reported.length).toBeGreaterThan(0);
    expect(reported[0]).toContain('/api/42/');
  });
});

test.describe('Google Cloud Logging', () => {
  test("Google Cloud Project Id and Key File Contents send logs to that project's log", async ({
    settings,
    request,
  }) => {
    const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
    const keyFile = JSON.stringify({
      type: 'service_account',
      project_id: 'e2e-project',
      private_key_id: 'e2e-key',
      private_key: privateKey.export({ type: 'pkcs8', format: 'pem' }),
      client_email: 'join-logs@e2e-project.iam.gserviceaccount.com',
      client_id: '1',
      token_uri: 'https://oauth2.googleapis.com/token',
    });

    await settings.open('Integrations');
    await settings.setText('Google Cloud Project Id', 'e2e-project');
    await settings.setText('Google Cloud Key File Contents', keyFile);
    await settings.save();

    expect(getSettings(['google_cloud_project_id', 'google_cloud_key_file_contents'])).toEqual({
      google_cloud_project_id: 'e2e-project',
      google_cloud_key_file_contents: keyFile,
    });

    await logInfo(request);

    const writes = (await fakeApiRequests('logging.googleapis.com')).filter(
      (request) => request.method === 'POST' && request.path === '/v2/entries:write',
    );
    expect(writes.length).toBeGreaterThan(0);
    const entries = writes.flatMap((request) => request.body.entries);
    expect(entries).toContainEqual(
      expect.objectContaining({
        logName: 'projects/e2e-project/logs/join-flow',
        jsonPayload: expect.objectContaining({ message: 'Recording CK Join form step' }),
      }),
    );

    // Signed in as the key file's service account.
    const token = writes[0].headers.authorization.replace(/^Bearer /, '');
    const claims = JSON.parse(Buffer.from(token.split('.')[1], 'base64url').toString());
    expect(claims.iss).toBe('join-logs@e2e-project.iam.gserviceaccount.com');
  });
});
