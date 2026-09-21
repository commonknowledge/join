<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\MailchimpService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the throwing Mailchimp tag pair, addTag and removeTag.
 *
 * These run in production during joins and Stripe webhooks but had no
 * coverage until the client became injectable, so the behaviour their
 * callers rely on is pinned here: the memberExists pre-check that turns a
 * missing member into a warning rather than an API error, and the rethrow of
 * the original exception, which both callers catch.
 */
class MailchimpServiceLegacyTagsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private object $logger;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('carbon_get_theme_option')->justReturn(null);

        $_ENV['MAILCHIMP_API_KEY'] = 'test-key-us1';
        $_ENV['MAILCHIMP_AUDIENCE_ID'] = 'test-audience';

        global $joinBlockLog;
        $this->logger = new class {
            public array $infos = [];
            public array $warnings = [];
            public array $errors = [];

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function info(string $msg, array $ctx = []): void
            {
                $this->infos[] = $msg;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function warning(string $msg, array $ctx = []): void
            {
                $this->warnings[] = $msg;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function error(string $msg, array $ctx = []): void
            {
                $this->errors[] = $msg;
            }
        };
        $joinBlockLog = $this->logger;
    }

    protected function tearDown(): void
    {
        global $joinBlockLog;
        $joinBlockLog = null;
        unset($_ENV['MAILCHIMP_API_KEY'], $_ENV['MAILCHIMP_AUDIENCE_ID']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * A client whose member lookup and tag update can each be told to fail.
     */
    private function fakeClient(bool $memberExists = true, ?\Throwable $throwOnUpdate = null): object
    {
        $lists = new class ($memberExists, $throwOnUpdate) {
            public array $updates = [];
            public array $lookups = [];

            public function __construct(private bool $memberExists, private ?\Throwable $throwOnUpdate)
            {
            }

            public function getListMember($audienceId, $subscriberHash)
            {
                $this->lookups[] = [$audienceId, $subscriberHash];
                if (!$this->memberExists) {
                    throw new ClientException(
                        'HTTP 404',
                        new Request('GET', '/'),
                        new Response(404, [], '{"title":"Resource Not Found","status":404}')
                    );
                }
                return ['id' => $subscriberHash];
            }

            public function updateListMemberTags($audienceId, $subscriberHash, $body)
            {
                $this->updates[] = ['audienceId' => $audienceId, 'hash' => $subscriberHash, 'body' => $body];
                if ($this->throwOnUpdate) {
                    throw $this->throwOnUpdate;
                }
                return null;
            }
        };

        return new class ($lists) {
            public $lists;

            public function __construct($lists)
            {
                $this->lists = $lists;
            }
        };
    }

    public function test_add_tag_sends_the_tag_as_active()
    {
        $client = $this->fakeClient();

        MailchimpService::addTag('person@example.com', 'Bury', $client);

        $this->assertCount(1, $client->lists->updates);
        $this->assertSame(
            [['name' => 'Bury', 'status' => 'active']],
            $client->lists->updates[0]['body']['tags']
        );
        $this->assertSame([], $this->logger->errors);
    }

    public function test_remove_tag_sends_the_tag_as_inactive()
    {
        $client = $this->fakeClient();

        MailchimpService::removeTag('person@example.com', 'Bury', $client);

        $this->assertSame(
            [['name' => 'Bury', 'status' => 'inactive']],
            $client->lists->updates[0]['body']['tags']
        );
    }

    /**
     * A member who is not in the audience is a warning and a no-op, never an
     * API call or an exception. Both callers depend on that.
     */
    public function test_add_tag_skips_a_member_who_is_not_in_the_audience()
    {
        $client = $this->fakeClient(memberExists: false);

        MailchimpService::addTag('ghost@example.com', 'Bury', $client);

        $this->assertSame([], $client->lists->updates);
        $this->assertStringContainsString('member does not exist', $this->logger->warnings[0]);
    }

    /**
     * The exception that reaches the caller must be the original, not a
     * re-wrap. External code catching ClientException relies on it.
     */
    public function test_add_tag_rethrows_the_original_exception()
    {
        $original = new ClientException(
            'HTTP 403',
            new Request('POST', '/'),
            new Response(403, [], '{"title":"Forbidden"}')
        );
        $client = $this->fakeClient(throwOnUpdate: $original);

        $caught = null;
        try {
            MailchimpService::addTag('person@example.com', 'Bury', $client);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertSame($original, $caught);
        $this->assertNotEmpty($this->logger->errors);
    }

    public function test_member_exists_is_true_for_a_known_member()
    {
        $this->assertTrue(MailchimpService::memberExists('person@example.com', $this->fakeClient()));
    }

    public function test_member_exists_is_false_for_a_404()
    {
        $this->assertFalse(MailchimpService::memberExists('ghost@example.com', $this->fakeClient(memberExists: false)));
    }
}
