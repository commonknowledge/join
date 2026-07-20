<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\JoinService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CRM retry cadence used by ensureCrmPushesCompleted().
 *
 * The worker exists because a member can pay and never reach the CRM — the
 * failure mode behind the 2026-06-19 join whose demographics never arrived.
 * Its pacing matters in both directions: retry too eagerly and a CRM outage
 * means every queued member hammers it hourly and buries the useful logs;
 * retry too lazily and a member sits outside the CRM for days.
 */
class CrmRetryBackoffTest extends TestCase
{
    private const HOUR = 3600;

    // -------------------------------------------------------------------------
    // Backoff schedule
    // -------------------------------------------------------------------------

    /**
     * @dataProvider backoffSchedule
     */
    public function testBackoffFollowsTheExpectedSchedule(int $attempts, int $expectedHours): void
    {
        $this->assertSame(
            $expectedHours * self::HOUR,
            JoinService::crmRetryBackoffSeconds($attempts)
        );
    }

    public function backoffSchedule(): array
    {
        return [
            'never attempted'  => [0, 1],
            'after 1 failure'  => [1, 1],
            'after 2 failures' => [2, 2],
            'after 3 failures' => [3, 4],
            'after 4 failures' => [4, 8],
            'after 5 failures' => [5, 16],
            'capped at 24h'    => [6, 24],
            'still capped'     => [12, 24],
        ];
    }

    /**
     * A negative or nonsense attempt count must not produce a negative or zero
     * delay, which would spin the worker.
     */
    public function testMalformedAttemptCountStillYieldsAPositiveDelay(): void
    {
        $this->assertSame(self::HOUR, JoinService::crmRetryBackoffSeconds(-5));
    }

    // -------------------------------------------------------------------------
    // Due-ness
    // -------------------------------------------------------------------------

    public function testRecordIsDueOnceTheBackoffHasElapsed(): void
    {
        $now = 1_700_000_000;
        $record = ['attempts' => 3, 'lastAttemptAt' => $now - (4 * self::HOUR)];

        $this->assertTrue(JoinService::crmRetryIsDue($record, $now));
    }

    public function testRecordIsNotDueBeforeTheBackoffHasElapsed(): void
    {
        $now = 1_700_000_000;
        $record = ['attempts' => 3, 'lastAttemptAt' => $now - (4 * self::HOUR) + 1];

        $this->assertFalse(JoinService::crmRetryIsDue($record, $now));
    }

    /**
     * A freshly queued record has no lastAttemptAt, and must be picked up on
     * the next run rather than waiting out a backoff it never earned.
     */
    public function testFreshlyQueuedRecordIsDueImmediately(): void
    {
        $this->assertTrue(JoinService::crmRetryIsDue([], 1_700_000_000));
    }

    public function testLongStalledRecordIsDue(): void
    {
        $now = 1_700_000_000;
        $record = ['attempts' => 11, 'lastAttemptAt' => $now - (30 * 24 * self::HOUR)];

        $this->assertTrue(JoinService::crmRetryIsDue($record, $now));
    }
}
