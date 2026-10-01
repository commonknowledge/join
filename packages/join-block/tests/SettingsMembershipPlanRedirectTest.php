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

    /**
     * The help text names the CRMs the site actually uses, so admins know
     * where these people will not show up.
     */
    private function withCrms(array $enabled): void
    {
        Monkey\Functions\when('carbon_get_theme_option')->alias(
            fn($key) => in_array($key, $enabled, true)
        );
    }

    private function helpText(string $notRecorded): string
    {
        return 'People who choose this plan are sent to the page below instead of paying. '
            . "They are not signed up as members$notRecorded. "
            . 'Has no effect when Donation Supporter Mode is enabled.';
    }

    public function test_help_text_names_no_crm_when_none_is_set_up()
    {
        $this->withCrms([]);

        $this->assertSame($this->helpText(''), Settings::getMembershipPlanRedirectHelpText());
    }

    public function test_help_text_names_the_one_crm_that_is_set_up()
    {
        $this->withCrms(['use_zetkin']);

        $this->assertSame(
            $this->helpText(' and not recorded in Zetkin'),
            Settings::getMembershipPlanRedirectHelpText()
        );
    }

    public function test_help_text_names_two_crms()
    {
        $this->withCrms(['use_action_network', 'use_mailchimp']);

        $this->assertSame(
            $this->helpText(' and not recorded in Action Network and Mailchimp'),
            Settings::getMembershipPlanRedirectHelpText()
        );
    }

    public function test_help_text_names_all_three_crms()
    {
        $this->withCrms(['use_action_network', 'use_mailchimp', 'use_zetkin']);

        $this->assertSame(
            $this->helpText(' and not recorded in Action Network, Mailchimp and Zetkin'),
            Settings::getMembershipPlanRedirectHelpText()
        );
    }
}
