<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Settings;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Income guidance shows income bands against membership plans on the plan
 * step. It is off unless an add-on switches it on with the
 * ck_join_flow_income_guidance_enabled filter.
 */
class SettingsIncomeGuidanceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_off_by_default()
    {
        $this->assertFalse(Settings::isIncomeGuidanceEnabled());
    }

    public function test_an_add_on_can_switch_it_on()
    {
        Monkey\Filters\expectApplied('ck_join_flow_income_guidance_enabled')
            ->once()
            ->with(false)
            ->andReturn(true);

        $this->assertTrue(Settings::isIncomeGuidanceEnabled());
    }

    public function test_a_truthy_filter_result_counts_as_on()
    {
        Monkey\Filters\expectApplied('ck_join_flow_income_guidance_enabled')->andReturn('1');

        $this->assertTrue(Settings::isIncomeGuidanceEnabled());
    }

    private function switchOn(): void
    {
        Monkey\Filters\expectApplied('ck_join_flow_income_guidance_enabled')->andReturn(true);
    }

    private function withSettings(array $settings): void
    {
        Monkey\Functions\when('carbon_get_theme_option')->alias(fn($key) => $settings[$key] ?? null);
        Monkey\Functions\when('wpautop')->alias(fn($text) => $text === '' ? '' : "<p>$text</p>");
    }

    public function test_plans_carry_no_income_bands_when_off()
    {
        $plan = ['label' => 'Higher', 'income_weekly' => '£600 to £800', 'income_monthly' => '£2,400 to £3,200'];

        $this->assertSame([], Settings::getMembershipPlanIncomeBands($plan));
    }

    public function test_plans_carry_their_income_bands_when_on()
    {
        $this->switchOn();
        $plan = ['label' => 'Higher', 'income_weekly' => ' £600 to £800 ', 'income_monthly' => '£2,400 to £3,200'];

        $this->assertSame(
            ['incomeWeekly' => '£600 to £800', 'incomeMonthly' => '£2,400 to £3,200'],
            Settings::getMembershipPlanIncomeBands($plan)
        );
    }

    public function test_a_plan_without_bands_carries_empty_ones_when_on()
    {
        $this->switchOn();

        $this->assertSame(
            ['incomeWeekly' => '', 'incomeMonthly' => ''],
            Settings::getMembershipPlanIncomeBands(['label' => 'Other'])
        );
    }

    public function test_the_form_is_only_told_it_is_off_when_off()
    {
        $this->withSettings(['income_guidance_heading' => 'Heading', 'income_guidance_note' => 'Note']);

        $this->assertSame(['INCOME_GUIDANCE_ENABLED' => false], Settings::getIncomeGuidanceEnv());
    }

    public function test_the_form_gets_the_heading_and_note_when_on()
    {
        $this->switchOn();
        $this->withSettings([
            'income_guidance_heading' => 'Suggested dues by income',
            'income_guidance_note' => "Can't afford dues? Email us.",
        ]);

        $this->assertSame([
            'INCOME_GUIDANCE_ENABLED' => true,
            'INCOME_GUIDANCE_HEADING' => 'Suggested dues by income',
            'INCOME_GUIDANCE_NOTE' => "<p>Can't afford dues? Email us.</p>",
        ], Settings::getIncomeGuidanceEnv());
    }

    public function test_an_unset_note_is_sent_empty()
    {
        $this->switchOn();
        $this->withSettings(['income_guidance_heading' => 'Suggested dues by income']);

        $this->assertSame('', Settings::getIncomeGuidanceEnv()['INCOME_GUIDANCE_NOTE']);
    }
}
