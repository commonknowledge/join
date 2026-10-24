import { APIRequestContext, Locator, Page, expect } from '@playwright/test';
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
/** A join form with one custom field, so it shows the "more about you" section. */
export const CUSTOM_FIELDS_PAGE = '/e2e-custom-fields/';
/** A join form offering the same plan in GBP and EUR. */
export const MULTI_CURRENCY_PAGE = '/e2e-multi-currency-join/';
/** A join form with no plans of its own, so it offers the global plans. */
export const GLOBAL_PLANS_PAGE = '/e2e-global-plans-join/';

/** A pattern matching exactly `text`, with any pattern characters in it escaped. */
const exactly = (text: string) => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`);

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
export function loadRawOptions(options: Record<string, unknown>): void {
  wp('eval-file', '/var/www/html/wp-content/e2e-scripts/load-raw-options.php', JSON.stringify(options));
}

/**
 * Loads tests/settings/legacy/<name>.json, settings as Carbon Fields stored
 * them, and returns the rows it loaded.
 */
export function loadLegacySettings(name: string): Record<string, any> {
  const options = JSON.parse(readFileSync(path.join(__dirname, 'legacy', `${name}.json`), 'utf8'));
  loadRawOptions(options);
  return options;
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
 * Sends a join to the plugin's /join endpoint, as the join form does at its
 * last step, and returns the response. Only the fields the test cares about
 * need passing; `membership` is the chosen plan's ID.
 */
/** The email postJoin() joins with, unless given another. */
export const MEMBER_EMAIL = 'member@example.org';

export async function postJoin(request: APIRequestContext, data: Record<string, unknown>) {
  return request.post('/wp-json/join/v1/join', {
    data: { firstName: 'Ada', lastName: 'Lovelace', email: MEMBER_EMAIL, ...data },
  });
}

/** Makes the plugin log an info line ("Recording CK Join form step"). */
export async function logInfo(request: APIRequestContext): Promise<void> {
  const response = await request.post('/wp-json/join/v1/step', {
    data: { email: 'someone@example.com', stage: 'details' },
  });
  expect(response.ok()).toBe(true);
}

/** Makes the plugin log an error naming `plan` ("Invalid membership plan: <plan>"). */
export async function logInvalidPlan(request: APIRequestContext, plan = 'no-such-plan'): Promise<void> {
  const response = await request.post('/wp-json/join/v1/join', {
    data: { email: 'someone@example.com', membership: plan },
  });
  expect(response.status()).toBe(500);
}

/** PHP for the paths of the plugin's log files, for use in wpEvalJson(). */
export const PHP_LOG_FILES = `(glob(\\CommonKnowledge\\JoinBlock\\Logging::getLogDirectory() . '/debug-*.log') ?: [])`;

const PHP_LOG_BACKUP = `WP_CONTENT_DIR . '/e2e-log-backup'`;

/** Copies the plugin's log files aside, for tests that change them (see restoreLogFiles). */
export function backUpLogFiles(): void {
  wpEvalJson(`
    $backup = ${PHP_LOG_BACKUP};
    wp_mkdir_p($backup);
    array_map('unlink', glob("$backup/*") ?: []);
    foreach (${PHP_LOG_FILES} as $file) copy($file, "$backup/" . basename($file));
    return true;
  `);
}

/** Puts the plugin's log files back as backUpLogFiles() found them. */
export function restoreLogFiles(): void {
  wpEvalJson(`
    $backup = ${PHP_LOG_BACKUP};
    $dir = \\CommonKnowledge\\JoinBlock\\Logging::getLogDirectory();
    array_map('unlink', ${PHP_LOG_FILES});
    foreach (glob("$backup/*") ?: [] as $file) rename($file, "$dir/" . basename($file));
    return true;
  `);
}

/** Delivers an event to the plugin's Stripe webhook endpoint, as Stripe would. */
export async function postStripeWebhook(request: APIRequestContext, type: string, data: Record<string, unknown>) {
  const response = await request.post('/wp-json/join/v1/stripe/webhook', { data: { type, data } });
  expect(response.ok()).toBe(true);
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

/** A membership plan's fields on the settings page. Unset fields are left as they are. */
export type PlanFields = {
  name?: string;
  /** "Price Point ID". */
  id?: string;
  price?: string;
  allowCustomAmount?: boolean;
  frequency?: 'yearly' | 'monthly' | 'weekly';
  currency?: 'GBP' | 'EUR' | 'USD';
  description?: string;
  /** Comma-separated. */
  addTags?: string;
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

  /** Chooses an option of a select field by its value, e.g. 'live'. */
  async setSelect(label: string, value: string): Promise<void> {
    await this.field(label).selectOption(value);
  }

  /** Sets a colour field to a hex colour, e.g. '#336699', through its colour picker. */
  async setColor(label: string, hex: string): Promise<void> {
    const field = this.page.locator('.cf-field.cf-color').filter({
      has: this.page.locator('.cf-field__label', { hasText: exactly(label) }),
    });
    await field.locator('.cf-color__toggle').click();
    const hexInput = field.locator('.sketch-picker input').first();
    // Don't press Enter here: it submits the whole settings form.
    await hexInput.fill(hex.replace('#', ''));
    // Clicking outside closes the picker.
    await field.locator('.cf-field__label').click();
    await expect(this.page.locator('.cf-color__picker')).toHaveCount(0);
    await expect(field.locator('input[type="hidden"]')).toHaveValue(new RegExp(exactly(hex).source, 'i'));
  }

  /** Sets a rich text (editor) field's content to the given HTML. */
  async setRichText(label: string, html: string): Promise<void> {
    const editorId = await this.page
      .locator('.cf-container__fields .cf-field__label', { hasText: exactly(label) })
      .getAttribute('for');
    await this.page.waitForFunction((id) => (window as any).tinymce?.get(id), editorId);
    await this.page.evaluate(
      ({ id, content }) => {
        const editor = (window as any).tinymce.get(id);
        editor.setContent(content);
        editor.fire('change');
      },
      { id: editorId, content: html },
    );
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

  /** The global membership plans on the Membership Plans tab, one per plan. */
  plans(): Locator {
    return this.plansField().locator('.cf-complex__group');
  }

  private plansField(): Locator {
    return this.page
      .locator('.cf-container__fields .cf-field.cf-complex')
      .filter({ has: this.page.locator('.cf-field__label', { hasText: /^Membership Plans$/ }) });
  }

  /** Adds a membership plan at the end of the list and fills it in. Does not save. */
  async addPlan(plan: PlanFields): Promise<void> {
    const count = await this.plans().count();
    await this.plansField().locator('.cf-complex__inserter-button').click();
    await expect(this.plans()).toHaveCount(count + 1);
    await this.editPlan(count, plan);
  }

  /** Changes the given fields of the plan at `index` (0 is the first). Does not save. */
  async editPlan(index: number, plan: PlanFields): Promise<void> {
    const group = this.plans().nth(index);
    if (await group.evaluate((element) => element.classList.contains('cf-complex__group--collapsed'))) {
      await group.locator('.cf-complex__group-head').click();
    }
    const input = (label: string) =>
      group
        .locator(`.cf-field:has(.cf-field__label:text-matches("^${label}[*]?$"))`)
        .first()
        .locator('input:not([type="hidden"]), select, textarea')
        .first();

    if (plan.name !== undefined) await input('Name').fill(plan.name);
    if (plan.id !== undefined) await input('Price Point ID').fill(plan.id);
    if (plan.price !== undefined) await input('Price').fill(plan.price);
    if (plan.allowCustomAmount !== undefined) {
      await group.getByLabel('Allow users to change the amount', { exact: true }).setChecked(plan.allowCustomAmount);
    }
    if (plan.frequency !== undefined) await input('Frequency').selectOption(plan.frequency);
    if (plan.currency !== undefined) await input('Currency').selectOption(plan.currency);
    if (plan.description !== undefined) await input('Description').fill(plan.description);
    if (plan.addTags !== undefined) await input('Add Tags').fill(plan.addTags);
  }

  /** Removes the plan at `index` (0 is the first). Does not save. */
  async removePlan(index: number): Promise<void> {
    const count = await this.plans().count();
    await this.plans().nth(index).locator('button[title="Remove"]').click();
    await expect(this.plans()).toHaveCount(count - 1);
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

  /**
   * Checks the page will not save as it stands. It may not let the save be
   * made at all (today Save is disabled while a required field is empty; a
   * rebuilt page may leave it to the browser to block a field breaking its
   * own rules), or it may make the save and reject it with an error.
   *
   * A save refused before it is made says nothing about the plugin's own
   * checks: test those with saveSkippingPageChecksExpectingErrors().
   */
  async expectSaveRefused(): Promise<void> {
    const publish = this.page.locator('#publish');
    // Give the page a moment to disable Save after the last change.
    const disabled = await expect
      .poll(() => publish.isDisabled(), { timeout: 2000 })
      .toBe(true)
      .then(
        () => true,
        () => false,
      );
    if (disabled) {
      return;
    }
    const navigated = await Promise.all([
      this.page.waitForNavigation({ timeout: 5000 }).then(
        () => true,
        () => false,
      ),
      publish.click(),
    ]).then(([didNavigate]) => didNavigate);
    if (!navigated) {
      // The browser blocked the submit.
      return;
    }
    expect(await this.errors(), 'expected the settings page to refuse the save').not.toEqual([]);
  }

  /**
   * Saves with the page's own checks turned off (Save enabled, the browser's
   * field checks skipped), as a request made without the browser would be,
   * expecting the plugin to reject it. Returns its error messages.
   */
  async saveSkippingPageChecksExpectingErrors(): Promise<string[]> {
    await this.page.locator('#publish').evaluate((button: HTMLInputElement) => {
      button.disabled = false;
      if (button.form) button.form.noValidate = true;
    });
    return this.saveExpectingErrors();
  }

  private async submit(): Promise<void> {
    await Promise.all([this.page.waitForNavigation(), this.page.locator('#publish').click()]);
  }

  private async errors(): Promise<string[]> {
    const texts = await this.page.locator('.settings-error.error p, .settings-error.error li').allInnerTexts();
    return texts.map((text) => text.trim()).filter(Boolean);
  }
}
