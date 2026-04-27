<?php

// Define ABSPATH so service files don't bail out early
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../wordpress/');
}

// WordPress query result format constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../vendor/autoload.php';
