<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
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
        Monkey\Functions\when('carbon_get_theme_option')->justReturn('');

        global $joinBlockLog;
        $joinBlockLog = new class {
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function info($msg, $ctx = [])
            {
            }
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function warning($msg, $ctx = [])
            {
            }
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function error($msg, $ctx = [])
            {
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


    // --- shouldLapseMember ---

    public function testShouldLapseReturnsFalseByDefault(): void
    {
        $this->assertFalse(JoinService::shouldLapseMember('test@example.com'));
    }

    public function testShouldUnlapseReturnsFalseByDefault(): void
    {
        $this->assertFalse(JoinService::shouldUnlapseMember('test@example.com'));
    }

    public function testFilterCanEnableLapsing(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->andReturn(false);

        $this->assertFalse(JoinService::shouldLapseMember('test@example.com'));
    }

    public function testFilterCanEnableUnlapsing(): void
    {
        Filters\expectApplied('ck_join_flow_should_unlapse_member')
            ->once()
            ->andReturn(false);

        $this->assertFalse(JoinService::shouldUnlapseMember('test@example.com'));
    }

    public function testFilterReturningTrueEnablesLapsing(): void
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
            ->with(false, 'test@example.com', \Mockery::type('array'))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', []);
    }

    public function testFilterReceivesProvider(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(false, \Mockery::any(), \Mockery::on(fn($c) => $c['provider'] === 'stripe'))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', ['provider' => 'stripe']);
    }

    public function testFilterReceivesTrigger(): void
    {
        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(false, \Mockery::any(), \Mockery::on(fn($c) => $c['trigger'] === 'invoice_paid'))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', ['trigger' => 'invoice_paid']);
    }

    public function testFilterReceivesEvent(): void
    {
        $event = ['type' => 'invoice.paid', 'id' => 'evt_test'];

        Filters\expectApplied('ck_join_flow_should_lapse_member')
            ->once()
            ->with(false, \Mockery::any(), \Mockery::on(fn($c) => $c['event'] === $event))
            ->andReturn(true);

        JoinService::shouldLapseMember('test@example.com', ['event' => $event]);
    }

    // --- toggleMemberLapsed action hooks ---

    public function testLapsedActionFiresAfterExecution(): void
    {
        Actions\expectDone('ck_join_flow_member_lapsed')
            ->once()
            ->with('test@example.com', \Mockery::type('array'));

        JoinService::toggleMemberLapsed('test@example.com', true, null, []);
    }

    public function testUnlapsedActionFiresAfterExecution(): void
    {
        Actions\expectDone('ck_join_flow_member_unlapsed')
            ->once()
            ->with('test@example.com', \Mockery::type('array'));

        JoinService::toggleMemberLapsed('test@example.com', false, null, []);
    }

    public function testActionsReceiveContext(): void
    {
        Actions\expectDone('ck_join_flow_member_lapsed')
            ->once()
            ->with(\Mockery::any(), \Mockery::on(fn($c) => $c['provider'] === 'stripe'));

        JoinService::toggleMemberLapsed('test@example.com', true, null, ['provider' => 'stripe']);
    }
}
