# Common Knowledge Join Flow

## Overview

This is a monorepo containing packages that together provide a full membership and donation management system, delivered as a WordPress plugin.

- `packages/join-flow` — A React application implementing the join form frontend.
- `packages/join-block` — A WordPress Gutenberg plugin containing the join form block(s) and the backend logic that processes memberships, payments, and CRM integrations.
- `packages/join-e2e` — End-to-end tests (Puppeteer).

**Current version:** 1.4.0

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
| **Mailchimp** | Adds subscribers to mailing lists with plan-specific tags. Manages "lapsed" tag on payment failure/recovery. |
| **Sentry** | Real-time error tracking for both frontend and backend. |
| **Google Cloud Logging** | Centralised log aggregation. |
| **Microsoft Teams** | Error alert notifications via incoming webhook. |
| **getAddress.io / ideal-postcodes** | UK postcode address lookup. |

---

## Membership Lapsing

The plugin handles member lapsing automatically in response to payment provider webhooks.

- A Stripe subscription entering `unpaid` or `incomplete_expired` triggers a lapse.
- A Stripe subscription returning to `active` triggers an unlapse.
- Lapsed/unlapsed status is reflected in all configured integrations (e.g. "lapsed" tag in Action Network, Mailchimp, Zetkin).

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
yarn
yarn composer
yarn build
```

This produces a deployable WordPress plugin in `packages/join-block`.

### Deploying to WordPress.org (automated)

Releases are automated via GitHub Actions (`.github/workflows/release-plugin.yml`).

1. Bump the version:

```bash
./scripts/bump-version.sh           # auto-increments patch
./scripts/bump-version.sh 1.4.0     # or specify a version
```

2. Commit and tag:

```bash
git add -A
git commit -m "Bump version to 1.4.0"
git tag 1.4.0
git push && git push --tags
```

The GitHub Action will build, package, and deploy to the WordPress.org plugin repository automatically. Monitor progress in the Actions tab.

### Manual deployment

```bash
yarn && yarn composer && yarn build
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
yarn
yarn composer

# Configure the frontend
cd packages/join-flow
cp .env.example .env
# Edit .env — add API keys and enable desired features

# Start the Docker stack
cd ../..
yarn start
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
yarn
cd packages/join-flow
cp .env.example .env
yarn run frontend
# Open http://localhost:3000
```

Use `REACT_APP_USE_TEST_DATA=true` in `.env` to pre-fill the form with example data.

---

## Testing

### Frontend unit tests

```bash
cd packages/join-flow
yarn test
```

### Backend unit tests

```bash
cd packages/join-block
composer test
```

### End-to-end tests

```bash
cd packages/join-e2e
yarn test
```
