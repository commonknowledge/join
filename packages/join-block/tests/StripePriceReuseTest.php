<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\StripeService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Custom amounts must reuse an existing Stripe Price rather than create one
 * per request.
 *
 * Context (RE-174, 5 Nov 2026): a member's first payment on a custom-amount
 * tier was declined by their bank. Each of their seven retries then failed
 * with an IdempotencyException before any card was charged. The retries
 * reused the subscription idempotency key, as intended, but each one created
 * a new Price, so the subscription request differed and Stripe rejected it.
 *
 * The cause was a strict comparison between Stripe's integer unit_amount and
 * a float computed from the form amount, which never matched. A price was
 * never reused, and about 400 single-use prices built up.
 *
 * SubscriptionIdempotencyKeyTest checks that the key is stable, but not that
 * the request it protects is stable too. These tests cover that by running
 * the real price lookup against an in-memory fake of the Stripe API.
 */
class StripePriceReuseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private object $stripe;

    private array $customPlan = [
        'label'               => 'Custom',
        'amount'              => '1',
        'allow_custom_amount' => true,
        'frequency'           => 'monthly',
        'currency'            => 'USD',
        'description'         => '',
        'stripe_price_id'     => 'price_default',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Monkey\Functions\when('sanitize_title')->alias(function ($title) {
            $title = strtolower((string) $title);
            $title = preg_replace('/[^a-z0-9]+/', '-', $title);
            return trim($title, '-');
        });

        global $joinBlockLog;
        $joinBlockLog = new class {
            public function info(string $msg, array $ctx = []): void
            {
            }

            public function warning(string $msg, array $ctx = []): void
            {
            }

            public function error(string $msg, array $ctx = []): void
            {
            }
        };

        \Stripe\Stripe::setApiKey('sk_test_fake');
        $this->stripe = $this->fakeStripe();
        \Stripe\ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        global $joinBlockLog;
        $joinBlockLog = null;
        \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * A minimal in-memory Stripe: one product, a list of prices that search
     * returns in pages, and a record of every subscription request.
     */
    private function fakeStripe(): object
    {
        return new class implements \Stripe\HttpClient\ClientInterface {
            public array $prices = [];
            public array $createdPrices = [];
            public array $subscriptionRequests = [];
            public array $priceSearchQueries = [];

            public function addPrice(int $unitAmount, string $currency = 'usd', ?string $interval = 'month'): string
            {
                $id = 'price_' . (count($this->prices) + 1);
                $this->prices[] = [
                    'id'          => $id,
                    'object'      => 'price',
                    'active'      => true,
                    'product'     => 'prod_test',
                    'unit_amount' => $unitAmount,
                    'currency'    => $currency,
                    'type'        => $interval ? 'recurring' : 'one_time',
                    'recurring'   => $interval ? ['interval' => $interval] : null,
                ];
                return $id;
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
            {
                $path = parse_url($absUrl, PHP_URL_PATH);
                parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
                // stripe-php hands GET parameters over separately, not in the URL.
                if ($method === 'get') {
                    $query = array_merge($query, (array) $params);
                }

                if ($method === 'get' && $path === '/v1/products/search') {
                    $body = $this->searchResult('/v1/products/search', [[
                        'id'     => 'prod_test',
                        'object' => 'product',
                        'name'   => 'Membership: Custom',
                    ]], $query);
                } elseif ($method === 'get' && $path === '/v1/prices/search') {
                    $this->priceSearchQueries[] = $query['query'] ?? '';
                    if (count($this->priceSearchQueries) > 50) {
                        throw new \RuntimeException('Runaway price search pagination');
                    }
                    $type = str_contains($query['query'] ?? '', "type:'one_time'") ? 'one_time' : 'recurring';
                    $matching = array_values(array_filter(
                        $this->prices,
                        fn ($p) => $p['type'] === $type
                    ));
                    $body = $this->searchResult('/v1/prices/search', $matching, $query);
                } elseif ($method === 'post' && $path === '/v1/prices') {
                    $id = $this->addPrice(
                        (int) $params['unit_amount'],
                        $params['currency'],
                        $params['recurring']['interval'] ?? null
                    );
                    $this->createdPrices[] = $params;
                    $body = $this->prices[count($this->prices) - 1];
                } elseif ($method === 'post' && $path === '/v1/subscriptions') {
                    $key = null;
                    foreach ($headers as $header) {
                        if (stripos($header, 'Idempotency-Key:') === 0) {
                            $key = trim(substr($header, strlen('Idempotency-Key:')));
                        }
                    }
                    $this->subscriptionRequests[] = ['params' => $params, 'idempotencyKey' => $key];
                    $body = ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'incomplete'];
                } else {
                    throw new \RuntimeException("Unexpected Stripe request: $method $absUrl");
                }

                return [json_encode($body), 200, []];
            }

            /** Page through $items the way Stripe's search API does. */
            private function searchResult(string $url, array $items, array $query): array
            {
                $limit = (int) ($query['limit'] ?? 10);
                $offset = (int) ($query['page'] ?? 0);
                $page = array_slice($items, $offset, $limit);
                $hasMore = $offset + $limit < count($items);

                return [
                    'object'    => 'search_result',
                    'url'       => $url,
                    'data'      => $page,
                    'has_more'  => $hasMore,
                    'next_page' => $hasMore ? (string) ($offset + $limit) : null,
                ];
            }
        };
    }

    private function product(): \Stripe\Product
    {
        return \Stripe\Product::constructFrom(['id' => 'prod_test', 'object' => 'product']);
    }

    // -------------------------------------------------------------------------
    // The RE-174 regression
    // -------------------------------------------------------------------------

    public function testRetryingACustomAmountJoinSendsAnIdenticalSubscriptionRequest(): void
    {
        $customer = \Stripe\Customer::constructFrom(['id' => 'cus_test', 'object' => 'customer']);
        $key = 'join_sub_same_session';

        // The form posts the custom amount as a string, as in production.
        StripeService::createSubscription($customer, $this->customPlan, '1', null, false, false, $key);
        StripeService::createSubscription($customer, $this->customPlan, '1', null, false, false, $key);

        $this->assertCount(1, $this->stripe->createdPrices, 'The retry should reuse the price made by the first attempt.');
        $this->assertCount(2, $this->stripe->subscriptionRequests);

        [$first, $retry] = $this->stripe->subscriptionRequests;
        $this->assertSame($key, $first['idempotencyKey']);
        $this->assertSame($key, $retry['idempotencyKey']);
        $this->assertSame(
            $first['params'],
            $retry['params'],
            'Stripe rejects a reused idempotency key if the request parameters differ.'
        );
    }

    // -------------------------------------------------------------------------
    // Price matching
    // -------------------------------------------------------------------------

    public function testExistingRecurringPriceIsReusedForAFloatAmount(): void
    {
        $existing = $this->stripe->addPrice(500);

        $price = StripeService::getOrCreatePriceForProduct($this->product(), 5.0, 'usd', 'month');

        $this->assertSame($existing, $price->id);
        $this->assertSame([], $this->stripe->createdPrices);
    }

    public function testFractionalAmountsRoundToWholeMinorUnits(): void
    {
        // 3.3 * 100 is 329.99999999999994 as a float; truncating would give 329.
        $existing = $this->stripe->addPrice(330);

        $price = StripeService::getOrCreatePriceForProduct($this->product(), 3.3, 'usd', 'month');

        $this->assertSame($existing, $price->id);
        $this->assertSame([], $this->stripe->createdPrices);
    }

    public function testNewPricesAreCreatedWithAnIntegerAmount(): void
    {
        StripeService::getOrCreatePriceForProduct($this->product(), 3.3, 'usd', 'month');

        $this->assertCount(1, $this->stripe->createdPrices);
        $this->assertSame(330, $this->stripe->createdPrices[0]['unit_amount']);
    }

    public function testUppercasePlanCurrencyIsSearchedAndCreatedLowercase(): void
    {
        StripeService::getOrCreatePriceForProduct($this->product(), 1.0, 'USD', 'month');

        $this->assertStringContainsString("currency:'usd'", $this->stripe->priceSearchQueries[0]);
        $this->assertSame('usd', $this->stripe->createdPrices[0]['currency']);
    }

    public function testPriceWithTheSameAmountButADifferentIntervalIsNotReused(): void
    {
        // The donation product is shared across tiers, so it can hold monthly
        // and yearly prices for the same amount.
        $this->stripe->addPrice(500, 'usd', 'year');

        $price = StripeService::getOrCreatePriceForProduct($this->product(), 5.0, 'usd', 'month');

        $this->assertCount(1, $this->stripe->createdPrices);
        $this->assertSame('month', $price->recurring->interval);
    }

    public function testMatchingRecurringPriceOnALaterSearchPageIsFound(): void
    {
        // Production products hold hundreds of prices; search returns pages.
        for ($i = 1; $i <= 150; $i++) {
            $this->stripe->addPrice(10000 + $i);
        }
        $existing = $this->stripe->addPrice(500);

        $price = StripeService::getOrCreatePriceForProduct($this->product(), 5.0, 'usd', 'month');

        $this->assertSame($existing, $price->id);
        $this->assertSame([], $this->stripe->createdPrices);
    }

    public function testMatchingOneTimePriceOnALaterSearchPageIsFound(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->stripe->addPrice(10000 + $i, 'usd', null);
        }
        $existing = $this->stripe->addPrice(500, 'usd', null);

        $price = StripeService::getOrCreateOneTimePriceForProduct($this->product(), 5.0, 'USD');

        $this->assertSame($existing, $price->id);
        $this->assertSame([], $this->stripe->createdPrices);
    }
}
