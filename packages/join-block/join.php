<?php

/**
 * Plugin Name:     Common Knowledge Join Plugin
 * Description:     Common Knowledge join flow plugin.
 * Version:         1.0.6
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     uk-greens
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use ChargeBee\ChargeBee\Environment;
use CommonKnowledge\JoinBlock\Services\JoinService;
use CommonKnowledge\JoinBlock\Blocks;
use CommonKnowledge\JoinBlock\Settings;
use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Processor\WebProcessor;
use GuzzleHttp\Exception\ClientException;

$joinBlockLog = new Logger('join-block');
$joinBlockLog->pushHandler(new ErrorLogHandler());
$joinBlockLog->pushProcessor(new WebProcessor());

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);

try {
    $dotenv->load();
} catch (\Throwable $e) {
    // Ignore missing .env as all settings are also available in the
    // plugin settings page
    $joinBlockLog->debug("Could not load environment variables from .env file: " . $e->getMessage());
}

add_action('after_setup_theme', function () {
    \Carbon_Fields\Carbon_Fields::boot();
});

add_action('carbon_fields_register_fields', function () {
    Settings::init();
    Blocks::init();
});

$teamsWebhook = $_ENV['MICROSOFT_TEAMS_INCOMING_WEBHOOK'] ?? null;
if ($teamsWebhook) {
    $joinBlockLog->pushHandler(
        new \CMDISP\MonologMicrosoftTeams\TeamsLogHandler(
            $teamsWebhook,
            \Monolog\Logger::ERROR
        )
    );
}

add_action('rest_api_init', function () {
    register_rest_route('join/v1', '/join', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $joinBlockLog->info('CK Join process started', ['request' => $request]);

            try {
                JoinService::handleJoin($request->get_json_params());
                $joinBlockLog->info('Join process successful');
            } catch (ClientException $error) {
                $joinBlockLog->error(
                    'Join process failed at Auth0 user creation, but customer created in Chargebee.',
                    ['error' => $error]
                );
            } catch (Error $error) {
                $joinBlockLog->error('Join process failed', ['error' => $error]);
                return new WP_Error(
                    'join_failed',
                    'Join process failed',
                    ['status' => 500, 'error_code' => $error->getCode(), 'error_message' => $error->getMessage()]
                );
            } catch (\CommonKnowledge\JoinBlock\Exceptions\JoinBlockException $exception) {
                $joinBlockLog->error(
                    'Join process failed',
                    ['error' => $exception, 'fields' => $exception->getFields()]
                );
                return new WP_Error(
                    'join_failed',
                    'Join process failed',
                    [
                        'status' => 500,
                        'error_code' => $exception->getCode(),
                        'error_message' => $exception->getMessage(),
                        'fields' => $exception->getFields()
                    ]
                );
            }

            return new WP_REST_Response(['status' => 'ok'], 200);
        },
    ));

    register_rest_route('join/v1', '/step', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $joinBlockLog->info('Recording CK Join form step', ['request' => $request]);

            try {
                $data = $request->get_json_params();
                $stepWebhookUrl = Settings::get('step_webhook_url');
                if ($stepWebhookUrl) {
                    $webhookData = apply_filters('ck_join_flow_pre_step_webhook_post', [
                        "headers" => [
                            'Content-Type' => 'application/json',
                        ],
                        "body" => json_encode($data)
                    ]);
                    $webhookResponse = wp_remote_post($stepWebhookUrl, $webhookData);
                    if ($webhookResponse instanceof \WP_Error) {
                        $error = $webhookResponse->get_error_message();
                        $joinBlockLog->error('Step webhook ' . $stepWebhookUrl . ' failed: ' . $error);
                    }
                }
            } catch (\Exception $e) {
                $joinBlockLog->error('CK Join form step error: ' . $e->getMessage());
            }

            return new WP_REST_Response(['status' => 'ok'], 200);
        },
    ));
});

// Happens after carbon_fields_register_fields
add_action('init', function () {
    $chargebee_site_name = Settings::get('CHARGEBEE_SITE_NAME');
    $chargebee_api_key = Settings::get('CHARGEBEE_API_KEY');
    Environment::configure($chargebee_site_name, $chargebee_api_key);
});
