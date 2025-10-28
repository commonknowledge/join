<?php

namespace CommonKnowledge\JoinBlock;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use Google\Cloud\Logging\LoggingClient;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\WebProcessor;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

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
        $joinBlockLog->pushHandler(new RotatingFileHandler("$logLocation/$logFilename", 10, Level::Info));
        $joinBlockLog->pushProcessor(new WebProcessor());
    }

    public static function enableSentry()
    {
        global $joinBlockLog;
        $joinBlockLog->pushHandler(new \Sentry\Monolog\BreadcrumbHandler(
            hub: \Sentry\SentrySdk::getCurrentHub(),
            level: Level::Info,
        ));
        $joinBlockLog->pushHandler(new \Sentry\Monolog\Handler(
            hub: \Sentry\SentrySdk::getCurrentHub(),
            level: Level::Error,
            fillExtraContext: false,
        ));
    }

    public static function enableGoogleCloud($projectId, $keyFileContents)
    {
        global $joinBlockLog;

        $config = [
            'projectId' => $projectId,
            'keyFile' => json_decode($keyFileContents, true)
        ];

        $logging = new LoggingClient($config);

        $batchLogger = $logging->psrBatchLogger('join-flow', ['clientConfig' => $config]);
        $joinBlockLog->pushHandler(new PsrHandler($batchLogger));
    }
}
