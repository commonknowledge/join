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
    public static function getLogDirectory()
    {
        // Prefer the uploads dir (survives plugin updates), but fall back to
        // WP_CONTENT_DIR if uploads is misconfigured or unwritable.
        $uploads = wp_upload_dir(null, false);
        $basedir = (is_array($uploads) && empty($uploads['error']) && !empty($uploads['basedir']))
            ? $uploads['basedir']
            : null;

        $candidates = [];
        if ($basedir) {
            $candidates[] = $basedir . '/join-block-logs';
        }
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = WP_CONTENT_DIR . '/join-block-logs';
        }

        $logLocation = null;
        $created = false;
        foreach ($candidates as $candidate) {
            $existed = is_dir($candidate);
            if (!$existed && !wp_mkdir_p($candidate)) {
                continue;
            }
            if (!is_writable($candidate)) {
                continue;
            }
            $logLocation = $candidate;
            $created = !$existed;
            break;
        }

        if ($logLocation === null) {
            error_log(
                'join-block: unable to create a writable log directory (tried: '
                . implode(', ', $candidates ?: ['<none>']) . '); '
                . 'file-based logging disabled for this request'
            );
            return null;
        }

        // On first creation, migrate any pre-existing logs from the old
        // in-plugin location, which WordPress wipes on plugin update.
        if ($created) {
            $legacyLocation = __DIR__ . '/../logs';
            if (is_dir($legacyLocation)) {
                $legacyFiles = scandir($legacyLocation) ?: [];
                foreach ($legacyFiles as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $src = $legacyLocation . '/' . $file;
                    $dst = $logLocation . '/' . $file;
                    if (is_file($src) && !file_exists($dst)) {
                        @copy($src, $dst);
                    }
                }
            }
        }

        return $logLocation;
    }

    public static function init()
    {
        global $joinBlockLog;
        $joinBlockLog = new Logger('join-block');
        $logLocation = self::getLogDirectory();
        if ($logLocation !== null) {
            $logFilenameHash = null;
            $logFiles = scandir($logLocation) ?: [];
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
        }
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
