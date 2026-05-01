<?php

namespace CommonKnowledge\JoinBlock;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin upgrade dispatcher.
 *
 * Modelled on the WooCommerce WC_Install pattern: store the plugin db_version
 * in wp_options, compare against CK_JOIN_FLOW_VERSION on every `init` request,
 * and run any pending per-version migration callbacks when the stored value
 * is older.
 *
 * To add a new migration:
 *   1. Add a private static method on this class (or a free function in this
 *      namespace) that performs the one-shot data fix idempotently.
 *   2. Append an entry to self::$migrations under the version that introduces
 *      the change, e.g.
 *
 *          '1.5.0' => ['renameLegacyTagOption'],
 *
 *   3. Bump CK_JOIN_FLOW_VERSION via scripts/bump-version.sh and ship.
 *
 * The next request after deploy will run upgradeJoinFlow() once, dispatch each
 * pending migration in ascending version order, and stamp the new version.
 */
class Upgrade
{
    /**
     * Map of plugin-version => list of migration callbacks to run on upgrade
     * to that version. A callback may be a method name on this class (string)
     * or any PHP callable.
     */
    private static array $migrations = [
        '1.4.9' => ['rekeyMembershipPlanOptions'],
    ];

    /** Transient key for the cross-request upgrade lock. */
    private const LOCK_KEY = 'ck_join_flow_upgrading';

    /** wp_options key holding the stored plugin db_version. */
    private const VERSION_OPTION = 'ck_join_flow_db_version';

    /**
     * Hooked to the WordPress `init` action. Cheap no-op when the stored
     * db_version already matches CK_JOIN_FLOW_VERSION (the common case on
     * every page load after a successful upgrade).
     *
     * The $migrations parameter is injectable purely so unit tests can
     * supply test doubles; production callers omit it and use the static
     * map. Avoiding a mutable static keeps tests order-independent.
     */
    public static function check(?array $migrations = null): void
    {
        $stored = get_option(self::VERSION_OPTION, '0.0.0');
        if (version_compare($stored, CK_JOIN_FLOW_VERSION, '>=')) {
            return;
        }

        if (get_transient(self::LOCK_KEY)) {
            // Another request in this PHP-FPM pool is already running the
            // upgrade. Bail rather than double-run migrations.
            return;
        }

        // 60s is plenty for the rekey migration; long enough that a slow
        // Stripe round-trip doesn't release the lock prematurely, short
        // enough that a crashed worker doesn't wedge upgrades indefinitely.
        set_transient(self::LOCK_KEY, 1, 60);

        try {
            self::upgradeJoinFlow($stored, $migrations);
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    /**
     * Run every migration in the map whose version key is strictly newer
     * than $fromVersion, in ascending version order. Stamps the stored
     * db_version only after every migration succeeds — on failure the
     * stored version is left stale so the next request retries.
     *
     * Public so the migration sequence can be triggered manually from WP-CLI
     * or a debug tool. Migrations are injectable via the second argument
     * solely so unit tests can supply test doubles; production callers omit
     * it and use self::$migrations.
     */
    public static function upgradeJoinFlow(string $fromVersion, ?array $migrations = null): void
    {
        global $joinBlockLog;

        $migrations = $migrations ?? self::$migrations;

        $pending = [];
        foreach ($migrations as $version => $callbacks) {
            if (version_compare($fromVersion, $version, '<')) {
                $pending[$version] = $callbacks;
            }
        }
        uksort($pending, 'version_compare');

        if (!$pending) {
            // No migrations to run, but the version may still need stamping
            // (e.g. fresh install or a no-migration version bump).
            update_option(self::VERSION_OPTION, CK_JOIN_FLOW_VERSION);
            return;
        }

        if ($joinBlockLog) {
            $joinBlockLog->info(
                "Running join-flow upgrade from {$fromVersion} to " . CK_JOIN_FLOW_VERSION,
                ['pending' => array_keys($pending)]
            );
        }

        foreach ($pending as $version => $callbacks) {
            foreach ($callbacks as $callback) {
                $callable = is_string($callback) ? [self::class, $callback] : $callback;
                if ($joinBlockLog) {
                    $label = is_string($callback) ? $callback : 'closure';
                    $joinBlockLog->info("Running migration for {$version}: {$label}");
                }
                call_user_func($callable);
            }
        }

        update_option(self::VERSION_OPTION, CK_JOIN_FLOW_VERSION);

        if ($joinBlockLog) {
            $joinBlockLog->info('Join-flow upgrade complete; db_version=' . CK_JOIN_FLOW_VERSION);
        }
    }

    /**
     * Migration for v1.4.9: re-key all stored membership plans under the
     * new label_frequency_currency slug format introduced in v1.4.4.
     *
     * Idempotent — running this on already-correctly-keyed plans is a no-op
     * because saveMembershipPlans() writes to the same key it reads from.
     * Old-format option rows are deliberately not deleted; the
     * getMembershipPlan() fallback continues to read them as a safety net.
     */
    private static function rekeyMembershipPlanOptions(): void
    {
        $plans = Settings::get('MEMBERSHIP_PLANS') ?? [];
        if (!$plans) {
            return;
        }
        Settings::saveMembershipPlans($plans);
    }

    /** @internal Used by UpgradeTest to assert the production map's contents. */
    public static function getMigrationsForTesting(): array
    {
        return self::$migrations;
    }
}
