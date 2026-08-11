<?php

namespace CommonKnowledge\JoinBlock\Handlers;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Holds log records made before the real handlers can be attached.
 *
 * The plugin's log destination is a setting, and settings cannot be read until
 * Carbon Fields has registered its fields (see Logging::configure()). Anything
 * logged before that point has nowhere to go, and a Monolog logger with no
 * handlers discards records silently. This handler keeps them in memory
 * instead, so Logging::configure() can replay them into the real handlers once
 * it knows where logs are meant to go.
 */
class StartupBufferHandler extends AbstractProcessingHandler
{
    /**
     * Startup is short and nothing should be logging in bulk during it. The
     * cap only exists so that a runaway caller cannot exhaust memory before
     * the buffer is flushed.
     */
    public const MAX_RECORDS = 500;

    /** @var LogRecord[] */
    private array $records = [];

    private int $discarded = 0;

    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (count($this->records) >= self::MAX_RECORDS) {
            $this->discarded++;
            return;
        }
        $this->records[] = $record;
    }

    /**
     * @return LogRecord[]
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * The number of records dropped because the buffer was full.
     */
    public function getDiscardedCount(): int
    {
        return $this->discarded;
    }

    public function clear(): void
    {
        $this->records = [];
        $this->discarded = 0;
    }
}
