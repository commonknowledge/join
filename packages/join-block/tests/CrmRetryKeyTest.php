<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\JoinService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JoinService::crmRetryKey().
 *
 * The key names the WordPress option holding a member's outstanding CRM push.
 * That record is the only copy of what they submitted, so two members sharing a
 * key means one silently overwrites the other and their details are gone.
 */
class CrmRetryKeyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('wp_json_encode')->alias('json_encode');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Preferred identifiers
    // -------------------------------------------------------------------------

    public function testPrefersTheStripeSubscriptionId(): void
    {
        $key = JoinService::crmRetryKey([
            'stripeSubscriptionId' => 'sub_123',
            'email' => 'member@example.com',
        ]);

        $this->assertSame(JoinService::CRM_RETRY_OPTION_PREFIX . 'sub_123', $key);
    }

    public function testFallsBackToEmailWhenThereIsNoSubscription(): void
    {
        $withEmail = JoinService::crmRetryKey(['email' => 'member@example.com']);
        $other     = JoinService::crmRetryKey(['email' => 'someone-else@example.com']);

        $this->assertStringStartsWith(JoinService::CRM_RETRY_OPTION_PREFIX . 'email_', $withEmail);
        $this->assertNotSame($withEmail, $other);
    }

    public function testEmailIsNormalisedSoOneMemberGetsOneRecord(): void
    {
        $this->assertSame(
            JoinService::crmRetryKey(['email' => 'member@example.com']),
            JoinService::crmRetryKey(['email' => '  Member@Example.COM '])
        );
    }

    // -------------------------------------------------------------------------
    // Collision resistance — the reason the fallback chain exists
    // -------------------------------------------------------------------------

    /**
     * handleJoin() can be called without an email (it falls back to
     * sessionToken for its lock), so this is reachable. Hashing an empty string
     * would give every such record the same option name, and each queued member
     * would overwrite the last.
     */
    public function testTwoRecordsWithNoSubscriptionOrEmailDoNotCollide(): void
    {
        $first  = JoinService::crmRetryKey(['sessionToken' => 'session-aaa']);
        $second = JoinService::crmRetryKey(['sessionToken' => 'session-bbb']);

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith(JoinService::CRM_RETRY_OPTION_PREFIX . 'session_', $first);
    }

    public function testBlankIdentifiersAreTreatedAsAbsentNotHashed(): void
    {
        $first = JoinService::crmRetryKey([
            'stripeSubscriptionId' => '',
            'email' => '   ',
            'sessionToken' => 'session-aaa',
        ]);
        $second = JoinService::crmRetryKey([
            'stripeSubscriptionId' => '',
            'email' => '',
            'sessionToken' => 'session-bbb',
        ]);

        $this->assertNotSame($first, $second);
    }

    /**
     * With no identifier at all, distinct payloads must still get distinct
     * records. Identical payloads collapsing to one is correct de-duplication.
     */
    public function testWithNoIdentifiersAtAllDistinctPayloadsStillDiffer(): void
    {
        $first  = JoinService::crmRetryKey(['firstName' => 'Ada']);
        $second = JoinService::crmRetryKey(['firstName' => 'Grace']);

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith(JoinService::CRM_RETRY_OPTION_PREFIX . 'payload_', $first);
        $this->assertSame($first, JoinService::crmRetryKey(['firstName' => 'Ada']));
    }

    /**
     * The degenerate case the fallback chain was added for: two entirely empty
     * payloads are indistinguishable, but must not take a key that a real
     * member's record could also land on.
     */
    public function testEmptyPayloadDoesNotShareAKeyWithAnIdentifiedMember(): void
    {
        $empty      = JoinService::crmRetryKey([]);
        $identified = JoinService::crmRetryKey(['email' => 'member@example.com']);

        $this->assertNotSame($empty, $identified);
    }

    public function testKeyFitsWithinTheWordPressOptionNameColumn(): void
    {
        $key = JoinService::crmRetryKey(['sessionToken' => str_repeat('x', 500)]);

        $this->assertLessThanOrEqual(191, strlen($key));
    }
}
