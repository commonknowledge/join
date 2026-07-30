<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Handlers\StartupBufferHandler;
use CommonKnowledge\JoinBlock\Logging;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the two-phase logging setup.
 *
 * The logger exists from the moment the plugin file loads, but its
 * destination is a setting, so it cannot be chosen until Carbon Fields has
 * registered its fields. Records made in between are buffered and replayed. If
 * that replay breaks, the logs vanish silently - Monolog discards records when
 * a logger has no handler - so the startup path is covered here.
 */
class LoggingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/join-block-test-' . bin2hex(random_bytes(6));
        mkdir($this->uploadsDir, 0777, true);

        Monkey\Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $this->uploadsDir,
            'error' => false,
        ]);
        Monkey\Functions\when('wp_mkdir_p')->alias(function ($dir) {
            return is_dir($dir) || mkdir($dir, 0777, true);
        });

        unset($_ENV['LOGGLY_TOKEN'], $_ENV['LOGGLY_TAGS']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['LOGGLY_TOKEN'], $_ENV['LOGGLY_TAGS']);
        $this->deleteDirectory($this->uploadsDir);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function readLogFile(): string
    {
        $logDir = $this->uploadsDir . '/join-block-logs';
        $contents = '';
        foreach (scandir($logDir) ?: [] as $entry) {
            if (str_starts_with($entry, 'debug-')) {
                $contents .= file_get_contents($logDir . '/' . $entry);
            }
        }
        return $contents;
    }

    // -------------------------------------------------------------------------
    // Startup buffering
    // -------------------------------------------------------------------------

    public function testRecordsLoggedBeforeConfigureReachTheLogFile(): void
    {
        global $joinBlockLog;

        Logging::init();
        $joinBlockLog->info('logged during startup');

        $this->assertFalse(Logging::isConfigured());

        Logging::configure();
        $joinBlockLog->info('logged after configure');

        $log = $this->readLogFile();
        $this->assertStringContainsString('logged during startup', $log);
        $this->assertStringContainsString('logged after configure', $log);
    }

    public function testStartupRecordsAreNotWrittenTwice(): void
    {
        global $joinBlockLog;

        Logging::init();
        $joinBlockLog->info('logged once');
        Logging::configure();

        $this->assertSame(1, substr_count($this->readLogFile(), 'logged once'));
    }

    public function testTheBufferIsRemovedFromTheLoggerOnceConfigured(): void
    {
        global $joinBlockLog;

        Logging::init();
        Logging::configure();

        $this->assertTrue(Logging::isConfigured());
        foreach ($joinBlockLog->getHandlers() as $handler) {
            $this->assertNotInstanceOf(StartupBufferHandler::class, $handler);
        }
    }

    public function testConfigureIsIdempotent(): void
    {
        global $joinBlockLog;

        Logging::init();
        Logging::configure();
        $handlerCount = count($joinBlockLog->getHandlers());

        Logging::configure();
        $joinBlockLog->info('logged once');

        $this->assertCount($handlerCount, $joinBlockLog->getHandlers());
        $this->assertSame(1, substr_count($this->readLogFile(), 'logged once'));
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function testLogglySettingsFallBackToTheEnvironmentBeforeFieldsAreRegistered(): void
    {
        $_ENV['LOGGLY_TOKEN'] = 'token-from-env';
        $_ENV['LOGGLY_TAGS'] = 'join-block, example-org ,,  live ';

        $this->assertSame('token-from-env', Logging::getLogglyToken());
        $this->assertTrue(Logging::isLogglyEnabled());
        $this->assertSame(['join-block', 'example-org', 'live'], Logging::getLogglyTags());
    }

    public function testLogglyIsDisabledWithoutAToken(): void
    {
        $this->assertSame('', Logging::getLogglyToken());
        $this->assertFalse(Logging::isLogglyEnabled());
    }

    public function testTagsDefaultWhenNoneAreConfigured(): void
    {
        $_ENV['LOGGLY_TOKEN'] = 'token-from-env';

        $this->assertSame(['join-block'], Logging::getLogglyTags());
    }

    public function testAWhitespaceOnlyTokenLeavesLogglyDisabled(): void
    {
        $_ENV['LOGGLY_TOKEN'] = '   ';

        $this->assertSame('', Logging::getLogglyToken());
        $this->assertFalse(Logging::isLogglyEnabled());
    }

    public function testNoLogFileIsCreatedWhenLogglyIsEnabled(): void
    {
        global $joinBlockLog;

        if (!extension_loaded('curl')) {
            $this->markTestSkipped('The curl extension is required for the Loggly handler.');
        }

        $_ENV['LOGGLY_TOKEN'] = 'token-from-env';

        Logging::init();
        Logging::configure();

        // No log line is written here: the Loggly handler buffers until
        // shutdown, and this test must not make a network request.
        $this->assertDirectoryDoesNotExist($this->uploadsDir . '/join-block-logs');
    }

    // -------------------------------------------------------------------------
    // StartupBufferHandler
    // -------------------------------------------------------------------------

    public function testTheStartupBufferKeepsRecordsAndCountsOverflow(): void
    {
        $buffer = new StartupBufferHandler();
        $logger = new Logger('test');
        $logger->pushHandler($buffer);

        $total = StartupBufferHandler::MAX_RECORDS + 5;
        for ($i = 0; $i < $total; $i++) {
            $logger->info("record $i");
        }

        $this->assertCount(StartupBufferHandler::MAX_RECORDS, $buffer->getRecords());
        $this->assertSame(5, $buffer->getDiscardedCount());
        $this->assertSame('record 0', $buffer->getRecords()[0]->message);
    }

    public function testTheStartupBufferKeepsDebugRecords(): void
    {
        $buffer = new StartupBufferHandler();

        $this->assertTrue($buffer->isHandling(
            new \Monolog\LogRecord(
                new \DateTimeImmutable(),
                'join-block',
                Level::Debug,
                'a debug message'
            )
        ));
    }
}
