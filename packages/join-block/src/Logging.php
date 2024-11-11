<?php

namespace CommonKnowledge\JoinBlock;

use Monolog\Logger;
use Monolog\Processor\WebProcessor;
use Monolog\Handler\RotatingFileHandler;

class Logging
{
    public static function init()
    {
        global $joinBlockLog;
        $joinBlockLog = new Logger('join-block');
        $logFilenameHash = null;
        $logLocation = __DIR__ . "/../logs";
        $logFiles = scandir($logLocation);
        foreach ($logFiles as $logFile) {
            if (str_starts_with($logFile, "debug-")) {
                $parts = explode("-", $logFile);
                $logFilenameHash = $parts[1];
                break;
            }
        }
        if (!$logFilenameHash) {
            $logFilenameHash = bin2hex(random_bytes(18));
        }
        $logFilename = "debug-$logFilenameHash.log";
        $joinBlockLog->pushHandler(new RotatingFileHandler("$logLocation/$logFilename", 10, Logger::INFO));
        $joinBlockLog->pushProcessor(new WebProcessor());
    }
}
