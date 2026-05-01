<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Upgrade;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the plugin upgrade dispatcher.
 *
 * The Upgrade class follows the WooCommerce-style version-comparison pattern:
 * on every `init` request, compare the stored db-version against the current
 * plugin version (CK_JOIN_FLOW_VERSION). If older, run any pending migrations
 * declared in the version map, then stamp the new version.
 *
 * These tests pin the dispatcher contract:
 *   - no-op fast path when versions already match
 *   - migrations run only for versions strictly above the stored one
 *   - migrations run in ascending version order
 *   - the stored version is bumped only after every migration succeeds
 *   - a transient lock prevents concurrent dispatcher runs in the same request
 *
 * The migrations argument to upgradeJoinFlow() is intentionally injectable so
 * tests can supply test doubles without alias-mocking the Settings class.
 * Real callers omit the argument and get the production map.
 */
class UpgradeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        global $joinBlockLog;
        $joinBlockLog = new class {
            public function info($msg, $ctx = [])
            {
            }
            public function warning($msg, $ctx = [])
            {
            }
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

    // ------------------------------------------------------------------
    // check() — version-comparison fast path
    // ------------------------------------------------------------------

    /**
     * When the stored db_version matches the current plugin version, the
     * dispatcher must short-circuit before touching the lock or migrations.
     * This is the hot path on every request after a successful upgrade.
     */
    public function testCheckReturnsEarlyWhenStoredVersionMatchesCurrent(): void
    {
        Monkey\Functions\expect('get_option')
            ->once()
            ->with('ck_join_flow_db_version', '0.0.0')
            ->andReturn(CK_JOIN_FLOW_VERSION);

        // No transient or update_option calls expected — if the dispatcher
        // touched them, Brain Monkey's strict mode would fail the test.
        Monkey\Functions\expect('get_transient')->never();
        Monkey\Functions\expect('update_option')->never();

        Upgrade::check();

        $this->assertTrue(true); // assertions are in the expect() calls
    }

    /**
     * When the stored version is older than the current plugin version, the
     * dispatcher must invoke upgradeJoinFlow with the stored version as the
     * fromVersion, run the production migrations, and stamp the new version.
     *
     * We supply an injected migration map via a static override so the test
     * doesn't depend on real migration behaviour.
     */
    public function testCheckRunsUpgradeJoinFlowWhenStoredVersionIsOlder(): void
    {
        Monkey\Functions\expect('get_option')
            ->once()
            ->with('ck_join_flow_db_version', '0.0.0')
            ->andReturn('1.4.8');

        Monkey\Functions\expect('get_transient')
            ->with('ck_join_flow_upgrading')
            ->andReturn(false);
        Monkey\Functions\expect('set_transient')->once();
        Monkey\Functions\expect('delete_transient')->once();

        Monkey\Functions\expect('update_option')
            ->once()
            ->with('ck_join_flow_db_version', CK_JOIN_FLOW_VERSION)
            ->andReturn(true);

        $invoked = false;
        Upgrade::check([
            CK_JOIN_FLOW_VERSION => [function () use (&$invoked) {
                $invoked = true;
            }],
        ]);

        $this->assertTrue($invoked, 'Migration callback should have been invoked');
    }

    /**
     * Fresh-install case: get_option returns the default '0.0.0' when no
     * stored value exists. The dispatcher must treat that as older than any
     * real version and run all migrations.
     */
    public function testCheckTreatsMissingStoredVersionAsZero(): void
    {
        Monkey\Functions\expect('get_option')
            ->once()
            ->with('ck_join_flow_db_version', '0.0.0')
            ->andReturn('0.0.0');

        Monkey\Functions\expect('get_transient')->andReturn(false);
        Monkey\Functions\expect('set_transient');
        Monkey\Functions\expect('delete_transient');
        Monkey\Functions\expect('update_option')
            ->once()
            ->with('ck_join_flow_db_version', CK_JOIN_FLOW_VERSION);

        Upgrade::check([]); // no migrations to run; just stamp

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // check() — concurrent-invocation lock
    // ------------------------------------------------------------------

    /**
     * If a previous request is mid-upgrade (transient lock held), a second
     * concurrent check() must be a no-op so we don't double-run migrations
     * or hit Stripe twice. Cheap protection against the WordPress request
     * concurrency model on busy sites.
     */
    public function testCheckIsNoOpWhenUpgradeLockIsHeld(): void
    {
        Monkey\Functions\expect('get_option')
            ->with('ck_join_flow_db_version', '0.0.0')
            ->andReturn('1.4.8');

        // Lock is already held by another request.
        Monkey\Functions\expect('get_transient')
            ->with('ck_join_flow_upgrading')
            ->andReturn(true);

        // Must NOT touch any of these — another request owns the upgrade.
        Monkey\Functions\expect('set_transient')->never();
        Monkey\Functions\expect('delete_transient')->never();
        Monkey\Functions\expect('update_option')->never();

        $invoked = false;
        Upgrade::check([
            CK_JOIN_FLOW_VERSION => [function () use (&$invoked) {
                $invoked = true;
            }],
        ]);

        $this->assertFalse($invoked, 'Migration must not run while lock is held');
    }

    // ------------------------------------------------------------------
    // upgradeJoinFlow() — version filtering and ordering
    // ------------------------------------------------------------------

    /**
     * Migrations keyed at or below $fromVersion have already run on a
     * previous upgrade and must be skipped — running them again could
     * double-charge Stripe or duplicate option writes.
     */
    public function testUpgradeJoinFlowSkipsMigrationsAtOrBelowFromVersion(): void
    {
        Monkey\Functions\expect('update_option')->once()->andReturn(true);

        $alreadyRan = false;
        $shouldRun = false;

        Upgrade::upgradeJoinFlow('1.4.9', [
            '1.4.9' => [function () use (&$alreadyRan) {
                $alreadyRan = true;
            }],
            '1.5.0' => [function () use (&$shouldRun) {
                $shouldRun = true;
            }],
        ]);

        $this->assertFalse($alreadyRan, '1.4.9 migration should NOT re-run when from=1.4.9');
        $this->assertTrue($shouldRun, '1.5.0 migration SHOULD run when from=1.4.9');
    }

    /**
     * Migrations must run in ascending version order. A 1.4.9 → 1.5.1
     * upgrade must run 1.5.0's migration before 1.5.1's, even if the map
     * is declared out of order.
     */
    public function testUpgradeJoinFlowRunsMigrationsInAscendingVersionOrder(): void
    {
        Monkey\Functions\expect('update_option')->once()->andReturn(true);

        $callOrder = [];

        Upgrade::upgradeJoinFlow('1.4.9', [
            '1.5.1' => [function () use (&$callOrder) {
                $callOrder[] = '1.5.1';
            }],
            '1.5.0' => [function () use (&$callOrder) {
                $callOrder[] = '1.5.0';
            }],
        ]);

        $this->assertSame(['1.5.0', '1.5.1'], $callOrder);
    }

    /**
     * If a migration throws, the dispatcher must NOT bump the stored
     * version — leaving it stale ensures the next request retries the
     * migration, surfacing the failure instead of silently skipping it.
     * The exception must propagate so monitoring (Sentry, Teams) catches it.
     */
    public function testUpgradeJoinFlowDoesNotBumpVersionWhenMigrationThrows(): void
    {
        // No update_option call expected on the failure path.
        Monkey\Functions\expect('update_option')->never();

        $this->expectException(RuntimeException::class);

        Upgrade::upgradeJoinFlow('1.4.8', [
            '1.4.9' => [function () {
                throw new RuntimeException('migration boom');
            }],
        ]);
    }

    /**
     * Happy path: when every migration succeeds, the stored version is
     * stamped to CK_JOIN_FLOW_VERSION exactly once.
     */
    public function testUpgradeJoinFlowStampsVersionAfterAllMigrationsSucceed(): void
    {
        Monkey\Functions\expect('update_option')
            ->once()
            ->with('ck_join_flow_db_version', CK_JOIN_FLOW_VERSION)
            ->andReturn(true);

        // Two distinct version keys so both callbacks run regardless of what
        // CK_JOIN_FLOW_VERSION currently is.
        $ranA = false;
        $ranB = false;

        Upgrade::upgradeJoinFlow('1.4.8', [
            '1.4.9' => [function () use (&$ranA) {
                $ranA = true;
            }],
            '1.5.0' => [function () use (&$ranB) {
                $ranB = true;
            }],
        ]);

        $this->assertTrue($ranA);
        $this->assertTrue($ranB);
    }

    // ------------------------------------------------------------------
    // Production migration map sanity check
    // ------------------------------------------------------------------

    /**
     * Sanity check that the production migration map declares the 1.4.9
     * rekey migration. Pins the map's contract — if someone deletes the
     * entry, this fails and forces them to think about why.
     */
    public function testProductionMigrationMapIncludesRekeyForOneFourNine(): void
    {
        $map = Upgrade::getMigrationsForTesting();

        $this->assertArrayHasKey('1.4.9', $map);
        $this->assertContains(
            'rekeyMembershipPlanOptions',
            array_map(
                fn($entry) => is_array($entry) ? ($entry[1] ?? $entry[0]) : $entry,
                $map['1.4.9']
            )
        );
    }
}
