# Common Knowledge Join Flow

## Overview

This is a monorepo containing packages that together provide a full membership and donation management system, delivered as a WordPress plugin.

- `packages/join-flow` — A React application implementing the join form frontend.
- `packages/join-block` — A WordPress Gutenberg plugin containing the join form block(s) and the backend logic that processes memberships, payments, and CRM integrations.
- `packages/join-e2e` — End-to-end tests (Playwright).

**Current version:** 1.4.9

---

## How does the Join Flow work?

1. A visitor lands on a WordPress page with the **Join Form** block. They enter their email address and are directed to the join form itself.
2. The **Join Form Fullscreen Takeover** block hosts the React application. It takes over the full page and guides the visitor through a multi-step form: personal details, membership plan selection, optional donation, and payment.
3. On submission the React app POSTs to a WordPress REST API endpoint provided by the plugin. The backend orchestrates all downstream services: it creates the payment, sets up the subscription or one-off charge, and syncs the new member to any configured integrations (Auth0, Zetkin, Action Network, Mailchimp).
4. On success the visitor is redirected to a configurable success page.

---

## WordPress Blocks

| Block | Purpose |
|---|---|
| **Join Form** | Lightweight entry point — captures an email address and links to the full form page. |
| **Join Form Fullscreen Takeover** | Hosts the full multi-step React join flow. Add this to one page to enable membership sign-up. |

All copy within both blocks is fully configurable — nothing is hardcoded.

---

## Form Flows

The join flow supports three distinct modes, controlled by environment variables:

### Standard join flow

The default. Steps:

1. **Details** — name, email, phone, address (with optional postcode lookup), date of birth, nationality, county, "how did you hear about us?", contact consent, and any configured custom fields.
2. **Membership plan** — choose a tier from the configured plans.
3. **Donation** (optional) — offer an additional one-off or recurring donation on top of membership.
4. **Payment** — select a payment method and complete payment.
5. **Confirmation** — review and submit.

### Supporter / donation-focused mode (`REACT_APP_DONATION_SUPPORTER_MODE`)

Reorders and simplifies the flow to: Donation → Details → Payment. The membership amount equals the donation amount; no separate plan selection step is shown.

### Update flow mode (`REACT_APP_IS_UPDATE_FLOW`)

For existing members updating their membership or payment details. The email is pre-filled via a URL parameter and the details step is skipped.

---

## Payment Providers

The plugin supports three payment providers. They can be enabled independently and combined (e.g. Stripe for cards and GoCardless for Direct Debit).

### Stripe

- Recurring subscriptions (card and Direct Debit)
- One-off donations via PaymentIntent (£0.01–£10,000)
- Customer upsert (creates or looks up existing customers)
- Subscription metadata (plan, organisation)
- Test and Live mode
- Environment variable: `REACT_APP_USE_STRIPE=true`

### GoCardless

- Recurring Direct Debit (UK/EU bank payments)
- Supports both Hosted Pages mode and API mode (requires GoCardless Pro account — `REACT_APP_USE_GOCARDLESS_API=true`)
- Automatic customer and mandate creation
- Sandbox and Live environments
- Environment variable: `REACT_APP_USE_GOCARDLESS=true`

### Chargebee

- Subscription and billing management (multi-currency)
- Hosted Pages mode for PCI compliance
- API mode for direct customer/subscription creation
- Environment variable: `REACT_APP_USE_CHARGEBEE=true`

---

## Integrations

All integrations are optional. Enable and configure them via environment variables or the WordPress settings page.

