<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Settings;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Settings::getMembershipPlan() following the slug-format
 * change in v1.4.4 (commit 0161e59).
 *
 * Before v1.4.4, getMembershipPlanId() returned `sanitize_title($plan['label'])`,
 * so plans were saved to the WP options table under keys like:
 *
 *     ck_join_flow_membership_plan_low-wage-payment-level
 *
 * v1.4.4 changed the slug format to include frequency and currency:
 *
 *     ck_join_flow_membership_plan_low-wage-payment-level_monthly_gbp
 *
 * The frontend dropdown now submits the new-format ID, but options saved
 * before the upgrade still live under the old key. Until an admin opens
 * the Carbon Fields settings page and re-saves (which re-keys the options
 * via Settings::saveMembershipPlans()), every /stripe/create-subscription
 * call throws "Selected plan is not in the list of plans, this is unexpected"
 * and no one can buy a membership.
 *
 * These tests pin the self-healing fallback: getMembershipPlan() must locate
 * a plan whose recomputed ID matches the requested ID, even when the option
 * is stored under a stale (legacy-format) key.
 */
class SettingsMembershipPlanLookupTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // sanitize_title in WP lowercases and replaces non-alphanumerics with
        // hyphens. A faithful-enough stand-in for our purposes.
        Monkey\Functions\when('sanitize_title')->alias(function ($title) {
            $title = strtolower((string) $title);
            $title = preg_replace('/[^a-z0-9]+/', '-', $title);
            return trim($title, '-');
        });

        // maybe_unserialize: WP returns the value unchanged if not a serialized
        // string. Our $wpdb stub already returns arrays, so passthrough is fine.
        Monkey\Functions\when('maybe_unserialize')->returnArg();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * The bug, distilled: a plan saved under the legacy slug
     * `low-wage-payment-level` cannot be found by the new-format ID
     * `low-wage-payment-level_monthly_gbp`, so create-subscription throws.
     *
     * Mirrors the production failure on tenantsunion.org.uk on 2026-05-01.
     */
    public function testFindsPlanSavedUnderLegacySlugByNewFormatId(): void
    {
        $newFormatId = 'low-wage-payment-level_monthly_gbp';
        $legacyOptionName = 'ck_join_flow_membership_plan_low-wage-payment-level';

        $storedPlan = [
            'label'           => 'Low Wage Payment Level',
            'frequency'       => 'monthly',
            'currency'        => 'GBP',
            'amount'          => 5,
            'stripe_price_id' => 'price_1SUUcWKspBtY4V5GYVE0YfiA',
        ];

        // Direct lookup under the new-format key misses (option doesn't exist).
        Monkey\Functions\expect('get_option')
            ->with('ck_join_flow_membership_plan_' . $newFormatId)
            ->andReturn(false);

        // Fallback: scan options table for any ck_join_flow_membership_plan_*
        // and recompute each plan's ID. The legacy row is what we want to find.
        $this->stubWpdbWithRows([
            ['option_value' => $storedPlan],
        ]);

        $plan = Settings::getMembershipPlan($newFormatId);

        $this->assertIsArray($plan, 'Expected fallback to recover legacy-keyed plan');
        $this->assertSame('price_1SUUcWKspBtY4V5GYVE0YfiA', $plan['stripe_price_id']);
        $this->assertSame('Low Wage Payment Level', $plan['label']);
    }

    /**
     * Direct hit must remain the fast path: when the option exists under the
     * canonical new-format key, no $wpdb scan should be needed.
     */
    public function testReturnsDirectMatchWithoutScanningOptionsTable(): void
    {
        $id = 'low-wage-payment-level_monthly_gbp';
        $storedPlan = [
            'label'           => 'Low Wage Payment Level',
            'frequency'       => 'monthly',
            'currency'        => 'GBP',
            'stripe_price_id' => 'price_direct',
        ];

        Monkey\Functions\expect('get_option')
            ->once()
            ->with('ck_join_flow_membership_plan_' . $id)
            ->andReturn($storedPlan);

        // No $wpdb stub: if the implementation tries to scan, the test will
        // blow up on the missing global, proving the fast path was skipped.
        global $wpdb;
        $wpdb = null;

        $plan = Settings::getMembershipPlan($id);

        $this->assertSame('price_direct', $plan['stripe_price_id']);
    }

    /**
     * If no option exists and no stored plan recomputes to the requested ID,
     * the function must return a falsy value rather than a stale plan.
     * Guards against the fallback returning the wrong tier when slugs collide.
     */
    public function testReturnsFalsyWhenNoStoredPlanMatches(): void
    {
        Monkey\Functions\expect('get_option')
            ->andReturn(false);

        $this->stubWpdbWithRows([
            ['option_value' => [
                'label'     => 'Some Other Plan',
                'frequency' => 'yearly',
                'currency'  => 'EUR',
            ]],
        ]);

        $plan = Settings::getMembershipPlan('low-wage-payment-level_monthly_gbp');

        $this->assertEmpty($plan);
    }

    /**
     * Stub global $wpdb so getMembershipPlan's fallback can iterate options.
     * Returns whatever rows are passed in regardless of the SQL/LIKE pattern —
     * the production query already filters to ck_join_flow_membership_plan_*.
     */
    private function stubWpdbWithRows(array $rows): void
    {
        global $wpdb;
        $wpdb = Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn($rows);
    }
}
