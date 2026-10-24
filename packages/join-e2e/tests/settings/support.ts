import { Locator, Page, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import { readFileSync } from 'fs';
import path from 'path';
import { ENV_SCRIPT_PATTERN } from '../helpers';

/**
 * Shared helpers for the settings page regression tests (tests/settings).
 *
 * These tests pin down what every setting on WP Admin > Join does today, so
 * that the settings page can be rebuilt without Carbon Fields and checked
 * against them. Each test changes a setting through the admin page, then
 * checks that the change was saved (read back through the plugin's own
 * Settings::get, so the check survives a change of storage format) and that
 * it has the effect the rest of the plugin expects.
 *
 * Two things here know how settings are stored and shown today, and are the
 * parts to rewrite when the settings page is rebuilt: JoinSettingsPage (how
 * the page is built) and setBaselinePlan() (writes a plan straight into
 * storage). The specs should not need to change.
 */

const PACKAGE_DIR = path.resolve(__dirname, '../..');

/** Inside the WordPress container. Written by scripts/seed.sh. */
const OPTIONS_SNAPSHOT = '/var/www/html/wp-content/e2e-options-snapshot.sql';

export const STANDARD_JOIN_PAGE = '/e2e-standard-join/';
export const FREE_JOIN_PAGE = '/e2e-free-join/';

/** Runs WP-CLI against the E2E site and returns its standard output. */
export function wp(...args: string[]): string {
  return execFileSync('bash', ['scripts/env.sh', 'wp', ...args], {
    cwd: PACKAGE_DIR,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

/** Runs PHP inside WordPress and returns the JSON it echoes, decoded. */
export function wpEvalJson<T = unknown>(php: string): T {
  const output = wp('eval', `echo "\\n" . wp_json_encode((function () { ${php} })());`);
  // The plugin can print log lines before the value; the value is the last line.
  return JSON.parse(output.split('\n').pop() as string) as T;
}

/**
 * A setting's value as the plugin reads it (Settings::get), by its key on the
 * settings page, e.g. 'collect_county'.
 */
export function getSetting<T = unknown>(key: string): T {
  return wpEvalJson<T>(`return \\CommonKnowledge\\JoinBlock\\Settings::get(${JSON.stringify(key)});`);
}

/** Several settings at once, by key (each WP-CLI call takes a second or two). */
export function getSettings(keys: string[]): Record<string, unknown> {
  return wpEvalJson(`
    $values = [];
    foreach (json_decode(${JSON.stringify(JSON.stringify(keys))}) as $key) {
      $values[$key] = \\CommonKnowledge\\JoinBlock\\Settings::get($key);
    }
    return $values;
  `);
}

/**
 * Puts every WordPress option back as scripts/seed.sh left it, undoing any
 * settings a test changed. Options only: users, pages and posts are kept.
 */
export function resetSettings(): void {
  try {
    wp('db', 'import', OPTIONS_SNAPSHOT);
  } catch (error) {
    throw new Error(
      `Could not restore the options snapshot. Run scripts/seed.sh (npm test does this) first.\n${error}`,
    );
  }
}

/**
 * Adds the one global membership plan the settings page needs before it will
 * save anything ("You must add at least one membership plan"), straight into
 * storage. Together with the seeded site, this is the state every settings
 * test starts from.
 *
 * Knows how plans are stored today: update it along with JoinSettingsPage
 * when the settings page is rebuilt.
 */
export function setBaselinePlan(): void {
  wpEvalJson(`
    carbon_set_theme_option('membership_plans', [[
      'label' => 'Baseline Plan',
      'amount' => '7',
      'frequency' => 'monthly',
      'currency' => 'GBP',
    ]]);
    return true;
  `);
}

/**
 * Writes raw option rows straight into the database, bypassing the settings
 * page. Used to load settings as Carbon Fields stored them before the settings
 * page was rebuilt (see tests/settings/legacy), to check that existing sites
 * keep working.
 */
export function loadRawOptions(options: Record<string, string>): void {
  wp('eval-file', '/var/www/html/wp-content/e2e-scripts/load-raw-options.php', JSON.stringify(options));
}

/** Loads tests/settings/legacy/<name>.json: settings as Carbon Fields stored them. */
export function loadLegacySettings(name: string): void {
  loadRawOptions(JSON.parse(readFileSync(path.join(__dirname, 'legacy', `${name}.json`), 'utf8')));
}

/** The settings the plugin hands the join form: the page's <script id="env"> JSON. */
export async function joinFormEnv(page: Page, url = STANDARD_JOIN_PAGE): Promise<Record<string, any>> {
  const response = await page.request.get(url);
  expect(response.ok()).toBe(true);
  const html = await response.text();
  const match = html.match(ENV_SCRIPT_PATTERN);
  expect(match, `no env script on ${url}`).not.toBeNull();
  return JSON.parse((match as RegExpMatchArray)[2]);
}

/**
 * Integration switches on the Features tab, and the Integrations tab
 * credentials each one needs before the settings page will save it switched
 * on. The values are fake: nothing in these tests reaches the real services.
 */
export const INTEGRATION_CREDENTIALS: Record<string, Record<string, string>> = {
  'Create Auth0 Account': {
    'Auth0 Domain': 'e2e.eu.auth0.com',
    'Auth0 Client Id': 'e2e-auth0-client-id',
    'Auth0 Client Secret': 'e2e-auth0-client-secret',
    'Auth0 Management Audience': 'https://e2e.eu.auth0.com/api/v2/',
  },
  'Use Zetkin': {
    'Zetkin Organisation ID': '1',
    'Zetkin Join Form ID': '2',
    'Zetkin Join Form Submission Token': 'e2e-zetkin-token',
  },
  'Use GoCardless': { 'Gc Access Token': 'e2e-gocardless-token' },
  'Use Stripe': { 'Stripe publishable key': 'pk_test_e2e', 'Stripe secret key': 'sk_test_e2e' },
  'Use Chargebee': {
    'Chargebee Site Name': 'e2e',
    'Chargebee Api Key': 'e2e-chargebee-key',
    'Chargebee Api Publishable Key': 'e2e-chargebee-publishable-key',
  },
  'Use Action Network': { 'Action Network API key': 'e2e-action-network-key' },
  'Use Mailchimp': { 'Mailchimp API key': 'e2e-mailchimp-key-us1', 'Mailchimp audience ID': 'e2e-audience' },
};

/**
 * The WP Admin > Join settings page.
 *
 * Fields are found by their visible labels, the way a person finds them.
 * This is the only code that knows how the page is built.
 */
export class JoinSettingsPage {
  static readonly URL = '/wp-admin/admin.php?page=crb_carbon_fields_container_ck_join_flow.php';

  constructor(readonly page: Page) {}

  /** Opens the settings page on the given tab, e.g. 'Features'. */
  async open(tab: string): Promise<void> {
    await this.page.goto(JoinSettingsPage.URL);
    await this.tab(tab);
  }

  /** Switches to another tab without leaving the page. */
  async tab(name: string): Promise<void> {
    await this.page.getByRole('tab', { name, exact: true }).click();
  }

  /** The input labelled `label` on the current tab. */
  field(label: string): Locator {
    return this.page.locator('.cf-container__fields').getByLabel(label, { exact: true });
  }

  async setCheckbox(label: string, checked: boolean): Promise<void> {
    await this.field(label).setChecked(checked);
  }

  async setText(label: string, value: string): Promise<void> {
    await this.field(label).fill(value);
  }

  /**
   * Ticks an integration switch on the Features tab and fills in the
   * credentials it needs on the Integrations tab (INTEGRATION_CREDENTIALS).
   * Does not save.
   */
  async enableIntegration(switchLabel: string): Promise<void> {
    await this.tab('Integrations');
    for (const [label, value] of Object.entries(INTEGRATION_CREDENTIALS[switchLabel])) {
      await this.setText(label, value);
    }
    await this.tab('Features');
    await this.setCheckbox(switchLabel, true);
  }

  /** Saves every tab, and fails with the page's error messages if the save is rejected. */
  async save(): Promise<void> {
    await this.submit();
    const errors = await this.errors();
    expect(errors, 'the settings page rejected the save').toEqual([]);
    await expect(this.page.getByText('Settings saved.')).toBeVisible();
  }

  /** Saves, expecting the page to reject it, and returns its error messages. */
  async saveExpectingErrors(): Promise<string[]> {
    await this.submit();
    const errors = await this.errors();
    expect(errors, 'expected the settings page to reject the save').not.toEqual([]);
    return errors;
  }

  private async submit(): Promise<void> {
    await Promise.all([this.page.waitForNavigation(), this.page.locator('#publish').click()]);
  }

  private async errors(): Promise<string[]> {
    const texts = await this.page.locator('.settings-error.error p, .settings-error.error li').allInnerTexts();
    return texts.map((text) => text.trim()).filter(Boolean);
  }
}
