# End-to-End Testing Plan

## Overview

Join ships with a Playwright end-to-end test suite that exercises the React join flow inside a real WordPress environment. Tests run against a wp-env (Docker WordPress) instance seeded with purpose-built test pages.

### Test approach

The suite validates the join form's UI behaviour and the data contracts it sends to the backend. It does not exercise live payment provider APIs. Instead, it uses helpers that simulate the outcome of payment flows (redirect-based success) and captures the `/join` request body to assert correctness.

This is a deliberate trade-off: payment provider SDKs require live credentials, load scripts from CDNs, and introduce external redirect flows that are fragile in CI. By mocking the REST layer and simulating payment completion, the tests stay fast, deterministic, and credential-free while still covering the most important contract: what the frontend sends to the backend.

### Infrastructure

| Component | Location | Purpose |
|-----------|----------|---------|
| Test suite | `packages/join-e2e/tests/` | Playwright spec files |
| Helpers | `packages/join-e2e/tests/helpers.ts` | Shared utilities for mocking, environment injection, and payment simulation |
| Seed script | `packages/join-e2e/scripts/setup.php` | Creates test pages with specific block configurations in WordPress |
| Playwright config | `packages/join-e2e/playwright.config.ts` | Single Chromium project, serial execution, base URL `localhost:8889` |

### Key helpers

**`mockRestEndpoints(page)`** intercepts `/wp-json/join/v1/step` and `/wp-json/join/v1/join` with empty 200 responses so network errors never block form progression.

**`injectEnvOverrides(page, urlPattern, overrides)`** intercepts the page HTML response and patches the `<script id="env">` JSON block before the browser receives it. This lets tests toggle feature flags (e.g. `USE_STRIPE`, `STRIPE_DIRECT_DEBIT_ONLY`) without changing WordPress plugin settings.

Note: this approach only affects what the frontend receives. The WordPress database and PHP backend still hold the real plugin settings. Any backend behaviour that reads those settings directly (e.g. server-side feature gating, REST endpoint logic) will not be affected by overrides applied here. For true end-to-end coverage of backend-gated behaviour, the settings would need to be written to the database directly, for example via `wp option update` in the seed script.

**`captureJoinBodyViaStripeRedirect(page, pageUrl)`** simulates a completed Stripe payment by injecting a fake `stripePaymentIntentId` into `sessionStorage` and reloading with `?stripe_success=true`. The app detects this and jumps to the confirm stage. The helper intercepts the resulting `/join` POST and returns its body for assertions.

### Test data

Building with `USE_TEST_DATA=true` pre-fills all personal detail fields (name, email, phone, address). This lets tests skip manual field entry when the details page is not the focus.

### Seed pages

The setup script (`scripts/setup.php`) creates these WordPress pages:

| Slug | Configuration |
|------|---------------|
| `e2e-standard-join` | Standard membership, GBP 5/month |
| `e2e-free-join` | Free membership, GBP 0/month |
| `e2e-donation-upsell` | Standard membership with `ask_for_additional_donation` enabled |
| `e2e-supporter` | Supporter mode with 3 tiers (GBP 5, 10, 20/month) |
| `e2e-supporter-custom` | Supporter mode with custom amount allowed |
| `e2e-supporter-no-plans` | Supporter mode with no plans (triggers warning UI) |


## What is covered

The suite currently contains 52 passing tests across 7 spec files.

| Spec file | Area | Tests | What it verifies |
|-----------|------|------:|------------------|
| `render.spec.ts` | Rendering | 3 | Form container present, env script injected, first name input visible, no console errors, progress breadcrumb renders |
| `form-progression.spec.ts` | Form progression | 7 | Test data pre-fill, required field validation (name, email, phone, address), email format validation, country defaults to GB, details-to-plan advancement, plan selection, `/step` request body |
| `free-membership.spec.ts` | Free membership | 4 | Payment stages skipped for GBP 0 plans, confirm stage reached from plan, confirm page content, `/join` request body |
| `donation-upsell.spec.ts` | Donation upsell | 6 | Upsell page appears when enabled, "Not right now" skips to payment, tier selection advances, `recurDonation` flag toggling, supporter mode takes precedence over upsell |
| `supporter-mode-monthly.spec.ts` | Supporter monthly | 9 | Donation page is first step, breadcrumb text, monthly toggle default, tier selection updates CTA text, donation-to-details progression, `/join` body fields, custom amount input |
| `supporter-mode-oneoff.spec.ts` | Supporter one-off | 6 | One-off tab enabled under correct env flags, CTA text changes, `/join` body contains `recurDonation=false`, `paymentMethod=creditCard` forced, custom one-off amounts |
| `supporter-mode-edge-cases.spec.ts` | Edge cases | 7 | One-off disabled under `STRIPE_DIRECT_DEBIT_ONLY`, explanatory note shown, monthly still works with DD-only, "no amounts configured" warning, standard vs supporter `/join` body differences |

