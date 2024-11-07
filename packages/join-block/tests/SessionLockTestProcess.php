<?php

require_once(__DIR__ . "/../vendor/autoload.php");

// Load WordPress functions required by JoinService::lockSession()
define( 'ABSPATH', __DIR__ . '/../wordpress/' );
define( 'WPINC', 'wp-includes' );
require_once(__DIR__ . "/../wordpress/wp-includes/formatting.php");
require_once(__DIR__ . "/../wordpress/wp-includes/functions.php");

use CommonKnowledge\JoinBlock\Services\JoinService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * This is the child script of SessionLockTest. It simulates
 * running an exclusive section of code. It logs its
 * execution to a file so it can be monitored by
 * the parent process.
 */

// Set up log file so SessionLockTest can monitor progress of this script
global $joinBlockLog;
$joinBlockLog = new Logger('join-block-test');
$joinBlockLogLocation = __DIR__ . '/../logs/tests.log';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
$joinBlockLogFile = fopen($joinBlockLogLocation, 'a');
$joinBlockLog->pushHandler(new StreamHandler($joinBlockLogFile, 10, Logger::INFO));

$sessionId = $argv[1];

$joinBlockLog->info("Testing lock with sessionId $sessionId");

$lockFile = JoinService::lockSession($sessionId);

$joinBlockLog->info("WORKING");
// Simulate work
sleep(1);
$joinBlockLog->info("DONE");

JoinService::unlockSession($lockFile);
