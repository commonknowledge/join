<?php

/**
 * Plugin Name:     Common Knowledge Join Plugin
 * Description:     Common Knowledge join flow plugin.
 * Version:         1.0.6
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     uk-greens
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

add_action('after_setup_theme', function () {
    \Carbon_Fields\Carbon_Fields::boot();
});

use ChargeBee\ChargeBee\Environment;
use CommonKnowledge\JoinBlock\Services\JoinService;
use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Processor\WebProcessor;

use GuzzleHttp\Exception\ClientException;

$joinBlockLog = new Logger('join-block');
$joinBlockLog->pushHandler(new ErrorLogHandler());
$joinBlockLog->pushProcessor(new WebProcessor());

if ($_ENV['MICROSOFT_TEAMS_INCOMING_WEBHOOK'] && $_ENV['MICROSOFT_TEAMS_INCOMING_WEBHOOK'] !== '') {
    $joinBlockLog->pushHandler(
        new \CMDISP\MonologMicrosoftTeams\TeamsLogHandler(
            $_ENV['MICROSOFT_TEAMS_INCOMING_WEBHOOK'],
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

            $joinBlockLog->info('Join process started', ['request' => $request]);

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
});


add_action('init', function () {
    Environment::configure($_ENV['CHARGEBEE_SITE_NAME'], $_ENV['CHARGEBEE_API_KEY']);
});