| Integration | Purpose |
|---|---|
| **Auth0** | Creates user accounts with email, name, and role assignment on successful join. Requires an M2M application — see [Auth0 Setup](#auth0-setup). |
| **Zetkin** | Adds members to campaigns, applies plan-specific tags, syncs custom fields (DOB, "hear about us", contact preferences). |
| **Action Network** | Adds or updates people in Action Network; applies and removes tags; syncs custom fields. |
| **Mailchimp** | Adds subscribers to mailing lists with plan-specific tags. Manages "lapsed" tag on payment failure/recovery. **Supporter-mode forms**: the flow does not collect a postal address, so the Mailchimp audience must have the `ADDRESS` merge field marked optional (not required); otherwise Mailchimp will reject new-member requests with a 400. |
| **Sentry** | Real-time error tracking for both frontend and backend. |
| **Google Cloud Logging** | Centralised log aggregation. |
| **Loggly** | Centralised log aggregation. When a customer token is set, logs are sent to Loggly and are **not** written to disk. |
| **Microsoft Teams** | Error alert notifications via incoming webhook. |
| **getAddress.io / ideal-postcodes** | UK postcode address lookup. |

---

## Membership Lapsing

The plugin handles member lapsing automatically in response to payment provider webhooks.

Two distinct end states are tracked, each with its own configurable tag (WP Admin > CK Join Flow > Membership Plans, or the `LAPSED_TAG` / `CANCELLED_TAG` / `LAPSING_TAG` env vars):

- **Lapsed** (default tag "Lapsed - failed payment"): the subscription ended because payments failed. Triggered by a final `invoice.payment_failed` with no retry scheduled, a subscription entering `unpaid` or `incomplete_expired`, or a `customer.subscription.deleted` whose `cancellation_details.reason` is `payment_failed`.
- **Cancelled** (default tag "Cancelled"): the member deliberately ended their subscription. Triggered by any other `customer.subscription.deleted`, including `cancellation_requested`, a `cancel_at_period_end` expiry, and `payment_disputed`.
- **Lapsing** (default tag "Lapsing"): transient, applied while payments are failing but retries are still scheduled. Cleared when the member recovers, lapses or cancels.

Lapsed and cancelled are mutually exclusive: applying one removes the other. A subscription returning to `active`, a paid invoice, or a successful re-join clears all three tags. Status is reflected in all configured integrations (Action Network, Mailchimp, Zetkin).

---

## Developer Hooks

The plugin exposes filters and actions for customisation without modifying plugin code.

### Filters

#### `ck_join_flow_should_lapse_member`

Controls whether a member should be lapsed when a lapsing event is detected.

| Argument | Type | Description |
|---|---|---|
| `$default` | bool | `true` by default |
| `$email` | string | The member's email address |
| `$context` | array | `provider` (e.g. `stripe`), `trigger` (e.g. `invoice_payment_failed`), `event` (raw payload) |

```php
add_filter('ck_join_flow_should_lapse_member', function ($default, $email, $context) {
    if (($context['provider'] ?? '') === 'gocardless') {
        return false;
    }
    return $default;
}, 10, 3);
```

#### `ck_join_flow_should_unlapse_member`

Controls whether a member should be unlapsed when a reactivation event is detected.

| Argument | Type | Description |
|---|---|---|
| `$default` | bool | `true` by default |
| `$email` | string | The member's email address |
| `$context` | array | Same shape as above |

#### `ck_join_flow_should_cancel_member`

Controls whether a member should be tagged as cancelled when a deliberate cancellation is detected. Same arguments as `ck_join_flow_should_lapse_member`; `trigger` is `subscription_deleted`.

#### `ck_join_flow_add_tags`

Customise tags applied across all integrations.

| Argument | Type | Description |
|---|---|---|
| `$tags` | array | Tags to apply |
| `$data` | array | Form submission data |
| `$service` | string | Service name (e.g. `action_network`, `mailchimp`) |

#### `ck_join_flow_action_network_add_tags` / `ck_join_flow_action_network_remove_tags`

Action Network-specific tag overrides.

### Actions

#### `ck_join_flow_success`

Fired after a successful join.

| Argument | Type | Description |
|---|---|---|
| `$data` | array | Submitted form data |
| `$customer` | mixed | Customer object from the payment provider |

#### `ck_join_flow_error`

Fired when the join process encounters an error.

| Argument | Type | Description |
|---|---|---|
| `$data` | array | Submitted form data |
| `$exception` | \Throwable | The exception that was thrown |

#### `ck_join_flow_member_lapsed`

Fired after a member has been successfully marked as lapsed.

| Argument | Type | Description |
|---|---|---|
| `$email` | string | The member's email address |
| `$context` | array | Trigger context (see above) |

```php
add_action('ck_join_flow_member_lapsed', function ($email, $context) {
    // e.g. send an internal notification or update a CRM
}, 10, 2);
```

#### `ck_join_flow_member_unlapsed`

Fired after a member has been successfully unmarked as lapsed.

| Argument | Type | Description |
|---|---|---|
| `$email` | string | The member's email address |
| `$context` | array | Trigger context (see above) |

Reactivation always goes through this path, whether the member had previously lapsed or cancelled.

#### `ck_join_flow_member_cancelled`

Fired after a member has been successfully marked as cancelled. Same arguments as `ck_join_flow_member_lapsed`.

---

## Auth0 Setup

Create an Auth0 machine-to-machine application and authorise it for the Auth0 Management API.

1. Go to **Applications > APIs > Auth0 Management API > Machine to Machine Applications**.
2. Authorise your application.
3. Expand the authorisation and add the following scopes: `read:users`, `update:users`, `create:users`, `delete:users`.

---

## Configuration Reference

Configuration is available through a WordPress settings page (**Settings > CK Join Flow**) or via environment variables. The `.env.example` files in each package list all available variables.

### Key frontend environment variables

| Variable | Description |
|---|---|
| `REACT_APP_ORGANISATION_NAME` | Displayed throughout the form |
| `REACT_APP_ORGANISATION_BANK_NAME` | Shown on Direct Debit pages |
| `REACT_APP_ORGANISATION_EMAIL_ADDRESS` | Contact email shown in the form |
| `REACT_APP_MEMBERSHIP_PLANS` | JSON array of membership plan objects |
| `REACT_APP_USE_STRIPE` | Enable Stripe |
| `REACT_APP_STRIPE_PUBLISHABLE_KEY` | Stripe public key |
| `REACT_APP_STRIPE_DIRECT_DEBIT_ONLY` | Restrict Stripe to Direct Debit only |
| `REACT_APP_USE_GOCARDLESS` | Enable GoCardless |
| `REACT_APP_USE_GOCARDLESS_API` | Use GoCardless API mode (requires Pro account) |
| `REACT_APP_USE_CHARGEBEE` | Enable Chargebee |
| `REACT_APP_CHARGEBEE_SITE_NAME` | Chargebee site name |
| `REACT_APP_CHARGEBEE_API_PUBLISHABLE_KEY` | Chargebee public key |
| `REACT_APP_USE_MAILCHIMP` | Enable Mailchimp integration |
| `REACT_APP_CREATE_AUTH0_ACCOUNT` | Enable Auth0 user creation |
| `REACT_APP_COLLECT_DATE_OF_BIRTH` | Collect date of birth |
| `REACT_APP_COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT` | Collect contact consent |
| `REACT_APP_ASK_FOR_ADDITIONAL_DONATION` | Show donation page |
| `REACT_APP_DONATION_SUPPORTER_MODE` | Enable supporter/donation-focused flow |
| `REACT_APP_IS_UPDATE_FLOW` | Enable existing-member update flow |
| `REACT_APP_USE_POSTCODE_LOOKUP` | Enable UK postcode address lookup |
| `REACT_APP_INCLUDE_SKIP_PAYMENT_BUTTON` | Allow skipping payment |
| `REACT_APP_HIDE_ZERO_PRICE_DISPLAY` | Hide £0 price labels |
| `REACT_APP_USE_TEST_DATA` | Pre-fill form with test data |
| `REACT_APP_SENTRY_DSN` | Sentry DSN for frontend error tracking |

### Membership plans format

```json
[
  {
    "value": "standard",
    "label": "Standard",
    "amount": "10",
    "frequency": "monthly",
    "currency": "GBP",
    "allowCustomAmount": false,
    "add_tags": ["member"],
    "remove_tags": []
  }
]
```

### Key backend environment variables

| Variable | Description |
|---|---|
| `STRIPE_SECRET_KEY` | Stripe secret key |
| `GC_ACCESS_TOKEN` | GoCardless API token |
| `GC_ENVIRONMENT` | `sandbox` or `live` |
| `CHARGEBEE_SITE_NAME` | Chargebee site name |
| `CHARGEBEE_API_KEY` | Chargebee API key |
| `AUTH0_DOMAIN` | Auth0 tenant domain |
| `AUTH0_CLIENT_ID` | Auth0 M2M app client ID |
| `AUTH0_CLIENT_SECRET` | Auth0 M2M app client secret |
| `AUTH0_MANAGEMENT_AUDIENCE` | Auth0 Management API identifier |
| `MICROSOFT_TEAMS_INCOMING_WEBHOOK` | Teams webhook URL for error alerts |
| `SENTRY_DSN` | Sentry DSN for backend error tracking |
| `GOOGLE_CLOUD_PROJECT_ID` | Google Cloud project ID |
| `GOOGLE_CLOUD_KEY_FILE_CONTENTS` | Google Cloud service account credentials (JSON) |
| `LOGGLY_TOKEN` | Loggly customer token. When set, logs go to Loggly instead of disk |
| `LOGGLY_TAGS` | Comma-separated Loggly tags (default `join-block`) |
| `DEBUG_JOIN_FLOW` | Set to `true` to load frontend from `localhost:3000` |

---

## Tech Stack

### Frontend

- React 16, React Bootstrap
- React Hook Form, Yup (form state and validation)
- Stripe.js / `@stripe/react-stripe-js`
- Chargebee.js
- Webpack 5, Babel 7, SASS
- Jest, Testing Library

### Backend

- PHP 8+, WordPress
- Carbon Fields (admin UI)
- Stripe PHP SDK, GoCardless PHP SDK, Chargebee PHP SDK
- Auth0 PHP SDK
- Mailchimp Marketing SDK
- GuzzleHTTP, Monolog, Google Cloud Logging
- PHPUnit, Brain Monkey, Mockery

### Infrastructure

- Lerna (monorepo)
- Docker Compose (local WordPress environment)
- GitHub Actions (CI/CD, WordPress.org releases)

---

## Build and Deployment

### Build

```bash
npm install
npm run composer
npm run build
```

This produces a deployable WordPress plugin in `packages/join-block`.

### Deploying to WordPress.org (automated)

Releases are automated via GitHub Actions (`.github/workflows/release-plugin.yml`).

1. In the PR that should ship, bump the version and add changelog entries:

```bash
./scripts/bump-version.sh           # auto-increments patch
./scripts/bump-version.sh 1.4.0     # or specify a version
```

2. Commit the bump to the PR:

```bash
git add -A
git commit -m "Bump version to 1.4.0"
```

3. Merge the PR.

On every push to `master`, the workflow reads the version from `packages/join-block/join.php`. If no tag exists for it yet, it runs the PHP, Jest and E2E tests, builds the plugin, deploys it to the WordPress.org plugin repository, then creates the tag and the GitHub Release. If the tag already exists, nothing is released. Monitor progress in the Actions tab.

The workflow fails before releasing if the version in `join.php`, the `Stable tag:` in `readme.txt` and the `release:` in `packages/join-flow/src/index.tsx` disagree, or if `readme.txt` has no changelog entry for the version. Run `./scripts/check-version.sh` to check this locally.

Don't push tags by hand: a tag push no longer triggers a release, and a hand-made tag would make the workflow skip that version. If a release fails, use "Re-run failed jobs" on the run. Re-running is safe: the deploy skips a version already on WordPress.org, and the tag is only created after the deploy succeeds.

### Manual deployment

```bash
npm install && npm run composer && npm run build
sh scripts/package.sh
```

Upload the resulting zip to a WordPress site and activate the plugin.

> **Note:** The packaging script removes `vendor/giggsey/libphonenumber-for-php/src/geocoding` to stay under the 10 MB WordPress.org plugin size limit.

---

## Developer Quickstart

### Full stack (WordPress + React)

**Prerequisites:** Node.js >= 18, Yarn, Composer, Docker

```bash
# Install dependencies
npm install
npm run composer

# Configure the frontend
cd packages/join-flow
cp .env.example .env
# Edit .env — add API keys and enable desired features

# Start the Docker stack
cd ../..
npm start
```

- WordPress admin: `http://localhost:8082/wp-admin`
- React dev server (live reload): `http://localhost:3000`

**WordPress setup:**

1. Go to `http://localhost:8082/wp-admin/plugins.php` and activate the **Join** plugin.
2. Add the **Join Form Fullscreen Takeover** block to a page — this is where the join flow lives.
3. Optionally add the **Join Form** block elsewhere to pre-fill the email and link through.
4. Configure credentials and feature flags at **Settings > CK Join Flow**.

### Frontend only (no backend)

```bash
npm install
cd packages/join-flow
cp .env.example .env
npm start
# Open http://localhost:3000
```

Use `REACT_APP_USE_TEST_DATA=true` in `.env` to pre-fill the form with example data.

---

## Testing

### Frontend unit tests

```bash
cd packages/join-flow
npm test
```

### Frontend formatting

CI fails if `packages/join-flow` isn't formatted with Prettier. To fix formatting, from the repository root:

```bash
npm run prettier
```

### Backend unit tests

```bash
cd packages/join-block
./vendor/bin/phpunit
```

### Backend linting

CI runs phpcs and PHPStan on `packages/join-block` and fails on any phpcs error or PHPStan error. Run both locally with:

```bash
cd packages/join-block
composer lint
```

`phpstan-baseline.neon` lists errors that existed before PHPStan ran in CI. Fix new errors rather than adding them to the baseline.

### End-to-end tests

The Playwright suite in `packages/join-e2e` runs against a real WordPress site in Docker, defined in `packages/join-e2e/docker-compose.yml`: WordPress (Apache), MariaDB, and a WP-CLI container for one-off commands. The plugin (`packages/join-block`) is mounted into it, so its Composer dependencies must be installed first. The containers need no network access of their own.

```bash
cd packages/join-e2e
npm run env:start    # WordPress on http://localhost:8889 (admin / password)
npm test             # builds the test bundle, seeds the test pages, runs the suite
```

`npm test` runs `scripts/seed.sh` first, which builds the join-flow bundle with test data and runs `scripts/setup.php` to create the test pages. Other commands:

| Command | What it does |
|---|---|
| `npm run env:stop` | Stop the containers, keeping the database |
| `npm run env:destroy` | Remove the containers and all data, for a fresh install |
| `bash scripts/env.sh wp <command>` | Run WP-CLI, e.g. `bash scripts/env.sh wp option get home` |
| `npx playwright test tests/render.spec.ts` | Run one spec (after seeding) |
| `npx playwright show-report` | Open the HTML report of the last run |

To use an already-installed Chromium instead of Playwright's download, set `PLAYWRIGHT_CHROMIUM_EXECUTABLE` to its path. Claude Code cloud sessions set this automatically.

CI runs the same setup (`.github/workflows/e2e.yml`). See [docs/e2e-testing.md](docs/e2e-testing.md) for what the suite covers.

#### Maintenance: WordPress version

The suite tests against one pinned WordPress release: the `wordpress:<version>-php8.2-apache` and `wordpress:cli-<version>-php8.2` image tags in `packages/join-e2e/docker-compose.yml`. When WordPress releases a new version:

1. Bump the WordPress image tag (and the WP-CLI tag if a newer WP-CLI image exists).
2. Run `npm run env:destroy && npm run env:start && npm test` locally, or let CI run on the PR.
3. Fix anything the new version breaks before merging.

The PHP version in the tags (8.2) matches the plugin's minimum and the PHP version used in CI and `composer.json`; change them together.

### Claude Code cloud sessions

`.claude/hooks/session-start.sh` runs at the start of each Claude Code cloud session (and does nothing elsewhere). It installs the npm and Composer dependencies and starts Docker, so all three test suites can run. Two things are specific to that environment:

- **Composer:** the session's GitHub proxy refuses package tarball downloads for third-party repositories. `seed-composer-cache.sh` builds each locked package's zip from a shallow git fetch of the locked commit and places it in Composer's cache, so `composer install` works as normal. It reads `composer.lock`, so it needs no updating when dependencies change.
- **Network allowlist:** the environment needs `wordpress.org`, `api.github.com` and `codeload.github.com` allowed, plus Docker Hub and the default package managers.
