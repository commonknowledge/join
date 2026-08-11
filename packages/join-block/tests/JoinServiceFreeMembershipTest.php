<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Exceptions\JoinBlockException;
use CommonKnowledge\JoinBlock\Services\JoinService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a £0 membership plan (e.g. a subsidised membership) can be
 * joined with Stripe enabled but no Stripe subscription.
 *
 * The frontend skips the payment step entirely for a £0 plan with no
 * donation, so the join request carries no stripeSubscriptionId. The
 * subscription amount verification must treat that as "no payment expected"
 * rather than "cannot verify" — previously every subsidised join failed with
 * JoinBlockException code 9 ("Could not verify subscription amount").
 *
 * The protection the verification exists for must survive the exception:
 * a priced plan with no subscription ID is still an error, and a £0 plan
 * that DOES have a subscription (the free-plan-plus-donation path) is still
 * verified against the plan amount.
 *
 * Each test runs in a separate process so that Mockery's alias mock for the
 * static StripeService class takes effect before the autoloader loads the
 * real implementation.
 */
class JoinServiceFreeMembershipTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Minimal valid join data for the subsidised (£0) plan. No
     * stripeSubscriptionId: the frontend skipped the payment step.
     */
    private array $joinData = [
        'sessionToken'    => 'test-free-membership-token',
        'membership'      => 'subsidised',
        'email'           => 'test@example.com',
        'firstName'       => 'Test',
        'lastName'        => 'Person',
        'phoneNumber'     => '',
        'addressLine1'    => '1 Test Street',
        'addressCity'     => 'Manchester',
        'addressPostcode' => 'M1 1AA',
        'addressCountry'  => 'GB',
    ];

    /**
     * A £0 membership plan, as configured server-side for subsidised tiers.
     */
    private array $freePlan = [
        'label'               => 'Subsidised',
        'id'                  => 'subsidised',
        'amount'              => 0,
        'allow_custom_amount' => false,
        'frequency'           => 'monthly',
        'currency'            => 'GBP',
        'stripe_price_id'     => '',
        'add_tags'            => '',
    ];

    /**
     * A priced plan, to check the verification still guards paid tiers.
     */
    private array $paidPlan = [
        'label'               => 'Standard',
        'id'                  => 'standard',
        'amount'              => 5,
        'allow_custom_amount' => false,
        'frequency'           => 'monthly',
        'currency'            => 'GBP',
        'stripe_price_id'     => 'price_test',
        'add_tags'            => '',
    ];

    /** Options written during a test. */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->savedOptions = [];

        // WordPress functions used in the join path.
        Monkey\Functions\when('wp_json_encode')->alias('json_encode');
        Monkey\Functions\when('esc_html')->returnArg();
        // apply_filters($hook, $value, ...$args) — return $value (arg 2) unchanged.
        Monkey\Functions\when('apply_filters')->returnArg(2);
        Monkey\Functions\when('do_action')->justReturn(null);
        Monkey\Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir());

        // Stub $wpdb so Settings::computeTagsToRemove() can run (no other plans = nothing to remove).
        global $wpdb;
        $wpdb = new class {
            public string $options = 'wp_options';
            public function prepare(string $query, ...$args): string
            {
                return $query;
            }
            public function esc_like(string $text): string
            {
                return $text;
            }
            public function get_results(string $query, $output = null): array
            {
                return [];
            }
        };

        // Return the plans from wp_options so the membership validation passes.
        Monkey\Functions\when('get_option')
            ->alias(function (string $key) {
                if ($key === 'ck_join_flow_membership_plan_subsidised') {
                    return $this->freePlan;
                }
                if ($key === 'ck_join_flow_membership_plan_standard') {
                    return $this->paidPlan;
                }
                return $this->savedOptions[$key] ?? false;
            });

        Monkey\Functions\when('update_option')
            ->alias(function (string $key, $value) {
                $this->savedOptions[$key] = $value;
                return true;
            });

        Monkey\Functions\when('delete_option')
            ->alias(function (string $key) {
                unset($this->savedOptions[$key]);
                return true;
            });

        // USE_STRIPE is on; every other plugin setting is off/empty, so the
        // join reaches the Stripe block and then finishes without CRM calls.
        Monkey\Functions\when('carbon_get_theme_option')
            ->alias(function (string $key) {
                return $key === 'use_stripe' ? true : '';
            });

        global $joinBlockLog;
        $joinBlockLog = new class {
            public array $errors = [];

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function info(string $msg, array $ctx = []): void {}

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function warning(string $msg, array $ctx = []): void {}

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function error(string $msg, array $ctx = []): void
            {
                $this->errors[] = $msg;
            }
        };
    }

    protected function tearDown(): void
    {
        global $joinBlockLog, $wpdb;
        $joinBlockLog = null;
        $wpdb = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFreeMembershipJoinSucceedsWithoutStripeSubscription(): void
    {
        $stripe = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\StripeService');
        $stripe->shouldReceive('initialise')->once();

        // No subscription exists, so nothing must try to read one.
        $stripe->shouldNotReceive('getSubscriptionAmount');

        // The rest of the Stripe block still runs: the previous (paid)
        // subscriptions are cancelled — a member moving to the subsidised
        // plan should not keep being charged for the old one — and the
        // subscription dates are still read for the CRM custom fields.
        $stripe->shouldReceive('resolveCustomerId')
            ->once()
            ->with('test@example.com', null)
            ->andReturn('cus_test123');
        $stripe->shouldReceive('cancelPreviousSubscriptions')
            ->once()
            ->with('test@example.com', 'cus_test123', null);
        $stripe->shouldReceive('getSubscriptionDates')
            ->once()
            ->with('test@example.com', 'cus_test123')
            ->andReturn([
                'firstSubscription' => '2026-08-11',
                'firstPayment'      => null,
                'lastPayment'       => null,
            ]);

        try {
            JoinService::handleJoin($this->joinData);
        } catch (\Exception $e) {
            $this->fail('handleJoin threw for a free membership join: ' . $e->getMessage());
        }

        global $joinBlockLog;
        $this->assertEmpty($joinBlockLog->errors, 'Expected no errors for a free membership join');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPaidMembershipWithoutSubscriptionStillFails(): void
    {
        $stripe = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\StripeService');
        $stripe->shouldReceive('initialise')->once();

        // The real method logs a warning and returns null for a missing ID.
        $stripe->shouldReceive('getSubscriptionAmount')
            ->once()
            ->with(null)
            ->andReturn(null);

        // Verification failed, so nothing may be cancelled.
        $stripe->shouldNotReceive('cancelPreviousSubscriptions');

        $this->expectException(JoinBlockException::class);
        $this->expectExceptionCode(9);
        JoinService::handleJoin(array_merge($this->joinData, ['membership' => 'standard']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFreePlanWithSubscriptionIsStillVerified(): void
    {
        // The free-plan-plus-donation path: the payment step ran and created
        // a subscription whose first item is the £0 membership price. It must
        // take the normal verification path, and pass.
        $stripe = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\StripeService');
        $stripe->shouldReceive('initialise')->once();
        $stripe->shouldReceive('getSubscriptionAmount')
            ->once()
            ->with('sub_test123')
            ->andReturn(0.0);
        $stripe->shouldReceive('resolveCustomerId')
            ->once()
            ->with('test@example.com', null)
            ->andReturn('cus_test123');
        $stripe->shouldReceive('cancelPreviousSubscriptions')
            ->once()
            ->with('test@example.com', 'cus_test123', 'sub_test123');
        $stripe->shouldReceive('getSubscriptionDates')
            ->once()
            ->with('test@example.com', 'cus_test123')
            ->andReturn([
                'firstSubscription' => '2026-08-11',
                'firstPayment'      => null,
                'lastPayment'       => null,
            ]);

        try {
            JoinService::handleJoin(array_merge($this->joinData, ['stripeSubscriptionId' => 'sub_test123']));
        } catch (\Exception $e) {
            $this->fail('handleJoin threw for a free plan with a subscription: ' . $e->getMessage());
        }

        global $joinBlockLog;
        $this->assertEmpty($joinBlockLog->errors, 'Expected no errors when the subscription amount matches');
    }
}
