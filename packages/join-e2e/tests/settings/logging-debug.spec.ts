import { APIRequestContext } from '@playwright/test';
import {
  PHP_LOG_FILES,
  STANDARD_JOIN_PAGE,
  backUpLogFiles,
  expect,
  getSettings,
  joinFormEnv,
  loadLegacySettings,
  logInfo,
  logInvalidPlan,
  openJoinForm,
  restoreLogFiles,
  test,
  wpEvalJson,
} from './fixtures';

/**
 * Settings page, Logging and Debug tabs.
 *
 * Logging: how many days of log files are kept, and the log shown on the tab.
 * Where else logs go (Sentry, Google Cloud, Loggly) is on the Integrations tab
 * (integrations-logging.spec.ts).
 *
 * Debug: turning off the join form's test data.
 */

// These tests replace the log files; each test's are put back afterwards.
test.beforeEach(() => backUpLogFiles());
test.afterEach(() => restoreLogFiles());

/**
 * Replaces the log files with one for each of the `days` days before today
 * (as if the site had been logging that long), so the next log line starts a
 * new day's file and the plugin rotates the old ones. Returns the dates laid
 * down and the dates kept after rotation, both newest first.
 */
async function rotateLogFiles(request: APIRequestContext, days: number) {
  const laidDown = wpEvalJson<string[]>(`
    $dir = \\CommonKnowledge\\JoinBlock\\Logging::getLogDirectory();
    $existing = ${PHP_LOG_FILES};
    preg_match('/debug-([0-9a-f]+)-/', basename($existing[0] ?? 'debug-e2e-'), $match);
    $hash = $match[1] ?? bin2hex(random_bytes(18));
    array_map('unlink', $existing);
    $dates = [];
    for ($i = 1; $i <= ${days}; $i++) {
      $dates[] = date('Y-m-d', strtotime("-$i days"));
      file_put_contents("$dir/debug-$hash-" . end($dates) . '.log', "old\\n");
    }
    return $dates;
  `);
  await logInfo(request);
  const kept = wpEvalJson<string[]>(
    `return array_map(fn ($file) => substr(basename($file, '.log'), -10), ${PHP_LOG_FILES});`,
  )
    .sort()
    .reverse();
  return { laidDown, kept };
}

/**
 * Checks rotation kept `days` days of files: the new day's, and the newest of
 * those laid down. (Worked out from the dates rotateLogFiles saw, so a run
 * across midnight still passes.)
 */
function expectKept({ laidDown, kept }: { laidDown: string[]; kept: string[] }, days: number): void {
  expect(kept).toHaveLength(days);
  expect(kept[0] > laidDown[0]).toBe(true);
  expect(kept.slice(1)).toEqual(laidDown.slice(0, days - 1));
}

/** JOIN_FLOW_LOG_RETENTION_DAYS from the plugin's environment (packages/join-block/.env), if set. */
const retentionFromEnvironment = () =>
  wpEvalJson<string | null>(`return $_ENV['JOIN_FLOW_LOG_RETENTION_DAYS'] ?? null;`);

test.describe('Logging', () => {
  test('logs are kept for 10 days unless Log Retention says otherwise', async ({ settings, request }) => {
    test.skip(
      retentionFromEnvironment() !== null,
      'JOIN_FLOW_LOG_RETENTION_DAYS is set in packages/join-block/.env, which replaces the default',
    );
    expect(getSettings(['join_flow_log_retention_days']).join_flow_log_retention_days).toBe('');

    await settings.open('Logging');
    await expect(
      settings.page.getByText(
        'Logs are retained for 10 days (default, as neither the Log Retention setting nor JOIN_FLOW_LOG_RETENTION_DAYS is set).',
      ),
    ).toBeVisible();

    expectKept(await rotateLogFiles(request, 14), 10);
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

    expectKept(await rotateLogFiles(request, 6), 3);
  });

  for (const value of ['0', '2.5', '-1']) {
    test(`Log Retention of ${value} is refused, and nothing is saved`, async ({ settings }) => {
      await settings.open('Logging');
      await settings.setText('Log Retention (days)', value);
      await settings.tab('Debug');
      await settings.setCheckbox('Disable Test Data', true);

      await settings.expectSaveRefused();
      // The plugin checks it too, for saves that skip the browser's checks.
      // (If the refusal above was a reload, the page shows the saved values
      // again, so enter the invalid ones again.)
      await settings.tab('Logging');
      await settings.setText('Log Retention (days)', value);
      await settings.tab('Debug');
      await settings.setCheckbox('Disable Test Data', true);
      expect(await settings.saveSkippingPageChecksExpectingErrors()).toEqual([
        'Log Retention must be a whole number of days, at least 1 (see Logging tab).',
      ]);
      expect(getSettings(['join_flow_log_retention_days', 'disable_test_data'])).toEqual({
        join_flow_log_retention_days: '',
        disable_test_data: false,
      });
    });
  }

  test('the tab shows the latest log', async ({ settings, request }) => {
    const plan = `log-viewer-${Date.now()}`;
    await logInvalidPlan(request, plan);

    await settings.open('Logging');
    await expect(settings.page.locator('pre', { hasText: `Invalid membership plan: ${plan}` })).toBeVisible();
  });

  // The tab picks its file by a different rule from the plugin's, so with
  // another log file in the folder it can show a stale one (Linear JOIN-172).
  // Re-enable once it's fixed.
  test.fixme('the tab shows the log being written, even with another log file in the folder', async ({
    settings,
    request,
  }) => {
    await logInfo(request);
    // A stale file under another name, as an earlier install might leave.
    wpEvalJson(`
      $dir = \\CommonKnowledge\\JoinBlock\\Logging::getLogDirectory();
      file_put_contents("$dir/debug-zzzz-" . date('Y-m-d', strtotime('-1 day')) . '.log', "stale\\n");
      return true;
    `);
    const plan = `log-viewer-${Date.now()}`;
    await logInvalidPlan(request, plan);

    await settings.open('Logging');
    await expect(settings.page.locator('pre', { hasText: `Invalid membership plan: ${plan}` })).toBeVisible();
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
    expectKept(await rotateLogFiles(request, 8), 4);
  });
});
