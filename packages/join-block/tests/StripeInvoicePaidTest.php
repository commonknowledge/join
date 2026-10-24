<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\StripeService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A renewal invoice.paid only un-lapses a member whose subscription is live.
 *
 * When Stripe exhausts its retries it cancels the subscription but leaves the
 * overdue invoice open. If the member pays that invoice later, Stripe sends
 * invoice.paid, but the subscription stays cancelled and no further payments
 * are taken. Previously this was treated as recovery: the lapsed tag was
 * removed and the member kept access with nothing paying for it.
 *
 * Stripe's HTTP client is replaced with a fake, and JoinService is alias
 * mocked, so each test runs in a separate process.
 */
class StripeInvoicePaidTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        global $joinBlockLog;
        $joinBlockLog = new class {
            public array $errors = [];

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function info(string $msg, array $ctx = []): void
            {
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function warning(string $msg, array $ctx = []): void
            {
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function error(string $msg, array $ctx = []): void
            {
                $this->errors[] = $msg;
            }
        };

        \Stripe\Stripe::setApiKey('sk_test_fake');
    }

    protected function tearDown(): void
    {
        global $joinBlockLog;
        $joinBlockLog = null;
        // Back to the client Stripe uses by default.
        \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Answer Stripe API calls: the customer lookup, and subscription listings
     * that return $activeSubscriptions for status=active and nothing else.
     */
    private function fakeStripe(array $activeSubscriptions): void
    {
        $client = new class ($activeSubscriptions) implements \Stripe\HttpClient\ClientInterface {
            public function __construct(private array $activeSubscriptions)
            {
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
            {
                $path = parse_url($absUrl, PHP_URL_PATH);
                if ($path === '/v1/customers/cus_test') {
                    $body = ['id' => 'cus_test', 'object' => 'customer', 'email' => 'test@example.com'];
                } elseif ($path === '/v1/subscriptions') {
                    parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
                    $status = $query['status'] ?? $params['status'] ?? null;
                    $body = [
                        'object'   => 'list',
                        'url'      => '/v1/subscriptions',
                        'has_more' => false,
                        'data'     => $status === 'active' ? $this->activeSubscriptions : [],
                    ];
                } else {
                    throw new \RuntimeException("Unexpected Stripe request: $method $absUrl");
                }
                return [json_encode($body), 200, []];
            }
        };
        \Stripe\ApiRequestor::setHttpClient($client);
    }

    private function renewalInvoicePaidEvent(): array
    {
        return [
            'id'   => 'evt_test',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id'             => 'in_test',
                    'customer'       => 'cus_test',
                    'billing_reason' => 'subscription_cycle',
                    'subscription'   => 'sub_test',
                ],
            ],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLatePaymentOnCancelledSubscriptionLeavesMemberLapsed(): void
    {
        $this->fakeStripe([]);

        $join = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\JoinService');
        $join->shouldNotReceive('toggleMemberLapsed');
        $join->shouldReceive('releaseLock');

        StripeService::handleWebhook($this->renewalInvoicePaidEvent());

        global $joinBlockLog;
        $this->assertCount(1, $joinBlockLog->errors);
        $this->assertStringContainsString('no live subscription', $joinBlockLog->errors[0]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPaymentOnLiveSubscriptionUnlapsesMember(): void
    {
        $this->fakeStripe([['id' => 'sub_test', 'object' => 'subscription', 'status' => 'active']]);

        $join = \Mockery::mock('alias:CommonKnowledge\JoinBlock\Services\JoinService');
        $join->shouldReceive('acquireLock')->once()->with('test@example.com')->andReturn(null);
        $join->shouldReceive('shouldUnlapseMember')->once()->andReturn(true);
        $join->shouldReceive('toggleMemberLapsed')
            ->once()
            ->with('test@example.com', false, null, \Mockery::type('array'));
        $join->shouldReceive('releaseLock');

        StripeService::handleWebhook($this->renewalInvoicePaidEvent());

        global $joinBlockLog;
        $this->assertEmpty($joinBlockLog->errors);
    }
}
