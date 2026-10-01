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
}
