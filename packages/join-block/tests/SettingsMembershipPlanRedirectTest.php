<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Settings;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A membership plan can send people to a chosen page instead of taking
 * payment. Settings::getMembershipPlanRedirectUrl() turns the plan's fields
 * into the URL the join form redirects to, or null when the plan takes
 * payment as normal.
 */
class SettingsMembershipPlanRedirectTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('get_page_link')->alias(fn($id) => "https://example.org/?page_id=$id");
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function plan(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Student',
            'amount' => '5',
            'frequency' => 'monthly',
            'currency' => 'GBP',
        ], $overrides);
    }

    public function test_returns_the_chosen_page_for_a_redirecting_plan()
    {
        $plan = $this->plan([
            'redirect_instead_of_payment' => true,
            'redirect_page' => [['id' => 42, 'type' => 'post', 'subtype' => 'page']],
        ]);

        $this->assertSame('https://example.org/?page_id=42', Settings::getMembershipPlanRedirectUrl($plan));
    }

    public function test_returns_null_for_a_plan_that_takes_payment()
    {
        $this->assertNull(Settings::getMembershipPlanRedirectUrl($this->plan()));
    }

    /**
     * Unticking the box has to switch the redirect off even though Carbon
     * Fields keeps the previously chosen page.
     */
    public function test_returns_null_when_the_box_is_unticked_but_a_page_is_still_set()
    {
        $plan = $this->plan([
            'redirect_instead_of_payment' => false,
            'redirect_page' => [['id' => 42, 'type' => 'post', 'subtype' => 'page']],
        ]);

        $this->assertNull(Settings::getMembershipPlanRedirectUrl($plan));
    }

    /**
     * Ticked with no page chosen falls back to taking payment, rather than
     * redirecting somewhere nobody picked.
     */
    public function test_returns_null_when_the_box_is_ticked_but_no_page_is_chosen()
    {
        $plan = $this->plan([
            'redirect_instead_of_payment' => true,
            'redirect_page' => [],
        ]);

        $this->assertNull(Settings::getMembershipPlanRedirectUrl($plan));
    }
}