### Coverage strengths

- **Form navigation logic** is well covered across standard, free, supporter, and upsell flows.
- **Data contracts** (`/step` and `/join` request bodies) are asserted for all major flows.
- **Feature flag behaviour** is tested by injecting env overrides, covering combinations of `USE_STRIPE`, `STRIPE_DIRECT_DEBIT`, `STRIPE_DIRECT_DEBIT_ONLY`, and `ASK_FOR_ADDITIONAL_DONATION`.
- **Validation** for required fields and email format is exercised.


## What is not covered

### Payment UI rendering

The `captureJoinBodyViaStripeRedirect` helper bypasses the payment stage entirely. If Stripe Elements stopped rendering, the payment method selector broke, or the intent creation API call failed, the current tests would not detect it.

### GoCardless flow

Zero coverage. GoCardless is the primary payment method for Direct Debit members. The hosted redirect flow, API flow, and mandate creation are all untested. There is no equivalent of `captureJoinBodyViaStripeRedirect` for GoCardless.

### Chargebee flow

Zero coverage. Neither the hosted pages flow nor the API flow is tested.

### Update flow (`IS_UPDATE_FLOW=true`)

No tests. When `IS_UPDATE_FLOW=true`, personal details are skipped and the form starts at the plan stage, with the member's email pre-filled via URL parameter. This distinct entry point has no coverage.

### Payment method selection page

The `payment-method` step (choosing between Direct Debit and Card when multiple providers are enabled) is never exercised. Tests either mock everything or bypass payment.

### Optional data collection flags

The following flags control whether additional fields appear on the details page, but none are tested:

- `COLLECT_DATE_OF_BIRTH`
- `COLLECT_COUNTY`
- `COLLECT_HEAR_ABOUT_US`
- `COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT`

### Custom fields

`CUSTOM_FIELDS` allows arbitrary text, checkbox, number, select, and radio fields on the details page. Not tested.

### Address lookup

`USE_POSTCODE_LOOKUP` enables a postcode-to-address autocomplete. Not tested.

### `/join` response handling

Tests verify what is sent to `/join` but not what happens with the response. Success redirects, error message rendering, and retry behaviour are all unverified.

### Success redirect

The `SUCCESS_REDIRECT` / `joined_page` block setting controls post-join navigation. Not tested.

### Error states

Network errors, server validation errors, Stripe card declines, and GoCardless mandate failures have no coverage.

### Skip payment button

`INCLUDE_SKIP_PAYMENT_BUTTON` renders a "skip to thank you" button. Not tested.

### Backend integrations (out of scope)

Mailchimp, Action Network, Zetkin, Auth0, and lapsing/unlapsing webhooks are backend concerns. Mailchimp is covered by PHPUnit (`JoinServiceMailchimpTest.php`). These are not appropriate for frontend e2e tests.


## Future coverage workstreams

Ordered roughly by value and feasibility. Each item is designed to be tackled independently.

### 1. Update flow

**Value:** High. This is a distinct user-facing flow with no coverage at all.

**What is needed:**
- [ ] Seed a new test page (`e2e-update-flow`) via `setup.php`
- [ ] Use `injectEnvOverrides` to set `IS_UPDATE_FLOW=true`
- [ ] Pass an `email` URL parameter to pre-fill the member email
- [ ] Verify the form starts at the plan step (details page skipped)
- [ ] Verify the pre-filled email appears on the confirm page
- [ ] Assert the `/join` request body includes the URL-provided email

### 2. Optional data collection fields

**Value:** Medium. These are simple show/hide toggles but affect data sent to the backend.

**What is needed:**
- [ ] Use `injectEnvOverrides` to enable each flag individually
- [ ] Verify the corresponding input fields appear on the details page
- [ ] Fill in values and confirm they are included in the `/step` request body
- [ ] Verify fields are absent when the flag is disabled

Flags to cover: `COLLECT_DATE_OF_BIRTH`, `COLLECT_COUNTY`, `COLLECT_HEAR_ABOUT_US`, `COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT`.

### 3. Payment method selection page

