<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Services\ZetkinService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the Zetkin tag and person-lookup paths.
 *
 * These paths run in production during joins and Stripe webhooks but had no
 * coverage: getZetkinContext() performs a live OAuth exchange, which made
 * everything above it untestable. The context override seam replaces that
 * exchange with a fake client, so the behaviour these paths have always had
 * is now pinned rather than preserved by hope.
 */
class ZetkinServiceTagsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private object $logger;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('carbon_get_theme_option')->justReturn(null);

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
        ZetkinService::overrideZetkinContext(null);
        global $joinBlockLog;
        $joinBlockLog = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * A client that routes requests by method and URL fragment, recording
     * everything it is asked for.
     */
    private function fakeZetkin(array $routes): object
    {
        $client = new class ($routes) {
            public array $requests = [];
            private array $routes;

            public function __construct(array $routes)
            {
                $this->routes = $routes;
            }

            public function request($method, $url, $options = [])
            {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
                foreach ($this->routes as [$routeMethod, $needle, $response]) {
                    if ($routeMethod === $method && str_contains($url, $needle)) {
                        return $response;
                    }
                }
                throw new \RuntimeException("Unrouted request in test: $method $url");
            }
        };

        ZetkinService::overrideZetkinContext([
            'baseUrl' => 'https://zetkin.test/v1',
            'orgId' => 99,
            'accessToken' => 'test-token',
            'client' => $client,
        ]);

        return $client;
    }

    private function response(array $json, int $status = 200): object
    {
        $body = new class (json_encode($json)) {
            public function __construct(private string $contents)
            {
            }

            public function getContents(): string
            {
                return $this->contents;
            }
        };

        return new class ($body, $status) {
            public function __construct(private object $body, private int $status)
            {
            }

            public function getBody(): object
            {
                return $this->body;
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }
        };
    }

    private function requestsMatching(object $client, string $method, string $needle): array
    {
        return array_values(array_filter(
            $client->requests,
            fn($r) => $r['method'] === $method && str_contains($r['url'], $needle)
        ));
    }

    // addTag / removeTag, the email-based pair used by joins and webhooks.

    /**
     * Nothing stops Zetkin holding two people with the same email, and addTag
     * has always tagged all of them, not the first. Pinned because collapsing
     * the search into findPersonByEmail (which takes the first) would silently
     * halve its reach.
     */
    public function test_add_tag_tags_every_person_with_that_email()
    {
        $client = $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [
                ['id' => 5, 'email' => 'a@example.com'],
                ['id' => 6, 'email' => 'a@example.com'],
            ]])],
            ['GET', 'people/tags', $this->response(['data' => [['id' => 7, 'title' => 'Bury']]])],
            ['PUT', '/tags/7', $this->response([])],
        ]);

        ZetkinService::addTag('a@example.com', 'Bury');

        $puts = $this->requestsMatching($client, 'PUT', '/tags/7');
        $this->assertCount(2, $puts);
        $this->assertStringContainsString('/people/5/tags/7', $puts[0]['url']);
        $this->assertStringContainsString('/people/6/tags/7', $puts[1]['url']);
    }

    /**
     * Zetkin's search is fuzzy. A near-miss email must never be tagged.
     */
    public function test_add_tag_ignores_fuzzy_search_matches()
    {
        $client = $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [
                ['id' => 5, 'email' => 'a@example.com'],
                ['id' => 9, 'email' => 'aa@example.com'],
            ]])],
            ['GET', 'people/tags', $this->response(['data' => [['id' => 7, 'title' => 'Bury']]])],
            ['PUT', '/tags/7', $this->response([])],
        ]);

        ZetkinService::addTag('a@example.com', 'Bury');

        $puts = $this->requestsMatching($client, 'PUT', '/tags/');
        $this->assertCount(1, $puts);
        $this->assertStringContainsString('/people/5/', $puts[0]['url']);
    }

    public function test_add_tag_warns_and_writes_nothing_when_nobody_matches()
    {
        $client = $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => []])],
        ]);

        ZetkinService::addTag('ghost@example.com', 'Bury');

        $this->assertCount(1, $client->requests);
        $this->assertStringContainsString('no person found for ghost@example.com', $this->logger->warnings[0]);
    }

    /**
     * addTag has always swallowed API failures: a Zetkin outage must not break
     * the join or webhook that called it. It logs and returns.
     */
    public function test_add_tag_logs_and_does_not_throw_on_an_api_error()
    {
        $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['error' => 'nope'])],
        ]);

        ZetkinService::addTag('a@example.com', 'Bury');

        $this->assertNotEmpty($this->logger->errors);
        $this->assertStringContainsString('Could not add tag', $this->logger->errors[0]);
    }

    public function test_remove_tag_deletes_the_resolved_tag()
    {
        $client = $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [['id' => 5, 'email' => 'a@example.com']]])],
            ['GET', 'people/tags', $this->response(['data' => [['id' => 7, 'title' => 'Bury']]])],
            ['DELETE', '/tags/7', $this->response([])],
        ]);

        ZetkinService::removeTag('a@example.com', 'Bury');

        $deletes = $this->requestsMatching($client, 'DELETE', '/people/5/tags/7');
        $this->assertCount(1, $deletes);
    }

    /**
     * Removing a tag the person does not have is a non-event, not an error.
     */
    public function test_remove_tag_treats_a_missing_tag_as_a_non_event()
    {
        $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [['id' => 5, 'email' => 'a@example.com']]])],
            ['GET', 'people/tags', $this->response(['data' => [['id' => 7, 'title' => 'Bury']]])],
            ['DELETE', '/tags/7', $this->response([], 404)],
        ]);

        ZetkinService::removeTag('a@example.com', 'Bury');

        $this->assertSame([], $this->logger->errors);
        $this->assertStringContainsString("was not on", $this->logger->infos[0]);
    }

    // Person lookup, shared by addTag, removeTag, updatePerson and
    // findPersonByEmail.

    public function test_find_person_by_email_returns_the_first_exact_match()
    {
        $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [
                ['id' => 9, 'email' => 'other@example.com'],
                ['id' => 5, 'email' => 'a@example.com'],
                ['id' => 6, 'email' => 'a@example.com'],
            ]])],
        ]);

        $person = ZetkinService::findPersonByEmail('a@example.com');

        $this->assertSame(5, $person['id']);
    }

    public function test_find_person_by_email_returns_null_when_nobody_matches()
    {
        $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [['id' => 9, 'email' => 'other@example.com']]])],
        ]);

        $this->assertNull(ZetkinService::findPersonByEmail('a@example.com'));
    }

    public function test_update_person_patches_the_matched_person()
    {
        $client = $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => [['id' => 5, 'email' => 'a@example.com']]])],
            ['PATCH', '/people/5', $this->response(['data' => ['id' => 5]])],
        ]);

        ZetkinService::updatePerson('a@example.com', ['first_name' => 'Test']);

        $patches = $this->requestsMatching($client, 'PATCH', '/people/5');
        $this->assertCount(1, $patches);
        $this->assertSame(['first_name' => 'Test'], $patches[0]['options']['json']);
    }

    public function test_update_person_warns_and_writes_nothing_when_nobody_matches()
    {
        $client = $this->fakeZetkin([
            ['POST', 'search/person', $this->response(['data' => []])],
        ]);

        ZetkinService::updatePerson('ghost@example.com', ['first_name' => 'Test']);

        $this->assertCount(1, $client->requests);
        $this->assertStringContainsString('no person found', $this->logger->warnings[0]);
    }

    // The bulk helpers the re-tag job is built on.

    public function test_list_people_sends_zetkins_paging_parameters()
    {
        $client = $this->fakeZetkin([
            ['GET', '/people?', $this->response(['data' => [['id' => 1]]])],
        ]);

        $people = ZetkinService::listPeople(2, 50);

        $this->assertSame([['id' => 1]], $people);
        $this->assertStringContainsString('/people?p=2&pp=50', $client->requests[0]['url']);
    }

    public function test_list_people_returns_an_empty_array_when_exhausted()
    {
        $this->fakeZetkin([
            ['GET', '/people?', $this->response(['data' => []])],
        ]);

        $this->assertSame([], ZetkinService::listPeople(7));
    }

    public function test_get_person_tags_returns_the_tag_records()
    {
        $this->fakeZetkin([
            ['GET', '/people/5/tags', $this->response(['data' => [['id' => 7, 'title' => 'Bury']]])],
        ]);

        $this->assertSame([['id' => 7, 'title' => 'Bury']], ZetkinService::getPersonTags(5));
    }

    // The try* pair report one of the TAG_* constants, mirroring
    // MailchimpService, so the re-tag job reads both services the same way.

    public function test_try_add_tag_to_person_reports_ok()
    {
        $this->fakeZetkin([
            ['PUT', '/people/5/tags/7', $this->response([])],
        ]);

        $this->assertSame(ZetkinService::TAG_OK, ZetkinService::tryAddTagToPerson(5, 7));
    }

    public function test_try_add_tag_to_person_reports_an_api_error()
    {
        $this->fakeZetkin([
            ['PUT', '/people/5/tags/7', $this->response(['error' => 'nope'])],
        ]);

        $this->assertSame(ZetkinService::TAG_ERROR, ZetkinService::tryAddTagToPerson(5, 7));
    }

    public function test_try_remove_tag_from_person_reports_ok()
    {
        $this->fakeZetkin([
            ['DELETE', '/people/5/tags/7', $this->response([])],
        ]);

        $this->assertSame(ZetkinService::TAG_OK, ZetkinService::tryRemoveTagFromPerson(5, 7));
    }

    /**
     * A tag the person does not have is reported as missing, distinct from
     * both success and failure, and callers decide what it means to them.
     */
    public function test_try_remove_tag_from_person_reports_a_missing_tag()
    {
        $this->fakeZetkin([
            ['DELETE', '/people/5/tags/7', $this->response([], 404)],
        ]);

        $this->assertSame(ZetkinService::TAG_MISSING, ZetkinService::tryRemoveTagFromPerson(5, 7));
    }

    public function test_try_remove_tag_from_person_reports_an_api_error()
    {
        $this->fakeZetkin([
            ['DELETE', '/people/5/tags/7', $this->response([], 500)],
        ]);

        $this->assertSame(ZetkinService::TAG_ERROR, ZetkinService::tryRemoveTagFromPerson(5, 7));
    }

    public function test_try_helpers_report_not_configured_without_credentials()
    {
        $this->assertSame(ZetkinService::TAG_NOT_CONFIGURED, ZetkinService::tryAddTagToPerson(5, 7));
        $this->assertSame(ZetkinService::TAG_NOT_CONFIGURED, ZetkinService::tryRemoveTagFromPerson(5, 7));
    }

    /**
     * The GMTU add-on compares against these values across a plugin boundary,
     * and its test fakes return them as literals. Renaming a constant is free;
     * changing its value is not, so pin the wire values here.
     */
    public function test_status_values_are_stable()
    {
        $this->assertSame('ok', ZetkinService::TAG_OK);
        $this->assertSame('missing', ZetkinService::TAG_MISSING);
        $this->assertSame('not_configured', ZetkinService::TAG_NOT_CONFIGURED);
        $this->assertSame('error', ZetkinService::TAG_ERROR);
    }

    public function test_find_or_create_tag_by_title_reuses_an_existing_tag()
    {
        $client = $this->fakeZetkin([
            ['GET', 'people/tags', $this->response(['data' => [['id' => 7, 'title' => 'Bury']]])],
        ]);

        $tag = ZetkinService::findOrCreateTagByTitle('bury');

        $this->assertSame(7, $tag['id']);
        $this->assertSame([], $this->requestsMatching($client, 'POST', 'people/tags'));
    }
}
