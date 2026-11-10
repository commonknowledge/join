<?php
/**
 * Writes raw option rows into the database, bypassing the settings page.
 *
 * Used by the settings regression tests to load settings in the format a
 * previous version of the plugin stored them in.
 *
 * Run via:
 *   scripts/env.sh wp eval-file /var/www/html/wp-content/e2e-scripts/load-raw-options.php '{"option_name": "value"}'
 */

if (!defined('ABSPATH')) {
    exit;
}

$options = json_decode($args[0] ?? '', true);
if (!is_array($options)) {
    WP_CLI::error('Expected a JSON object of option names to values.');
}

foreach ($options as $name => $value) {
    update_option($name, $value);
}

WP_CLI::success(count($options) . ' options written.');
