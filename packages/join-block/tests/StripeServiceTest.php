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

    // -------------------------------------------------------------------------
    // getExpectedProductName — product prefix logic
    // -------------------------------------------------------------------------

    public function testStandardModeProductNameUsesMembershipPrefix(): void
    {
        $name = StripeService::getExpectedProductName($this->planWithoutCustomAmount, false);
        $this->assertSame('Membership: Tenner a month', $name);
    }

    public function testSupporterModeProductNameUsesDonationPrefix(): void
    {
        $name = StripeService::getExpectedProductName($this->planWithoutCustomAmount, true);
        $this->assertSame('Donation: Tenner a month', $name);
    }

    public function testProductNameIncludesPlanLabel(): void
    {
        $name = StripeService::getExpectedProductName($this->planWithCustomAmount, false);
        $this->assertSame('Membership: Flexible', $name);
    }

    public function testSupporterModeProductNameIncludesPlanLabel(): void
    {
        $name = StripeService::getExpectedProductName($this->planWithCustomAmount, true);
        $this->assertSame('Donation: Flexible', $name);
    }

    public function testStandardAndSupporterModeProduceDifferentNames(): void
    {
        $standard  = StripeService::getExpectedProductName($this->planWithoutCustomAmount, false);
        $supporter = StripeService::getExpectedProductName($this->planWithoutCustomAmount, true);
        $this->assertNotSame($standard, $supporter);
    }

    // -------------------------------------------------------------------------
    // resolveSubscriptionPriceStrategy — supporter mode preset tier
    //
    // When a supporter mode donor selects a preset tier without entering a
    // custom amount, strategy is 'default'. The subscription uses the plan's
    // stripe_price_id directly (which was created under the correctly-named
    // "Donation: X" product at plan-save time). No Stripe product rename occurs.
    // -------------------------------------------------------------------------

    public function testSupporterModePresetTierWithZeroCustomAmountUsesDefaultStrategy(): void
    {
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            0.0,
            true
        );

        // 'default' means the plan's stripe_price_id is used as-is.
        // The product was already named "Donation: X" at plan-save time.
        $this->assertSame('default', $strategy);
    }

    public function testSupporterModePresetTierDoesNotUseCustomSupporterStrategy(): void
    {
        // Regression guard: preset supporter tiers must NOT route through
        // getOrCreateDonationProduct() — that is only for custom amounts.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            0.0,
            true
        );

        $this->assertNotSame('custom_supporter', $strategy);
    }

    // -------------------------------------------------------------------------
    // resolveSubscriptionPriceStrategy — standard mode core flows
    //
    // Regression guard: ensure standard join flows are unaffected.
    // -------------------------------------------------------------------------

    public function testStandardModePresetTierUsesDefaultStrategy(): void
    {
        // No custom amount, allow_custom_amount false: use stripe_price_id as-is.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithoutCustomAmount,
            0.0,
            false
        );

        $this->assertSame('default', $strategy);
    }

    public function testStandardModeCustomAmountPlanUsesCustomPlanStrategy(): void
    {
        // allow_custom_amount true with a positive amount: create price under plan's own product.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithCustomAmount,
            25.0,
            false
        );

        $this->assertSame('custom_plan', $strategy);
    }

    public function testStandardModeCustomAmountDoesNotUseDonationProduct(): void
    {
        // Standard mode custom amounts must use the plan's product, not the shared Donation product.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithCustomAmount,
            25.0,
            false
        );

        $this->assertNotSame('custom_supporter', $strategy);
    }

    public function testStandardModeWithCustomAmountFlagButZeroAmountUsesDefault(): void
    {
        // allow_custom_amount true but no amount entered: fall back to stripe_price_id.
        $strategy = StripeService::resolveSubscriptionPriceStrategy(
            $this->planWithCustomAmount,
            0.0,
            false
        );

        $this->assertSame('default', $strategy);
    }

    // -------------------------------------------------------------------------
    // validateOneOffDonationAmount
    // -------------------------------------------------------------------------

    public function testValidAmountReturnsNull(): void
    {
        $this->assertNull(StripeService::validateOneOffDonationAmount(10.0));
    }

    public function testZeroAmountReturnsError(): void
    {
        $this->assertNotNull(StripeService::validateOneOffDonationAmount(0.0));
    }

    public function testNegativeAmountReturnsError(): void
    {
        $this->assertNotNull(StripeService::validateOneOffDonationAmount(-5.0));
    }

    public function testMaximumBoundaryIsValid(): void
    {
        $this->assertNull(StripeService::validateOneOffDonationAmount(10000.0));
    }

    public function testAboveMaximumReturnsError(): void
    {
        $this->assertNotNull(StripeService::validateOneOffDonationAmount(10000.01));
    }
}
