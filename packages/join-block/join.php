<?php
/**
 * Plugin Name:     The Green Party Join Plugin
 * Description:     Green Party join flow plugin.
 * Version:         0.0.5
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     uk-greens
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

add_action('after_setup_theme', function () {
    \Carbon_Fields\Carbon_Fields::boot();
});

require 'lib/settings.php';
require 'lib/services/join_service.php';
require 'lib/services/gocardless_service.php';

require 'lib/blocks.php';

use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;

$joinBlockLog = new Logger('join-block');
$joinBlockLog->pushHandler(new ErrorLogHandler());

if ($_ENV['DEBUG_JOIN_FLOW'] === 'true') {
    $joinBlockLog->warning('DEBUG_JOIN_FLOW environment variable set, meaning join form starting in debug mode - using local frontend serving from http://localhost:3000/bundle.js');
}

add_action('rest_api_init', function () {
    register_rest_route('join/v1', '/join', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function ($req) {
            return rest_ensure_response(handle_join($req->get_json_params()));
        },
    ));
});


add_action('init', 'uk_greens_join_block_init');
function uk_greens_join_block_init()
{
    $directoryName = dirname(__FILE__);

    $joinFormJavascriptBundleLocation = 'build/join-flow/bundle.js';

    if ($_ENV['DEBUG_JOIN_FLOW'] === 'true') {
        wp_enqueue_script(
            'uk-greens-join-block-js',
            "http://localhost:3000/bundle.js",
            array(),
            false,
            true
        );
    } else {
        wp_enqueue_script(
            'uk-greens-join-block-js',
            plugins_url($joinFormJavascriptBundleLocation, __FILE__),
            array(),
            filemtime("$directoryName/$bundle_js"),
            true
        );
    }
}
