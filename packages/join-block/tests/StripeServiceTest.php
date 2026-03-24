<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\StripeService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeService pure logic helpers.
 *
 * resolveSubscriptionPriceStrategy() is a pure function with no Stripe API
 * calls, making it fully testable without a live Stripe environment.
 */
class StripeServiceTest extends TestCase
{
    private array $planWithCustomAmount = [
        'allow_custom_amount' => true,
        'amount'              => 10,
        'stripe_price_id'     => 'price_default',
        'currency'            => 'GBP',
        'frequency'           => 'monthly',
        'label'               => 'Flexible',
    ];

    private array $planWithoutCustomAmount = [
        'allow_custom_amount' => false,
        'amount'              => 10,
        'stripe_price_id'     => 'price_default',
        'currency'            => 'GBP',
        'frequency'           => 'monthly',
        'label'               => 'Tenner a month',
    ];

    // -------------------------------------------------------------------------
    // Supporter mode — custom amount
    // -------------------------------------------------------------------------

    public function testSupporterModeWithCustomAmountReturnsSupporterStrategy(): void
    {
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            69.0,
            true
        );

        $this->assertSame('custom_supporter', $strategy);
    }

    public function testSupporterModeWithCustomAmountUsesGenericProductRegardlessOfPlanFlag(): void
    {
        // Even when allow_custom_amount is false on the plan, supporter mode
        // should still route to the generic Donation product.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            100.0,
            true
        );

        $this->assertSame('custom_supporter', $strategy);
    }

    public function testSupporterModeWithZeroCustomAmountReturnsDefault(): void
    {
        // Zero / null custom amount should fall through to the plan's default price.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            0.0,
            true
        );

        $this->assertSame('default', $strategy);
    }

    // -------------------------------------------------------------------------
    // Standard mode — plan with allow_custom_amount
    // -------------------------------------------------------------------------

    public function testStandardModeWithAllowCustomAmountReturnsCustomPlanStrategy(): void
    {
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithCustomAmount,
            25.0,
            false
        );

        $this->assertSame('custom_plan', $strategy);
    }

    public function testStandardModeWithCustomAmountUsesCustomPlanNotDonationProduct(): void
    {
        // Standard flow must NOT use the generic Donation product — it should
        // create a price under the plan's own product.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithCustomAmount,
            25.0,
            false
        );

        $this->assertNotSame('custom_supporter', $strategy);
    }

    // -------------------------------------------------------------------------
    // Standard mode — plan without allow_custom_amount
    // -------------------------------------------------------------------------

    public function testStandardModeWithoutCustomAmountFlagReturnsDefault(): void
    {
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            25.0,
            false
        );

        $this->assertSame('default', $strategy);
    }

    public function testStandardModeWithZeroAmountReturnsDefault(): void
    {
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithCustomAmount,
            0.0,
            false
        );

        $this->assertSame('default', $strategy);
    }
}
