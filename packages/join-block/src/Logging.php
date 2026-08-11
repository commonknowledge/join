<?php

namespace CommonKnowledge\JoinBlock;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use CommonKnowledge\JoinBlock\Handlers\StartupBufferHandler;
use Google\Cloud\Logging\LoggingClient;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\LogglyHandler;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\WebProcessor;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * Logging is set up in two phases.
 *
 * init() runs when the plugin file loads and creates the logger, so that
 * $joinBlockLog is usable from the very first line of the request. It cannot
 * decide where logs should go, because the destination is a setting and
 * settings cannot be read until Carbon Fields has registered its fields.
 * Until then records are held by a StartupBufferHandler.
 *
 * configure() runs once fields are registered (on 'init' priority 0), reads
 * the settings, attaches the real handlers and replays anything buffered
 * during startup into them.
 *
 * Add new log destinations in configure(), not init(), and return the handler
 * so that startup records reach it too.
 */
class Logging
{
    private const DEFAULT_LOG_RETENTION_DAYS = 10;
    private const DEFAULT_LOGGLY_TAG = 'join-block';

    private static bool $configured = false;

    private static ?StartupBufferHandler $startupBuffer = null;

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

    /**
     * Number of days of rotated log files to keep.
     *
     * The settings page value takes precedence, then the
     * JOIN_FLOW_LOG_RETENTION_DAYS environment variable, then the default.
     */
    public static function getLogRetentionDays()
    {
        $days = (int) self::getSetting('join_flow_log_retention_days');
        return $days > 0 ? $days : self::DEFAULT_LOG_RETENTION_DAYS;
    }

    /**
     * Whether Carbon Fields is far enough along for Settings::get() to work.
     *
     * Reading a field earlier than this raises an Incorrect_Syntax_Exception
     * from Carbon Fields, which in turn wp_die()s the request.
     */
    private static function carbonFieldsHasRegisteredFields()
    {
        return doing_action('carbon_fields_register_fields')
            || did_action('carbon_fields_fields_registered') > 0;
    }

    /**
     * Read a logging setting.
     *
     * Uses Settings::get() where possible. The fallback only applies when
     * configure() has had to run without Carbon Fields (see configure()), in
     * which case the settings page values are unreachable and the environment
     * is all we have.
     */
    private static function getSetting($name)
    {
        if (self::carbonFieldsHasRegisteredFields()) {
            $value = Settings::get($name);
        } else {
            // Ignore sanitization error, consistent with Settings::get().
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $value = $_ENV[strtoupper($name)] ?? '';
        }
        return is_string($value) ? trim($value) : '';
    }

    /**
     * The Loggly customer token. When set, logs are sent to Loggly and
     * nothing is written to disk.
     */
    public static function getLogglyToken()
    {
        return self::getSetting('loggly_token');
    }

    /**
     * Tags used to identify this site's logs in Loggly.
     *
     * @return string[]
     */
    public static function getLogglyTags()
    {
        $tags = self::getSetting('loggly_tags');
        $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
        return $tags ?: [self::DEFAULT_LOGGLY_TAG];
    }

    public static function isLogglyEnabled()
    {
        return self::getLogglyToken() !== '';
    }

    public static function isConfigured()
    {
        return self::$configured;
    }

    /**
     * Phase one: create the logger. No destination is chosen yet.
     */
    public static function init()
    {
        global $joinBlockLog;

        self::$configured = false;
        $joinBlockLog = new Logger('join-block');
        $joinBlockLog->pushProcessor(new WebProcessor());

        self::$startupBuffer = new StartupBufferHandler();
        $joinBlockLog->pushHandler(self::$startupBuffer);

        // Last resort. If configure() never runs, the buffered records would
        // otherwise disappear without trace, exactly when something is broken
        // enough to be worth reading about.
        register_shutdown_function([self::class, 'reportUnconfiguredLogs']);
    }

    /**
     * Phase two: read the settings and attach the real handlers.
     *
     * Safe to call more than once and from more than one hook; only the first
     * call does anything. It is called from the 'carbon_fields_register_fields'
     * handler in join.php, with a later fallback in case that never fires
     * (for example when another copy of Carbon Fields booted first).
     */
    public static function configure()
    {
        global $joinBlockLog;

        if (self::$configured || !$joinBlockLog) {
            return;
        }
        self::$configured = true;

        $degraded = !self::carbonFieldsHasRegisteredFields();

        $handlers = array_merge(
            self::attachLogglyOrFileHandler(),
            self::attachSentry(),
            self::attachGoogleCloud()
        );

        self::flushStartupBuffer($handlers);

        if ($degraded) {
            $joinBlockLog->warning(
                'Carbon Fields did not register its fields, so logging was configured from ' .
                'environment variables only. Settings page values were ignored.'
            );
        }
    }

