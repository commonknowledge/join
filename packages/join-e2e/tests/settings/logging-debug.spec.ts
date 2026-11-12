import { APIRequestContext, Page } from '@playwright/test';
import {
  JoinSettingsPage,
  PHP_LOG_DIR,
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

// Tests that replace the log files back them up first (replaceLogFiles), and
// they are put back afterwards. Other tests leave them alone.
let logFilesBackedUp = false;

function replaceLogFiles(): void {
  if (!logFilesBackedUp) {
    backUpLogFiles();
    logFilesBackedUp = true;
  }
}

test.afterEach(() => {
  if (logFilesBackedUp) {
    restoreLogFiles();
    logFilesBackedUp = false;
  }
});

/**
 * Replaces the log files with one for each of the `days` days before today
 * (as if the site had been logging that long), so the next log line starts a
 * new day's file and the plugin rotates the old ones. Returns the dates laid
 * down and the dates kept after rotation, both newest first.
 */
async function rotateLogFiles(request: APIRequestContext, days: number) {
  replaceLogFiles();
  const laidDown = wpEvalJson<string[]>(`
    $dir = ${PHP_LOG_DIR};
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
  const kept = wpEvalJson<string[]>(`return array_map(fn ($file) => substr(basename($file, '.log'), -10), ${PHP_LOG_FILES});`)
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

/**
 * Whether JOIN_FLOW_LOG_RETENTION_DAYS (e.g. from packages/join-block/.env)
 * replaces the default. Read from the Logging tab, which the web server
 * renders: its environment is the one that rotates the logs, and it can differ
 * from WP-CLI's.
 */
async function retentionFromEnvironment(settings: JoinSettingsPage): Promise<boolean> {
  await settings.open('Logging');
  return (await settings.page.getByText('from JOIN_FLOW_LOG_RETENTION_DAYS=').count()) > 0;
}

/** Enters an invalid Log Retention, and another change on the Debug tab, without saving. */
async function enterInvalidRetention(settings: JoinSettingsPage, value: string): Promise<void> {
  await settings.tab('Logging');
  await settings.setText('Log Retention (days)', value);
  await settings.tab('Debug');
  await settings.setCheckbox('Disable Test Data', true);
}

/**
 * Opens the join form and says whether it filled itself with test data:
 * whether this build was made with REACT_APP_USE_TEST_DATA (npm test makes it
 * so). The form says so in the console when it uses test data.
 */
async function openFormBuiltWithTestData(page: Page): Promise<boolean> {
  let said = false;
  const listener = (message: { text(): string }) => {
    if (message.text().includes('REACT_APP_USE_TEST_DATA environment variable set')) said = true;
  };
  page.on('console', listener);
  await openJoinForm(page);
  page.off('console', listener);
  return said;
}

const NOT_BUILT_WITH_TEST_DATA = 'the join form was not built with REACT_APP_USE_TEST_DATA (npm test builds it so)';

test.describe('Logging', () => {
  test('logs are kept for 10 days unless Log Retention says otherwise', async ({ settings, request }) => {
    test.skip(
      await retentionFromEnvironment(settings),
      'JOIN_FLOW_LOG_RETENTION_DAYS is set (e.g. in packages/join-block/.env), which replaces the default',
    );
    expect(getSettings(['join_flow_log_retention_days']).join_flow_log_retention_days).toBe('');

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
      await enterInvalidRetention(settings, value);
      await settings.expectSaveRefused();

      // The plugin checks it too, for saves that skip the browser's checks.
      // (If the refusal above was a reload, the page shows the saved values
      // again, so enter the invalid ones again.)
      await enterInvalidRetention(settings, value);
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
    // Start from no log files, so the plugin writes the only one. With others
    // in the folder, the tab can show a stale one (JOIN-172, below).
    replaceLogFiles();
    wpEvalJson(`array_map('unlink', ${PHP_LOG_FILES}); return true;`);
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
    replaceLogFiles();
    await logInfo(request);
    // A stale file under another name, as an earlier install might leave.
    wpEvalJson(`
      $dir = ${PHP_LOG_DIR};
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

    test.skip(!(await openFormBuiltWithTestData(page)), NOT_BUILT_WITH_TEST_DATA);
    await expect(page.locator('input#firstName')).toHaveValue('Test');
  });

  test('Disable Test Data leaves the join form empty', async ({ settings, page }) => {
    // Without test data in the build the form is empty anyway, so this would prove nothing.
    test.skip(!(await openFormBuiltWithTestData(page)), NOT_BUILT_WITH_TEST_DATA);

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
    const builtWithTestData = await openFormBuiltWithTestData(page);
    loadLegacySettings('logging-debug');

    expect(getSettings(['join_flow_log_retention_days', 'disable_test_data'])).toEqual({
      join_flow_log_retention_days: '4',
      disable_test_data: true,
    });

    // Disable Test Data only shows when the build has test data to disable.
    if (builtWithTestData) {
      await openJoinForm(page);
      await expect(page.locator('input#firstName')).toHaveValue('');
    }

    await settings.open('Logging');
    await expect(
      settings.page.getByText('Logs are retained for 4 days (from the Log Retention setting above).'),
    ).toBeVisible();
    expectKept(await rotateLogFiles(request, 8), 4);
  });
});
