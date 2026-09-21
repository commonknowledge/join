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
 * Unit tests for the bulk-friendly Mailchimp tag helpers.
 *
 * These exist so a maintenance job can re-tag many members and report
 * honestly on what happened to each one. The existing addTag/removeTag
 * return nothing and throw on an API error, which is fine for a single
 * signup but useless in a bulk run, where "this member is not in the
 * audience" has to be distinguishable from "Mailchimp is broken".
 *
 * The Mailchimp client is injected so these run without network access.
 */
class MailchimpServiceTagsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('carbon_get_theme_option')->justReturn(null);

        $_ENV['MAILCHIMP_API_KEY'] = 'test-key-us1';
        $_ENV['MAILCHIMP_AUDIENCE_ID'] = 'test-audience';

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
        unset($_ENV['MAILCHIMP_API_KEY'], $_ENV['MAILCHIMP_AUDIENCE_ID']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * A client that records the calls made to it and returns a canned result.
     */
    private function fakeClient(?\Throwable $throw = null): object
    {
        $lists = new class ($throw) {
            public array $calls = [];
            private ?\Throwable $throw;

            public function __construct(?\Throwable $throw)
            {
                $this->throw = $throw;
            }

            public function updateListMemberTags($audienceId, $subscriberHash, $body)
            {
                $this->calls[] = compact('audienceId', 'subscriberHash', 'body');
                if ($this->throw) {
                    throw $this->throw;
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

    private function clientException(int $status, string $body): ClientException
    {
        return new ClientException(
            "HTTP $status",
            new Request('POST', '/'),
            new Response($status, [], $body)
        );
    }

    public function testAddTagToMemberReportsOk(): void
    {
        $client = $this->fakeClient();

        $result = MailchimpService::addTagToMember('person@example.com', 'Bury', $client);

        $this->assertSame(MailchimpService::TAG_OK, $result);
    }

    public function testAddTagToMemberSendsTheTagAsActive(): void
    {
        $client = $this->fakeClient();

        MailchimpService::addTagToMember('person@example.com', 'Bury', $client);

        $call = $client->lists->calls[0];
        $this->assertSame([['name' => 'Bury', 'status' => 'active']], $call['body']['tags']);
    }

    public function testRemoveTagFromMemberSendsTheTagAsInactive(): void
    {
        $client = $this->fakeClient();

        $result = MailchimpService::removeTagFromMember('person@example.com', 'South Manchester', $client);

        $this->assertSame(MailchimpService::TAG_OK, $result);
        $call = $client->lists->calls[0];
        $this->assertSame(
            [['name' => 'South Manchester', 'status' => 'inactive']],
            $call['body']['tags']
        );
    }

    /**
     * Mailchimp addresses a member by the md5 of their lowercased email.
     * Getting this wrong tags the wrong person, or nobody, without erroring.
     */
    public function testMemberIsAddressedByLowercasedEmailHash(): void
    {
        $client = $this->fakeClient();

        MailchimpService::addTagToMember('Person@Example.COM', 'Bury', $client);

        $call = $client->lists->calls[0];
        $this->assertSame(md5('person@example.com'), $call['subscriberHash']);
        $this->assertSame('test-audience', $call['audienceId']);
    }

    /**
     * Someone in Zetkin but not in the Mailchimp audience is an expected,
     * reportable outcome, not a failure of the run.
     */
    public function testUnknownMemberIsReportedAsNotFound(): void
    {
        $client = $this->fakeClient($this->clientException(404, '{"title":"Resource Not Found"}'));

        $result = MailchimpService::addTagToMember('ghost@example.com', 'Bury', $client);

        $this->assertSame(MailchimpService::TAG_NOT_FOUND, $result);
    }

    public function testUnknownMemberIsReportedAsNotFoundWhenRemoving(): void
    {
        $client = $this->fakeClient($this->clientException(404, '{"title":"Resource Not Found"}'));

        $result = MailchimpService::removeTagFromMember('ghost@example.com', 'Bury', $client);

        $this->assertSame(MailchimpService::TAG_NOT_FOUND, $result);
    }

    /**
     * Anything else from Mailchimp is an error. It must not be swallowed as
     * success, and it must not be mistaken for a missing member.
     */
    public function testOtherClientErrorsAreReportedAsError(): void
    {
        $client = $this->fakeClient($this->clientException(403, '{"title":"API Key Invalid"}'));

        $result = MailchimpService::addTagToMember('person@example.com', 'Bury', $client);

        $this->assertSame(MailchimpService::TAG_ERROR, $result);
    }

    public function testUnexpectedFailuresAreReportedAsError(): void
    {
        $client = $this->fakeClient(new \RuntimeException('connection reset'));

        $result = MailchimpService::addTagToMember('person@example.com', 'Bury', $client);

        $this->assertSame(MailchimpService::TAG_ERROR, $result);
    }

    /**
     * A bulk job must be able to ask whether Mailchimp is worth talking to
     * before it starts, rather than discovering it per member.
     */
    public function testIsConfiguredIsTrueWhenKeyAndAudienceAreSet(): void
    {
        $this->assertTrue(MailchimpService::isConfigured());
    }

    public function testIsConfiguredIsFalseWithoutAnApiKey(): void
    {
        unset($_ENV['MAILCHIMP_API_KEY']);

        $this->assertFalse(MailchimpService::isConfigured());
    }

    public function testIsConfiguredIsFalseWithoutAnAudience(): void
    {
        unset($_ENV['MAILCHIMP_AUDIENCE_ID']);

        $this->assertFalse(MailchimpService::isConfigured());
    }

    /**
     * The GMTU add-on compares against these values across a plugin boundary,
     * and its test fakes return them as literals. Renaming a constant is free;
     * changing its value is not, so pin the wire values here.
     */
    public function testStatusValuesAreStable(): void
    {
        $this->assertSame('ok', MailchimpService::TAG_OK);
        $this->assertSame('not_found', MailchimpService::TAG_NOT_FOUND);
        $this->assertSame('not_configured', MailchimpService::TAG_NOT_CONFIGURED);
        $this->assertSame('error', MailchimpService::TAG_ERROR);
    }

    /**
     * With Mailchimp switched off, the helpers say so rather than
     * constructing a client against an empty key.
     */
    public function testTaggingIsSkippedWhenMailchimpIsNotConfigured(): void
    {
        unset($_ENV['MAILCHIMP_API_KEY']);
        $client = $this->fakeClient();

        $result = MailchimpService::addTagToMember('person@example.com', 'Bury', $client);

        $this->assertSame(MailchimpService::TAG_NOT_CONFIGURED, $result);
        $this->assertSame([], $client->lists->calls);
    }
}