**Value:** Medium. This is the gate between choosing Direct Debit vs Card when multiple providers are active.

**What is needed:**
- [ ] Use `injectEnvOverrides` to enable both `USE_GOCARDLESS=true` and `USE_STRIPE=true`
- [ ] Verify the payment-method step appears between donation/plan and payment-details
- [ ] Verify selecting "Direct Debit" and "Card" each advance to the correct next stage
- [ ] Assert the selected `paymentMethod` value is stored in session state

### 4. GoCardless redirect simulation

**Value:** High, but requires more infrastructure.

**What is needed:**
- [ ] Write a `captureJoinBodyViaGoCardlessRedirect` helper that injects a fake `billing_request_id` into session state and reloads with `?gocardless_success=true`
- [ ] Verify the confirm page renders and the `/join` request body includes GoCardless-specific fields
- [ ] Determine the exact query parameters and session keys the app expects on GoCardless return (check the `useGoCardless` hook or equivalent)

### 5. Chargebee redirect simulation

**Value:** Medium. Similar pattern to GoCardless.

**What is needed:**
- [ ] Write a `captureJoinBodyViaChargebeeRedirect` helper that simulates the Chargebee hosted page return
- [ ] Determine the expected query parameters on return (`?chargebee_success=true` or similar)
- [ ] Use `injectEnvOverrides` to set `USE_CHARGEBEE=true` (and optionally `USE_CHARGEBEE_HOSTED_PAGES=true`)
- [ ] Assert `/join` body includes Chargebee-specific fields

### 6. `/join` response handling and error states

**Value:** High for user experience confidence. Moderate implementation effort.

**What is needed:**
- [ ] Mock `/join` to return an error response (e.g. `{ success: false, message: "Email already registered" }`)
- [ ] Verify the error message is displayed to the user
- [ ] Mock `/join` to return a network error (use `route.abort()`)
- [ ] Verify appropriate error UI is shown
- [ ] Mock `/join` to return success and verify redirect to `SUCCESS_REDIRECT` URL

### 7. Success redirect

**Value:** Medium. Validates the post-join experience.

**What is needed:**
- [ ] Seed a test page with a `joined_page` / `SUCCESS_REDIRECT` pointing to a known URL
- [ ] Complete a flow (e.g. free membership) and verify `page.url()` matches the redirect target
- [ ] Test with and without a configured redirect

### 8. Custom fields

**Value:** Low-medium. Affects organisations that configure custom fields.

**What is needed:**
- [ ] Use `injectEnvOverrides` to inject a `CUSTOM_FIELDS` configuration with at least one of each type: text, checkbox, number, select, radio
- [ ] Verify each field renders on the details page
- [ ] Fill in values and verify they appear in the `/step` or `/join` request body
- [ ] Verify validation (e.g. required custom fields block progression)

### 9. Address lookup

**Value:** Low. Nice to have, but the feature depends on a third-party postcode API.

**What is needed:**
- [ ] Mock the postcode lookup API endpoint
- [ ] Use `injectEnvOverrides` to set `USE_POSTCODE_LOOKUP=true`
- [ ] Type a postcode and verify the autocomplete dropdown appears with mocked results
- [ ] Select an address and verify the address fields are populated

### 10. WordPress version compatibility

**Value:** Medium. The test suite currently pins WordPress to a specific version. Regressions caused by WordPress core updates would not be caught until the pin is moved.

**What is needed:**
- [ ] Configure the CI matrix to run the suite against the current stable WordPress release and the previous major version
- [ ] Update `.wp-env.json` when a new WordPress major is released and confirm the suite passes
- [ ] Consider running nightly or weekly against WordPress trunk to catch regressions before stable release

### 11. Skip payment button

**Value:** Low. Single feature flag toggle.

**What is needed:**
- [ ] Use `injectEnvOverrides` to set `INCLUDE_SKIP_PAYMENT_BUTTON=true`
- [ ] Verify the skip button appears on the payment page
- [ ] Click it and verify the user reaches the confirm page
- [ ] Assert the `/join` body reflects that payment was skipped


## Running the tests

```bash
# Start the wp-env environment
yarn wp-env start

# Seed the test pages
yarn wp-env run tests-cli wp eval-file /var/www/html/wp-content/e2e-scripts/setup.php

# Build the frontend with test data pre-fill
USE_TEST_DATA=true yarn build

# Run the suite
cd packages/join-e2e
npx playwright test

# Run a specific spec
npx playwright test tests/free-membership.spec.ts

# View the HTML report after a run
npx playwright show-report
```
