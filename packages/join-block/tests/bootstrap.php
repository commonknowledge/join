<?php

// Define ABSPATH so service files don't bail out early
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../wordpress/');
}

// WordPress query result format constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Plugin version. Production code reads this from the matching define() in
// join.php; tests pin it here so Upgrade can compare against a known value
// without bootstrapping the whole plugin.
if (!defined('CK_JOIN_FLOW_VERSION')) {
    define('CK_JOIN_FLOW_VERSION', '1.4.9');
}

require_once __DIR__ . '/../vendor/autoload.php';
