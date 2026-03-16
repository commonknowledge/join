<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\StripeService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Stripe webhook data-extraction helpers.
 *
 * These methods are pure functions (no WordPress, no HTTP) so they can be
 * exercised without a running WordPress environment.
 *
 * Manual verification steps are documented at the bottom of this file.
 */
class StripeWebhookTest extends TestCase
{
    // ------------------------------------------------------------------ //
    //  extractPersonDataFromStripeCustomer                               //
    // ------------------------------------------------------------------ //

    public function testExtractPersonDataFullCustomer(): void
    {
        $customer = [
            'name'  => 'Jane Smith',
            'phone' => '+441234567890',
            'address' => [
                'line1'       => '10 Downing Street',
                'line2'       => 'Westminster',
                'city'        => 'London',
                'state'       => 'England',
                'postal_code' => 'SW1A 2AA',
                'country'     => 'GB',
            ],
        ];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Jane', $result['first_name']);
        $this->assertSame('Smith', $result['last_name']);
        $this->assertSame('+441234567890', $result['phone']);
        $this->assertSame('10 Downing Street', $result['street_address']);
        $this->assertSame('Westminster', $result['co_address']);
        $this->assertSame('London', $result['city']);
        $this->assertSame('SW1A 2AA', $result['zip_code']);
        $this->assertSame('GB', $result['country']);
    }

    public function testExtractPersonDataSingleWordName(): void
    {
        $customer = ['name' => 'Cher', 'phone' => null, 'address' => []];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Cher', $result['first_name']);
        $this->assertArrayNotHasKey('last_name', $result);
    }

    public function testExtractPersonDataMultiWordLastName(): void
    {
        $customer = ['name' => 'Mary Jane Watson', 'phone' => null, 'address' => []];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Mary', $result['first_name']);
        $this->assertSame('Jane Watson', $result['last_name']);
    }

    public function testExtractPersonDataOmitsNullAndEmptyValues(): void
    {
        $customer = [
            'name'    => null,
            'phone'   => '',
            'address' => ['line1' => '', 'city' => null],
        ];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertEmpty($result);
    }

    public function testExtractPersonDataMissingAddress(): void
    {
        $customer = ['name' => 'Alex Jones', 'phone' => '+4400000'];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Alex', $result['first_name']);
        $this->assertSame('Jones', $result['last_name']);
        $this->assertSame('+4400000', $result['phone']);
        $this->assertArrayNotHasKey('street_address', $result);
        $this->assertArrayNotHasKey('city', $result);
    }

    // ------------------------------------------------------------------ //
    //  extractMailchimpMergeFieldsFromStripeCustomer                     //
    // ------------------------------------------------------------------ //

    public function testExtractMergeFieldsFullCustomer(): void
    {
        $customer = [
            'name'  => 'John Doe',
            'phone' => '+441234567890',
            'address' => [
                'line1'       => '1 Test Street',
                'line2'       => '',
                'city'        => 'Bristol',
                'state'       => '',
                'postal_code' => 'BS1 1AA',
                'country'     => 'GB',
            ],
        ];

        $result = StripeService::extractMailchimpMergeFieldsFromStripeCustomer($customer);

        $this->assertSame('John', $result['FNAME']);
        $this->assertSame('Doe', $result['LNAME']);
        $this->assertSame('+441234567890', $result['PHONE']);
        $this->assertArrayHasKey('ADDRESS', $result);
        $this->assertSame('1 Test Street', $result['ADDRESS']['addr1']);
        $this->assertSame('Bristol', $result['ADDRESS']['city']);
        $this->assertSame('BS1 1AA', $result['ADDRESS']['zip']);
        $this->assertSame('GB', $result['ADDRESS']['country']);
    }

