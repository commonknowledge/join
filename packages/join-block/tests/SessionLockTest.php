<?php

namespace CommonKnowledge\JoinBlock\Tests;

use PHPUnit\Framework\TestCase;

class SessionLockTest extends TestCase
{
    public int $safeCounter = 0;
    public int $unsafeCounter = 0;

    /**
     * Test the session lock works. This is tested by making sure that
     * processes that use the lock do not run in parallel.
     */
    public function testSessionLock(): void
    {
        $scriptPath = __DIR__ . "/SessionLockTestProcess.php";
        $sessionId = microtime(true);
        $logFile = __DIR__ . "/../logs/tests.log";
        // Ensure clean log file output
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink($logFile);

        // Start two processes in parallel
        // Each script takes 1 second to complete. Therefore, if
        // they run in parallel, they should both complete in a little
        // over 1 second. However, if they run in series, they will not
        // both be complete until after 2 seconds.
        exec("php $scriptPath $sessionId > /dev/null 2>&1 &");
        exec("php $scriptPath $sessionId > /dev/null 2>&1 &");

        sleep(3);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $logs = file_get_contents($logFile);
        # Ensure that logs print:
        #    WORKING -> DONE -> Unlocked ... $sessionId -> WORKING -> DONE -> Unlocked ... $sessionId
        # Proving sequential execution
        $matched = preg_match(
            "#WORKING.*DONE.*Unlocked.*$sessionId.*WORKING.*DONE.*Unlocked.*$sessionId#s",
            $logs
        );
        $this->assertTrue((bool) $matched, "Should have expected sequence of logs");
    }
}
