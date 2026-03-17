<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\StripeService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Stripe webhook data-extraction helpers.
 *
 * These methods are pure functions (no WordPress, no HTTP) so they can be
 * exercised without a running WordPress environment.
 */
class StripeWebhookTest extends TestCase
{
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

    public function testExtractPersonDataHyphenatedLastName(): void
    {
        $customer = ['name' => 'Sarah Lloyd-Jones', 'phone' => null, 'address' => []];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Sarah', $result['first_name']);
        $this->assertSame('Lloyd-Jones', $result['last_name']);
    }

    public function testExtractPersonDataNameParticle(): void
    {
        $customer = ['name' => 'Ludwig van Beethoven', 'phone' => null, 'address' => []];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Ludwig', $result['first_name']);
        $this->assertSame('van Beethoven', $result['last_name']);
    }

    public function testExtractPersonDataForeignCharacters(): void
    {
        $customer = ['name' => 'Ångström Müller', 'phone' => null, 'address' => []];

        $result = StripeService::extractPersonDataFromStripeCustomer($customer);

        $this->assertSame('Ångström', $result['first_name']);
        $this->assertSame('Müller', $result['last_name']);
    }

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

    public function testExtractMergeFieldsHyphenatedLastName(): void
    {
        $customer = ['name' => 'Anne-Marie Davies-Smith', 'address' => []];

        $result = StripeService::extractMailchimpMergeFieldsFromStripeCustomer($customer);

        $this->assertSame('Anne-Marie', $result['FNAME']);
        $this->assertSame('Davies-Smith', $result['LNAME']);
    }
}
