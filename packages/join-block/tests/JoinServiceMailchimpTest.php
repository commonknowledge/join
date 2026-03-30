<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\JoinService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a Mailchimp failure during the join process is non-fatal.
 *
 * When the USE_MAILCHIMP setting is enabled and MailchimpService::signup()
 * throws an exception, the join should still complete successfully. The error
 * is logged but not re-thrown. This prevents a Mailchimp outage or bad address
 * from blocking a member from joining.
 *
 * Each test runs in a separate process so that Mockery's alias mock for the
 * static MailchimpService class takes effect before the autoloader loads the
 * real implementation.
 */
class JoinServiceMailchimpTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Minimal valid join data. No payment provider is enabled (all Settings
     * return false by default), so execution reaches the Mailchimp block
     * without any payment API calls.
     */
    private array $joinData = [
        'sessionToken'    => 'test-mailchimp-token',
        'membership'      => 'standard',
        'email'           => 'test@example.com',
        'firstName'       => 'Test',
        'lastName'        => 'Person',
        'phoneNumber'     => '',
        'addressLine1'    => '1 Test Street',
        'addressCity'     => 'London',
        'addressPostcode' => 'SW1A 1AA',
        'addressCountry'  => 'GB',
    ];

    /**
     * Minimal membership plan that satisfies JoinService validation.
     */
    private array $membershipPlan = [
        'label'              => 'Standard',
        'id'                 => 'standard',
        'amount'             => 5,
        'allow_custom_amount' => false,
        'frequency'          => 'monthly',
        'currency'           => 'GBP',
        'stripe_price_id'    => '',
        'add_tags'           => '',
        'remove_tags'        => '',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // WordPress functions used in the join path.
        Monkey\Functions\when('wp_json_encode')->alias('json_encode');
        Monkey\Functions\when('esc_html')->returnArg();
        // apply_filters($hook, $value, ...$args) — return $value (arg 2) unchanged.
        Monkey\Functions\when('apply_filters')->returnArg(2);
        Monkey\Functions\when('do_action')->justReturn(null);
        Monkey\Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir());

        // Return the plan from wp_options so the membership validation passes.
        Monkey\Functions\when('get_option')
            ->alias(function (string $key) {
                if ($key === 'ck_join_flow_membership_plan_standard') {
                    return $this->membershipPlan;
                }
                return false;
            });

        // All plugin settings default to false/empty — no payment provider,
        // no Auth0, no Action Network, no Zetkin, no webhook.
        // USE_MAILCHIMP is set per test via carbon_get_theme_option.
        Monkey\Functions\when('carbon_get_theme_option')->justReturn('');

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
        global $joinBlockLog;
        $joinBlockLog = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMailchimpFailureDoesNotBlockJoin(): void
    {
        // Arrange: make USE_MAILCHIMP return true so the Mailchimp block runs.
        Monkey\Functions\when('carbon_get_theme_option')
            ->alias(function (string $key) {
                return $key === 'use_mailchimp' ? true : '';
            });

        // Arrange: alias-mock MailchimpService so signup() throws.
        $mock = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\MailchimpService');
        $mock->shouldReceive('signup')
            ->once()
            ->andThrow(new \Exception('Mailchimp API error'));

        // Act & Assert: handleJoin must not throw even though Mailchimp failed.
        try {
            JoinService::handleJoin($this->joinData);
        } catch (\Exception $e) {
            $this->fail('handleJoin threw an exception when Mailchimp failed: ' . $e->getMessage());
        }

        // The error must have been logged.
        global $joinBlockLog;
        $this->assertNotEmpty($joinBlockLog->errors, 'Expected Mailchimp error to be logged');
        $this->assertStringContainsString('Mailchimp', $joinBlockLog->errors[0]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMailchimpSuccessCompletesJoinCleanly(): void
    {
        Monkey\Functions\when('carbon_get_theme_option')
            ->alias(function (string $key) {
                return $key === 'use_mailchimp' ? true : '';
            });

        $mock = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\MailchimpService');
        $mock->shouldReceive('signup')
            ->once()
            ->andReturn(null);

        try {
            JoinService::handleJoin($this->joinData);
        } catch (\Exception $e) {
            $this->fail('handleJoin threw an exception on successful Mailchimp signup: ' . $e->getMessage());
        }

        global $joinBlockLog;
        $this->assertEmpty($joinBlockLog->errors, 'Expected no errors on successful Mailchimp signup');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMailchimpSkippedWhenUseMailchimpFalse(): void
    {
        // USE_MAILCHIMP is false (default from setUp). MailchimpService::signup
        // must never be called.
        $mock = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\MailchimpService');
        $mock->shouldNotReceive('signup');

        try {
            JoinService::handleJoin($this->joinData);
        } catch (\Exception $e) {
            $this->fail('handleJoin threw unexpectedly: ' . $e->getMessage());
        }
    }
}
