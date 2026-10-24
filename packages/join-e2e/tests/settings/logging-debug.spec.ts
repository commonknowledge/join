import { APIRequestContext } from '@playwright/test';
import { STANDARD_JOIN_PAGE, expect, getSettings, joinFormEnv, loadLegacySettings, openJoinForm, test, wpEvalJson } from './fixtures';

/**
 * Settings page, Logging and Debug tabs.
 *
 * Logging: how many days of log files are kept, and the log shown on the tab.
 * Where else logs go (Sentry, Google Cloud, Loggly) is on the Integrations tab
 * (integrations-logging.spec.ts).
 *
 * Debug: turning off the join form's test data.
 */

/** Makes the plugin log a line naming `email` ("Recording CK Join form step"). */
async function logStep(request: APIRequestContext, email: string): Promise<void> {
  const response = await request.post('/wp-json/join/v1/step', { data: { email, stage: 'details' } });
  expect(response.ok()).toBe(true);
}

/**
 * Lays down a log file for each of the last `days` days (as if the site had
 * been logging that long), removes today's so the next log line starts a new
 * day's file, and returns the dates of the files left once that has happened.
 */
async function logFilesKeptAfter(request: APIRequestContext, days: number): Promise<string[]> {
  wpEvalJson(`
    $dir = \\CommonKnowledge\\JoinBlock\\Logging::getLogDirectory();
    $existing = glob("$dir/debug-*.log") ?: [];
    preg_match('/debug-([0-9a-f]+)-/', basename($existing[0] ?? 'debug-e2e-'), $match);
    $hash = $match[1] ?? bin2hex(random_bytes(18));
    array_map('unlink', $existing);
    for ($i = 1; $i <= ${days}; $i++) {
      file_put_contents("$dir/debug-$hash-" . date('Y-m-d', strtotime("-$i days")) . '.log', "old\\n");
    }
    return true;
  `);
  await logStep(request, 'rotation@example.org');
  return wpEvalJson<string[]>(`
    $dir = \\CommonKnowledge\\JoinBlock\\Logging::getLogDirectory();
    return array_map(fn ($file) => substr(basename($file, '.log'), -10), glob("$dir/debug-*.log") ?: []);
  `).sort();
}

/** The date `daysAgo` days ago in the WordPress container, e.g. 2026-10-20. */
const daysAgo = (days: number) => wpEvalJson<string>(`return date('Y-m-d', strtotime('-${days} days'));`);

test.describe('Logging', () => {
  test('logs are kept for 10 days unless Log Retention says otherwise', async ({ settings, request }) => {
    expect(getSettings(['join_flow_log_retention_days']).join_flow_log_retention_days).toBe('');

    await settings.open('Logging');
    await expect(
      settings.page.getByText(
        'Logs are retained for 10 days (default, as neither the Log Retention setting nor JOIN_FLOW_LOG_RETENTION_DAYS is set).',
      ),
    ).toBeVisible();

    const kept = await logFilesKeptAfter(request, 14);
    expect(kept).toHaveLength(10);
    expect(kept[0]).toBe(daysAgo(9));
  });

  test('Log Retention (days) sets how many days of log files are kept', async ({ settings, request }) => {
    await settings.open('Logging');
    await settings.setText('Log Retention (days)', '3');
    await settings.save();

    expect(getSettings(['join_flow_log_retention_days']).join_flow_log_retention_days).toBe('3');

    await settings.tab('Logging');
    await expect(
      settings.page.getByText('Logs are retained for 3 days (from the Log Retention setting above).'),
    ).toBeVisible();

    const kept = await logFilesKeptAfter(request, 6);
    expect(kept).toEqual([daysAgo(2), daysAgo(1), daysAgo(0)]);
  });

  for (const value of ['0', '2.5', '-1']) {
    test(`Log Retention of ${value} is refused, and nothing is saved`, async ({ settings }) => {
      await settings.open('Logging');
      await settings.setText('Log Retention (days)', value);
      await settings.tab('Debug');
      await settings.setCheckbox('Disable Test Data', true);

      await settings.expectSaveRefused();
      // The plugin checks it too, for saves that skip the browser's checks.
      expect(await settings.saveSkippingBrowserChecksExpectingErrors()).toEqual([
        'Log Retention must be a whole number of days, at least 1 (see Logging tab).',
      ]);
      expect(getSettings(['join_flow_log_retention_days', 'disable_test_data'])).toEqual({
        join_flow_log_retention_days: '',
        disable_test_data: false,
      });
    });
  }

  test('the tab shows the latest log', async ({ settings, request }) => {
    // A join on a plan that doesn't exist logs the plan's ID.
    const plan = `log-viewer-${Date.now()}`;
    const response = await request.post('/wp-json/join/v1/join', { data: { email: 'someone@example.com', membership: plan } });
    expect(response.status()).toBe(500);

    await settings.open('Logging');
    await expect(settings.page.locator('.cf-container__fields pre')).toContainText(`Invalid membership plan: ${plan}`);
  });
});

test.describe('Debug', () => {
  test('the join form is filled with test data when it was built with it', async ({ page }) => {
    expect(getSettings(['disable_test_data']).disable_test_data).toBe(false);
    expect((await joinFormEnv(page, STANDARD_JOIN_PAGE)).DISABLE_TEST_DATA).toBe(false);

    // The E2E suite builds the form with REACT_APP_USE_TEST_DATA (npm test does).
    await openJoinForm(page);
    await expect(page.locator('input#firstName')).toHaveValue('Test');
  });

  test('Disable Test Data leaves the join form empty', async ({ settings, page }) => {
    await settings.open('Debug');
    await settings.setCheckbox('Disable Test Data', true);
    await settings.save();

    expect(getSettings(['disable_test_data']).disable_test_data).toBe(true);
    expect((await joinFormEnv(page, STANDARD_JOIN_PAGE)).DISABLE_TEST_DATA).toBe(true);

    await openJoinForm(page);
    await expect(page.locator('input#firstName')).toHaveValue('');
    await expect(page.locator('input#email')).toHaveValue('');
  });
});

test.describe('Logging and Debug settings saved before the settings page was rebuilt', () => {
  // See tests/settings/legacy/README.md.
  test('are still read and still apply', async ({ settings, page, request }) => {
    loadLegacySettings('logging-debug');

    expect(getSettings(['join_flow_log_retention_days', 'disable_test_data'])).toEqual({
      join_flow_log_retention_days: '4',
      disable_test_data: true,
    });

    await openJoinForm(page);
    await expect(page.locator('input#firstName')).toHaveValue('');

    await settings.open('Logging');
    await expect(
      settings.page.getByText('Logs are retained for 4 days (from the Log Retention setting above).'),
    ).toBeVisible();
    expect(await logFilesKeptAfter(request, 8)).toHaveLength(4);
  });
});