    public function testExtractMergeFieldsNoAddressLine1OmitsAddressField(): void
    {
        $customer = [
            'name'    => 'Pat Brown',
            'phone'   => null,
            'address' => ['line1' => '', 'city' => 'Leeds'],
        ];

        $result = StripeService::extractMailchimpMergeFieldsFromStripeCustomer($customer);

        $this->assertArrayNotHasKey('ADDRESS', $result);
        $this->assertSame('Pat', $result['FNAME']);
        $this->assertSame('Brown', $result['LNAME']);
        $this->assertArrayNotHasKey('PHONE', $result);
    }

    public function testExtractMergeFieldsEmptyCustomer(): void
    {
        $result = StripeService::extractMailchimpMergeFieldsFromStripeCustomer([]);

        $this->assertEmpty($result);
    }

    public function testExtractMergeFieldsSingleWordName(): void
    {
        $customer = ['name' => 'Prince', 'address' => ['line1' => '1 Purple Rain Rd']];

        $result = StripeService::extractMailchimpMergeFieldsFromStripeCustomer($customer);

        $this->assertSame('Prince', $result['FNAME']);
        $this->assertArrayNotHasKey('LNAME', $result);
    }
}

/*
 * =============================================================================
 * MANUAL TEST PLAN — Stripe webhook sync to Zetkin and Mailchimp
 * =============================================================================
 *
 * Prerequisites
 * -------------
 * 1. Stripe CLI installed: https://stripe.com/docs/stripe-cli
 * 2. WordPress running locally with USE_ZETKIN and USE_MAILCHIMP enabled
 * 3. Stripe test mode with a customer in both Zetkin and Mailchimp
 *
 * Setup: forward webhooks to local WordPress
 * ------------------------------------------
 *   stripe listen --forward-to https://<your-local-wp>/wp-json/join/v1/stripe/webhook
 *
 * Test 1 — Contact detail update (customer.updated)
 * --------------------------------------------------
 * a. In Stripe Dashboard (test mode), open a customer and change their name,
 *    phone number, or address.
 * b. Confirm `customer.updated` appears in the stripe CLI listener output.
 * c. Verify in Zetkin: the person's record reflects the updated fields.
 * d. Verify in Mailchimp: the member's FNAME / LNAME / PHONE / ADDRESS
 *    merge fields are updated.
 *
 * Test 2 — Email change (customer.updated with email in previous_attributes)
 * --------------------------------------------------------------------------
 * a. In Stripe Dashboard, change the customer's email address.
 * b. Confirm the person in Zetkin is found (by old email) and their email
 *    field is updated.
 * c. Confirm the Mailchimp member is updated (old subscriber hash used for
 *    lookup, new email set in the update payload).
 *
 * Test 3 — Subscription reactivation (customer.subscription.updated)
 * ------------------------------------------------------------------
 * a. Using `stripe trigger customer.subscription.updated` (with a crafted
 *    payload), simulate a status change from `incomplete` → `active`.
 * b. Confirm the lapsed tag is REMOVED from Zetkin and Mailchimp.
 *
 * Test 4 — Subscription goes unpaid (customer.subscription.updated)
 * -----------------------------------------------------------------
 * a. Simulate a status change from `active` → `unpaid`.
 * b. Confirm the lapsed tag is ADDED to Zetkin and Mailchimp.
 *
 * Test 5 — Backfill command
 * -------------------------
 *   wp join backfill_stripe_to_zetkin_mailchimp --dry-run
 *
 * a. Confirm output lists all Stripe customers with emails, no writes.
 *   wp join backfill_stripe_to_zetkin_mailchimp
 * b. Verify a sample of customer records in Zetkin and Mailchimp reflect
 *    current Stripe data.
 *
 * Test 6 — No-op for unrelated events
 * ------------------------------------
 * a. Trigger a Stripe event unrelated to the above (e.g. `charge.succeeded`).
 * b. Confirm no errors in the WordPress log and no changes to Zetkin / Mailchimp.
 *
 * Test 7 — Graceful handling of unknown customer
 * -----------------------------------------------
 * a. Send a `customer.updated` event with an email that does not exist in
 *    Zetkin or Mailchimp.
 * b. Confirm a WARNING is logged (not an error), and the process completes
 *    without throwing.
 * =============================================================================
 */