    /**
     * Loggly replaces on-disk logging entirely: when it is configured, no log
     * file is created and no existing log file is written to.
     *
     * @return HandlerInterface[]
     */
    private static function attachLogglyOrFileHandler()
    {
        global $joinBlockLog;

        $token = self::getLogglyToken();
        if ($token !== '') {
            if (extension_loaded('curl')) {
                $logglyHandler = new LogglyHandler($token, Level::Info);
                $logglyHandler->setTag(self::getLogglyTags());
                // Buffer so the request makes a single bulk call to Loggly at
                // shutdown rather than one HTTP request per log line.
                $handler = new BufferHandler($logglyHandler, 0, Level::Info);
                $joinBlockLog->pushHandler($handler);
                return [$handler];
            }
            error_log(
                'join-block: Loggly is configured but the curl extension is missing; ' .
                'falling back to file-based logging'
            );
        }

        $logLocation = self::getLogDirectory();
        if ($logLocation === null) {
            return [];
        }

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
        $handler = new RotatingFileHandler(
            "$logLocation/$logFilename",
            self::getLogRetentionDays(),
            Level::Info
        );
        $joinBlockLog->pushHandler($handler);

        return [$handler];
    }

    /**
     * @return HandlerInterface[]
     */
    public static function attachSentry()
    {
        global $joinBlockLog;

        $sentryDsn = self::getSetting('sentry_dsn');
        if (!$sentryDsn) {
            return [];
        }

        \Sentry\init(['dsn' => $sentryDsn]);

        $handlers = [
            new \Sentry\Monolog\BreadcrumbHandler(
                hub: \Sentry\SentrySdk::getCurrentHub(),
                level: Level::Info,
            ),
            new \Sentry\Monolog\Handler(
                hub: \Sentry\SentrySdk::getCurrentHub(),
                level: Level::Error,
                fillExtraContext: false,
            ),
        ];
        foreach ($handlers as $handler) {
            $joinBlockLog->pushHandler($handler);
        }

        return $handlers;
    }

    /**
     * @return HandlerInterface[]
     */
    public static function attachGoogleCloud()
    {
        global $joinBlockLog;

        $projectId = self::getSetting('google_cloud_project_id');
        $keyFileContents = self::getSetting('google_cloud_key_file_contents');
        if (!$projectId || !$keyFileContents) {
            return [];
        }

        $config = [
            'projectId' => $projectId,
            'keyFile' => json_decode($keyFileContents, true)
        ];

        $logging = new LoggingClient($config);

        $batchLogger = $logging->psrBatchLogger('join-flow', ['clientConfig' => $config]);
        $handler = new PsrHandler($batchLogger);
        $joinBlockLog->pushHandler($handler);

        return [$handler];
    }

    /**
     * Replay startup records into the handlers just attached, and take the
     * buffer out of the logger so it stops collecting.
     *
     * Only the new handlers are replayed into. Any handler attached before
     * configure() ran - the Microsoft Teams one in join.php, for instance -
     * has already seen these records.
     *
     * @param HandlerInterface[] $handlers
     */
    private static function flushStartupBuffer(array $handlers)
    {
        global $joinBlockLog;

        $buffer = self::$startupBuffer;
        if ($buffer === null) {
            return;
        }
        self::$startupBuffer = null;

        $joinBlockLog->setHandlers(array_values(array_filter(
            $joinBlockLog->getHandlers(),
            function ($handler) use ($buffer) {
                return $handler !== $buffer;
            }
        )));

        foreach ($buffer->getRecords() as $record) {
            foreach ($handlers as $handler) {
                $handler->handle($record);
            }
        }

        $discarded = $buffer->getDiscardedCount();
        if ($discarded > 0) {
            $joinBlockLog->warning(
                "Dropped $discarded log record(s) made before logging was configured, " .
                "as the startup buffer was full. Something is logging heavily during plugin load."
            );
        }

        $buffer->clear();
    }

    /**
     * Shutdown fallback: if configure() never ran, send whatever was buffered
     * to the PHP error log so the records are not lost.
     */
    public static function reportUnconfiguredLogs()
    {
        $buffer = self::$startupBuffer;
        if (self::$configured || $buffer === null) {
            return;
        }
        self::$startupBuffer = null;

        $records = $buffer->getRecords();
        $buffer->clear();
        if (!$records) {
            return;
        }

        error_log(
            'join-block: logging was never configured this request, so ' . count($records) .
            ' record(s) had no destination. They follow:'
        );
        foreach ($records as $record) {
            error_log('join-block: ' . $record->level->getName() . ': ' . $record->message);
        }
    }
}
