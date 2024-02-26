<?php

/**
 * Plugin Name:     Common Knowledge Join Plugin
 * Description:     Common Knowledge join flow plugin.
 * Version:         1.0.6
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     ck
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use ChargeBee\ChargeBee\Environment;
use CommonKnowledge\JoinBlock\Services\JoinService;
use CommonKnowledge\JoinBlock\Blocks;
use CommonKnowledge\JoinBlock\Settings;
use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\WebProcessor;
use GuzzleHttp\Exception\ClientException;

global $joinBlockLog;
global $joinBlockLogLocation;
$joinBlockLogLocation = __DIR__ . '/logs/debug.log';
$joinBlockLog = new Logger('join-block');
$joinBlockLog->pushHandler(new ErrorLogHandler());
$joinBlockLog->pushHandler(new StreamHandler($joinBlockLogLocation, Logger::INFO));
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
                    JoinService::sendDataToWebhook($data, $stepWebhookUrl);
                }
            } catch (\Exception $e) {
                $joinBlockLog->error('CK Join form step error: ' . $e->getMessage());
            }

            return new WP_REST_Response(['status' => 'ok'], 200);
        },
    ));

    register_rest_route('join/v1', '/postcode', array(
        'methods' => 'GET',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $postcode = $request->get_query_params()['postcode'] ?? '';
            # remove whitespace
            $postcode = preg_replace('#\s+#', '', $postcode);
            if (!$postcode) {
                return new WP_REST_Response(['status' => 'invalid request'], 400);
            }

            try {
                $provider = Settings::get('POSTCODE_ADDRESS_PROVIDER');
                if ($provider === Settings::GET_ADDRESS_IO) {
                    $url = "https://api.getaddress.io/autocomplete/$postcode";
                    $apiKey = Settings::get(Settings::GET_ADDRESS_IO . '_api_key');
                    $url .= "?api-key=$apiKey";
                } else {
                    $url = "https://api.ideal-postcodes.co.uk/v1/postcodes/$postcode";
                    $apiKey = Settings::get(Settings::IDEAL_POSTCODES . '_api_key');
                    $url .= "?api_key=$apiKey";
                }
                if (!$apiKey) {
                    throw new \Exception('Error: missing API key for ' . $provider);
                }
                $response = @file_get_contents($url);
                $data = json_decode($response, true);
                if ($provider === Settings::GET_ADDRESS_IO) {
                    $addresses = $data['suggestions'] ?? [];
                } else {
                    $addresses = $data['result'] ?? [];
                }
                return new WP_REST_Response(['status' => 'ok', 'data' => $addresses], 200);
            } catch (\Exception $e) {
                $joinBlockLog->error('CK Join form postcode address search error: ' . $e->getMessage());
            }

            return new WP_REST_Response(['status' => 'internal server error'], 500);
        },
    ));

    register_rest_route('join/v1', '/address', array(
        'methods' => 'GET',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $addressId = $request->get_query_params()['id'] ?? '';
            if (!$addressId) {
                return new WP_REST_Response(['status' => 'invalid request'], 400);
            }

            try {
                $url = "https://api.getaddress.io/get/$addressId";
                $apiKey = Settings::get(Settings::GET_ADDRESS_IO . '_api_key');
                $url .= "?api-key=$apiKey";
                if (!$apiKey) {
                    throw new \Exception('Error: missing API key for getAddress.io');
                }

                $response = @file_get_contents($url);
                $data = json_decode($response, true) ?? [];

                // Match ideal postcodes output
                $address = [
                    'line_1' => $data['line_1'],
                    'line_2' => $data['line_2'],
                    'post_town' => $data['town_or_city'],
                    'county' => $data['county'],
                    'postcode' => $data['postcode'] ?? ''
                ];
                return new WP_REST_Response(['status' => 'ok', 'data' => $address], 200);
            } catch (\Exception $e) {
                $joinBlockLog->error('CK Join form postcode address search error: ' . $e->getMessage());
            }

            return new WP_REST_Response(['status' => 'internal server error'], 500);
        },
    ));
});

// Happens after carbon_fields_register_fields
add_action('init', function () {
    $chargebee_site_name = Settings::get('CHARGEBEE_SITE_NAME');
    $chargebee_api_key = Settings::get('CHARGEBEE_API_KEY');
    Environment::configure($chargebee_site_name, $chargebee_api_key);
});
