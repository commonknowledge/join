<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use CommonKnowledge\JoinBlock\Services\JoinService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class LapsingFilterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('get_option')->justReturn('');
        Monkey\Functions\when('esc_html')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- shouldLapseMember ---

    public function testShouldLapseReturnsTrueByDefault(): void
    {
        $this->assertTrue(JoinService::shouldLapseMember('test@example.com'));
    }

    public function testShouldUnlapseReturnsTrueByDefault(): void
    {
        $this->assertTrue(JoinService::shouldUnlapseMember('test@example.com'));
    }

    public function testFilterCanSuppressLapsing(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->andReturn(false);

        $this->assertFalse(JoinService::shouldLapseMember('test@example.com'));
    }

    public function testFilterCanSuppressUnlapsing(): void
    {
        Filters\expectApplied('ck_join_flow_should_unlapse_member')
            ->once()
            ->andReturn(false);

        $this->assertFalse(JoinService::shouldUnlapseMember('test@example.com'));
    }

    public function testFilterReturningTrueDoesNotSuppress(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->andReturn(true);

        $this->assertTrue(JoinService::shouldLapseMember('test@example.com'));
    }

    public function testFilterReceivesEmail(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(true, 'test@example.com', \Mockery::type('array'))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', []);
    }

    public function testFilterReceivesProvider(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(true, \Mockery::any(), \Mockery::on(fn($c) => $c['provider'] === 'stripe'))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', ['provider' => 'stripe']);
    }

    public function testFilterReceivesTrigger(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(true, \Mockery::any(), \Mockery::on(fn($c) => $c['trigger'] === 'invoice_paid'))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', ['trigger' => 'invoice_paid']);
    }

    public function testFilterReceivesEvent(): void
    {
        $event = ['type' => 'invoice.paid', 'id' => 'evt_test'];

        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(true, \Mockery::any(), \Mockery::on(fn($c) => $c['event'] === $event))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', ['event' => $event]);
    }

    public function testBackwardsCompatibility(): void
    {
        $this->assertTrue(JoinService::shouldLapseMember('test@example.com'));
    }
}
