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
