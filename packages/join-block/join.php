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
use CommonKnowledge\JoinBlock\Exceptions\SubscriptionExistsException;
use CommonKnowledge\JoinBlock\Services\GocardlessService;
use CommonKnowledge\JoinBlock\Services\StripeService;
use CommonKnowledge\JoinBlock\Settings;
use Monolog\Logger;
use Monolog\Processor\WebProcessor;
use GuzzleHttp\Exception\ClientException;
use Monolog\Handler\RotatingFileHandler;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Exception\ApiErrorException;

global $joinBlockLog;
global $joinBlockLogLocation;
$joinBlockLog = new Logger('join-block');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);

try {
    $dotenv->load();
} catch (\Throwable $e) {
    // Ignore missing .env as all settings are also available in the
    // plugin settings page
    $joinBlockLog->debug("Could not load environment variables from .env file: " . $e->getMessage());
}

$joinBlockLogLocation = $_ENV['JOIN_BLOCK_LOG_LOCATION'] ?? __DIR__ . '/logs';
$joinBlockLog->pushHandler(new RotatingFileHandler($joinBlockLogLocation . '/debug.log', 10, Logger::INFO));
$joinBlockLog->pushProcessor(new WebProcessor());

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
            } catch (SubscriptionExistsException $error) {
                $joinBlockLog->info(
                    'Join process failed as subscription already exists',
                    ['error' => $error]
                );
            } catch (ClientException $error) {
                $joinBlockLog->error(
                    'Join process failed at Auth0 user creation, but customer created in Chargebee.',
                    ['error' => $error]
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
            } catch (\Exception $error) {
                $joinBlockLog->error('Join process failed', ['error' => $error]);
                return new WP_Error(
                    'join_failed',
                    'Join process failed',
                    ['status' => 500, 'error_code' => $error->getCode(), 'error_message' => $error->getMessage()]
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
                    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
                    if (!empty($data['phoneNumber'] && !empty($data['addressCountry']))) {
                        $phoneNumberDetails = $phoneUtil->parse($data['phoneNumber'], $data['addressCountry']);
                        $data['phoneNumber'] = $phoneUtil->format($phoneNumberDetails, \libphonenumber\PhoneNumberFormat::E164);
                    }
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
                    $url .= "?api-key=$apiKey&all=true";
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

    // Get the link to the GoCardless sign up flow, and redirect the user to it.
    // Also set the Billing Request ID as a cookie, as it is used later to get
    // the new Customer ID when the user is redirected back to the Join Form
    // (see $_COOKIE["GC_BILLING_REQUEST_ID"] in Blocks.php)
    register_rest_route('join/v1', '/gocardless/auth', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;
            $data = json_decode($request->get_body(), true);
            $redirectUrl = $data['redirectUrl'];
            $successUrl = add_query_arg('gocardless_success', 'true', $redirectUrl);

            $billingRequest = GocardlessService::getBillingRequestIdAndUrl($successUrl, $redirectUrl);
            $authLink = $billingRequest['url'];
            $data['gcBillingRequestId'] = $billingRequest["id"];

            $joinBlockLog->info("Got billing request ID {$billingRequest['id']} for {$data['email']}");

            // Save this data in the database so if the user doesn't set up the subscription it can be
            // done when we receive a GoCardless webhook
            $data['createdAt'] = time();
            // Make stage = "confirm" to match the normal flow
            // (the user is redirected back from GoCardless and submits their data to the /join endpoint)
            $data['stage'] = "confirm";
            update_option("JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_{$billingRequest['id']}", json_encode($data));

            return ["href" => $authLink, "gcBillingRequestId" => $billingRequest["id"]];
        }
    ));

    register_rest_route('join/v1', '/stripe/create-confirm-subscription', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $data = json_decode($request->get_body(), true);

            $selectedPlan = $data['selectedPlan'];

            $plans = Settings::get('MEMBERSHIP_PLANS');

            $joinBlockLog->info('Attempting to find a matching plan in the list of plans', $selectedPlan);

            $planExists = array_filter($plans, function($plan) use ($selectedPlan) {
                return $plan['frequency'] === $selectedPlan['frequency'] && $plan['amount'] === $selectedPlan['amount'] && $plan['currency'] === $selectedPlan['currency'];
            });

            if (!$planExists) {
                throw new \Exception('Selected plan is not in the list of plans, this is unexpected');
            } else {
                $joinBlockLog->info('Found a matching plan in the list of plans', $planExists);
            }

            $joinBlockLog->info('Processing Stripe subscription creation request');

            $email = $data['email'];

            [$customer, $newCustomer] = StripeService::upsertCustomer($email);

            $subscription = StripeService::createSubscription($customer);

            $confirmedPaymentIntent = StripeService::confirmSubscription($subscription, $data['confirmationTokenId']);

            $status = $confirmedPaymentIntent->status;

            $paymentMethodId = $subscription->latest_invoice->payment_intent->payment_method;

            StripeService::updateCustomerDefaultPaymentMethod($customer->id, $paymentMethodId);

            return [
                "status" => $status,
                "new_customer" => $newCustomer,
                "stripe_customer" => $customer->toArray(),
                "stripe_subscription" => $subscription->toArray()
            ];
        }
    ));
});

// Happens after carbon_fields_register_fields
add_action('init', function () {
    $chargebee_site_name = Settings::get('CHARGEBEE_SITE_NAME');
    $chargebee_api_key = Settings::get('CHARGEBEE_API_KEY');
    Environment::configure($chargebee_site_name, $chargebee_api_key);
});
