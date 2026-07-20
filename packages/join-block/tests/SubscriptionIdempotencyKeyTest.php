<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\StripeService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StripeService::buildSubscriptionIdempotencyKey().
 *
 * Context: the payment page calls /stripe/create-subscription unconditionally
 * on every submit, so a member who hits an error and presses submit again gets
 * a second subscription and a second charge. Observed in production on
 * 2026-06-19, where one member was charged twice 91 seconds apart.
 *
 * The key must therefore be *stable* across a resubmit of the same choices in
 * the same form session, and *distinct* whenever the member has genuinely asked
 * for something different.
 */
class SubscriptionIdempotencyKeyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $plan = [
        'label'     => 'Cost of a cup of coffee in your country',
        'frequency' => 'monthly',
        'currency'  => 'USD',
    ];

    private array $data = [
        'sessionToken'           => '11111111-2222-3333-4444-555555555555',
        'email'                  => 'member@example.com',
        'customMembershipAmount' => null,
        'donationAmount'         => null,
        'recurDonation'          => false,
        'donationSupporterMode'  => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Monkey\Functions\when('sanitize_title')->alias(function ($title) {
            $title = strtolower((string) $title);
            $title = preg_replace('/[^a-z0-9]+/', '-', $title);
            return trim($title, '-');
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function key(array $overrides = []): ?string
    {
        return StripeService::buildSubscriptionIdempotencyKey(
            array_merge($this->data, $overrides),
            $this->plan
        );
    }

    // -------------------------------------------------------------------------
    // Stability — the double-charge case
    // -------------------------------------------------------------------------

    public function testResubmittingTheSameChoicesProducesTheSameKey(): void
    {
        $this->assertSame($this->key(), $this->key());
    }

    public function testEmailIsNormalisedSoCasingDoesNotSplitTheKey(): void
    {
        $this->assertSame(
            $this->key(),
            $this->key(['email' => '  Member@Example.COM '])
        );
    }

    // -------------------------------------------------------------------------
    // Distinctness — a deliberate second subscription must still be possible
    // -------------------------------------------------------------------------

    public function testANewFormSessionProducesADifferentKey(): void
    {
        $this->assertNotSame(
            $this->key(),
            $this->key(['sessionToken' => '99999999-8888-7777-6666-555555555555'])
        );
    }

    public function testChangingTheCustomAmountProducesADifferentKey(): void
    {
        $this->assertNotSame(
            $this->key(['customMembershipAmount' => 5]),
            $this->key(['customMembershipAmount' => 10])
        );
    }

    public function testChangingTheDonationAmountProducesADifferentKey(): void
    {
        $this->assertNotSame(
            $this->key(['donationAmount' => 5]),
            $this->key(['donationAmount' => 20])
        );
    }

    public function testTogglingRecurringDonationProducesADifferentKey(): void
    {
        $this->assertNotSame(
            $this->key(['donationAmount' => 5, 'recurDonation' => false]),
            $this->key(['donationAmount' => 5, 'recurDonation' => true])
        );
    }

    public function testTogglingSupporterModeProducesADifferentKey(): void
    {
        $this->assertNotSame(
            $this->key(['donationSupporterMode' => false]),
            $this->key(['donationSupporterMode' => true])
        );
    }

    public function testADifferentPlanProducesADifferentKey(): void
    {
        $usdKey = StripeService::buildSubscriptionIdempotencyKey($this->data, $this->plan);
        $gbpKey = StripeService::buildSubscriptionIdempotencyKey(
            $this->data,
            array_merge($this->plan, ['currency' => 'GBP'])
        );

        $this->assertNotSame($usdKey, $gbpKey);
    }

    public function testADifferentMemberProducesADifferentKey(): void
    {
        $this->assertNotSame(
            $this->key(),
            $this->key(['email' => 'someone-else@example.com'])
        );
    }

    // -------------------------------------------------------------------------
    // Safety valve
    // -------------------------------------------------------------------------

    /**
     * Without a session token the key would collapse to email + plan, so two
     * genuinely separate joins by the same person would collide and the second
     * would silently receive the first subscription. Returning null keeps the
     * old (duplicate-prone) behaviour, which is the lesser failure.
     */
    public function testMissingSessionTokenYieldsNoKeyRatherThanAWeakOne(): void
    {
        $this->assertNull($this->key(['sessionToken' => '']));

        $withoutToken = $this->data;
        unset($withoutToken['sessionToken']);
        $this->assertNull(
            StripeService::buildSubscriptionIdempotencyKey($withoutToken, $this->plan)
        );
    }

    public function testKeyFitsWithinStripesIdempotencyKeyLimit(): void
    {
        $this->assertLessThanOrEqual(255, strlen((string) $this->key()));
    }
}
