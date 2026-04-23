<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\MailchimpService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MailchimpService::buildMergeFields.
 *
 * Exercises the pure merge-field construction extracted from
 * MailchimpService::signup. Running these in isolation avoids the need
 * to mock Mailchimp's HTTP client.
 */
class MailchimpServiceTest extends TestCase
{
    /**
     * Full join-flow data (with address) produces all four merge fields
     * with populated sub-fields. Characterises the behaviour that existed
     * before the supporter-mode address fix and must not regress.
     */
    public function testStandardFlowDataProducesAllMergeFields(): void
    {
        $data = [
            'isUpdateFlow'    => '',
            'firstName'       => 'Test',
            'lastName'        => 'Person',
            'phoneNumber'     => '07123456789',
            'addressLine1'    => '1 Test Street',
            'addressLine2'    => 'Flat 2',
            'addressCity'     => 'London',
            'addressPostcode' => 'SW1A 1AA',
            'addressCountry'  => 'GB',
        ];

        $result = MailchimpService::buildMergeFields($data);

        $this->assertSame('Test', $result['FNAME']);
        $this->assertSame('Person', $result['LNAME']);
        $this->assertSame('07123456789', $result['PHONE']);
        $this->assertSame([
            'addr1'   => '1 Test Street',
            'addr2'   => 'Flat 2',
            'city'    => 'London',
            'state'   => '',
            'zip'     => 'SW1A 1AA',
            'country' => 'GB',
        ], $result['ADDRESS']);
    }

    /**
     * Update flow still yields an empty merge-fields array regardless of
     * what else is in the data.
     */
    public function testUpdateFlowReturnsEmptyMergeFields(): void
    {
        $data = [
            'isUpdateFlow' => true,
            'firstName'    => 'Test',
            'lastName'     => 'Person',
        ];

        $this->assertSame([], MailchimpService::buildMergeFields($data));
    }

    /**
     * Supporter-flow data has no address keys at all — those fields are
     * never collected in supporter mode. The resulting merge fields must
     * contain FNAME and LNAME only, with no ADDRESS or PHONE block. Before
     * this fix, buildMergeFields included an ADDRESS sub-array of nulls,
     * which Mailchimp rejected with a 400 "Invalid Resource" when the
     * audience had ADDRESS marked required — the member was never created
     * and tags were never applied. Regression test for that signup path.
     */
    public function testSupporterFlowDataOmitsMissingFields(): void
    {
        $data = [
            'isUpdateFlow' => '',
            'firstName'    => 'Test',
            'lastName'     => 'Person',
            'phoneNumber'  => '',
        ];

        $result = MailchimpService::buildMergeFields($data);

        $this->assertSame('Test', $result['FNAME']);
        $this->assertSame('Person', $result['LNAME']);
        $this->assertArrayNotHasKey('ADDRESS', $result);
        $this->assertArrayNotHasKey('PHONE', $result);
    }

    /**
     * Partial address data (addressLine1 missing) must not emit an ADDRESS
     * merge field at all. Either we have a meaningful postal address to
     * send or we don't send the field.
     */
    public function testPartialAddressOmitsAddressBlock(): void
    {
        $data = [
            'isUpdateFlow'    => '',
            'firstName'       => 'Test',
            'lastName'        => 'Person',
            'phoneNumber'     => '',
            'addressCity'     => 'London',
            'addressPostcode' => 'SW1A 1AA',
        ];

        $result = MailchimpService::buildMergeFields($data);

        $this->assertArrayNotHasKey('ADDRESS', $result);
    }

    /**
     * Empty-string and missing-key inputs must produce identical merge
     * fields. Defensive against PHP-8 implicit-null behaviour that lets
     * nulls reach Mailchimp's JSON payload.
     */
    public function testEmptyStringInputsMatchMissingKeyInputs(): void
    {
        $withMissingKeys = MailchimpService::buildMergeFields([
            'isUpdateFlow' => '',
            'firstName'    => 'Test',
            'lastName'     => 'Person',
            'phoneNumber'  => '',
        ]);

        $withEmptyStrings = MailchimpService::buildMergeFields([
            'isUpdateFlow'    => '',
            'firstName'       => 'Test',
            'lastName'        => 'Person',
            'phoneNumber'     => '',
            'addressLine1'    => '',
            'addressLine2'    => '',
            'addressCity'     => '',
            'addressPostcode' => '',
            'addressCountry'  => '',
        ]);

        $this->assertSame($withMissingKeys, $withEmptyStrings);
    }

    /**
     * A donor with a full postal address but no phone number must receive
     * ADDRESS while PHONE is omitted. The two fields are gated independently.
     */
    public function testAddressEmittedWhenPhoneMissing(): void
    {
        $data = [
            'isUpdateFlow'    => '',
            'firstName'       => 'Test',
            'lastName'        => 'Person',
            'phoneNumber'     => '',
            'addressLine1'    => '1 Test Street',
            'addressLine2'    => 'Flat 2',
            'addressCity'     => 'London',
            'addressPostcode' => 'SW1A 1AA',
            'addressCountry'  => 'GB',
        ];

        $result = MailchimpService::buildMergeFields($data);

        $this->assertArrayNotHasKey('PHONE', $result);
        $this->assertArrayHasKey('ADDRESS', $result);
        $this->assertSame('1 Test Street', $result['ADDRESS']['addr1']);
    }

    /**
     * When addressLine1 is present but the optional sub-fields (addressLine2
     * and the rest) are missing from $data, each optional sub-field must
     * fall through to an empty string rather than null, so Mailchimp never
     * sees a null in the ADDRESS payload.
     */
    public function testAddressSubFieldsDefaultToEmptyStringWhenMissing(): void
    {
        $data = [
            'isUpdateFlow' => '',
            'firstName'    => 'Test',
            'lastName'     => 'Person',
            'phoneNumber'  => '',
            'addressLine1' => '1 Test Street',
        ];

        $result = MailchimpService::buildMergeFields($data);

        $this->assertSame([
            'addr1'   => '1 Test Street',
            'addr2'   => '',
            'city'    => '',
            'state'   => '',
            'zip'     => '',
            'country' => '',
        ], $result['ADDRESS']);
    }

    /**
     * Custom fields from customFieldsConfig are uppercased, non-letter
     * characters stripped, and the value passed through verbatim.
     */
    public function testCustomFieldsAreUppercasedAndIncluded(): void
    {
        $data = [
            'isUpdateFlow'    => '',
            'firstName'       => 'Test',
            'lastName'        => 'Person',
            'phoneNumber'     => '',
            'addressLine1'    => '1 Test Street',
            'addressLine2'    => '',
            'addressCity'     => 'London',
            'addressPostcode' => 'SW1A 1AA',
            'addressCountry'  => 'GB',
            'customFieldsConfig' => [
                ['id' => 'date-of-birth'],
                ['id' => 'how_heard'],
            ],
            'date-of-birth' => '1990-01-01',
            'how_heard'     => 'Friend',
        ];

        $result = MailchimpService::buildMergeFields($data);

        $this->assertSame('1990-01-01', $result['DATE_OF_BIRTH']);
        $this->assertSame('Friend', $result['HOW_HEARD']);
    }
}
