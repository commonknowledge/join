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
        @unlink($logFile);

        // Start two processes in parallel
        // Each script takes 2 seconds to complete. Therefore, if
        // they run in parallel, they should both complete in a little
        // over 2 seconds. However, if they run in series, they will not
        // both be complete until after 4 seconds.
        exec("php $scriptPath $sessionId > /dev/null 2>&1 &");
        exec("php $scriptPath $sessionId > /dev/null 2>&1 &");

        sleep(3);

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
