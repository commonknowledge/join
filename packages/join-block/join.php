<?php

/**
 * Plugin Name:     Common Knowledge Join Flow
 * Description:     Common Knowledge join flow plugin.
 * Version:         1.3.1
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     common-knowledge-join-flow
 * License: GPLv2 or later
 */

if (! defined('ABSPATH')) exit; // Exit if accessed directly

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use ChargeBee\ChargeBee\Environment;
use CommonKnowledge\JoinBlock\Services\JoinService;
use CommonKnowledge\JoinBlock\Blocks;
use CommonKnowledge\JoinBlock\Exceptions\SubscriptionExistsException;
use CommonKnowledge\JoinBlock\Commands\ExportStripeSubscriptions;
use CommonKnowledge\JoinBlock\Logging;
use CommonKnowledge\JoinBlock\Services\ActionNetworkService;
use CommonKnowledge\JoinBlock\Services\GocardlessService;
use CommonKnowledge\JoinBlock\Services\StripeService;
use CommonKnowledge\JoinBlock\Services\MailchimpService;
use CommonKnowledge\JoinBlock\Settings;

Logging::init();
global $joinBlockLog;

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

    $sentryDsn = Settings::get("SENTRY_DSN");
    if ($sentryDsn) {
        \Sentry\init([
            'dsn' => $sentryDsn
        ]);
        Logging::enableSentry();
    }

    $googleCloudProjectId = Settings::get("GOOGLE_CLOUD_PROJECT_ID");
    $googleCloudKeyFileContents = trim(Settings::get("GOOGLE_CLOUD_KEY_FILE_CONTENTS"));
    if ($googleCloudProjectId && $googleCloudKeyFileContents) {
        Logging::enableGoogleCloud($googleCloudProjectId, $googleCloudKeyFileContents);
    }
});

// Ignore sanitization error as this could break provided environment variables
// If the environment is compromised, there are bigger problems!
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$teamsWebhook = $_ENV['MICROSOFT_TEAMS_INCOMING_WEBHOOK'] ?? null;
if ($teamsWebhook) {
    $joinBlockLog->pushHandler(
        new \CMDISP\MonologMicrosoftTeams\TeamsLogHandler(
            $teamsWebhook,
            \Monolog\Level::Error
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
            } catch (\CommonKnowledge\JoinBlock\Exceptions\JoinBlockException $exception) {
                if ($exception->getCode() === 7) {
                    $joinBlockLog->error(
                        'Join process failed at Auth0 user creation, but customer created in Chargebee.',
                        ['error' => $exception]
                    );
                } else {
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
                            'fields' => $exception->getFields()
                        ]
                    );
                }
            } catch (\Exception $error) {
                $joinBlockLog->error('Join process failed', ['error' => $error]);
                return new WP_Error(
                    'join_failed',
                    'Join process failed',
                    ['status' => 500, 'error_code' => $error->getCode()]
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
                $response = wp_remote_get($url);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
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

                $response = wp_remote_get($url);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true) ?? [];

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
    // Also return the Billing Request ID, as it is saved in the session data
    // and used later to get the new Customer ID when the user is redirected
    // back to the Join Form
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
            update_option("JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_{$billingRequest['id']}", wp_json_encode($data));

            return ["href" => $authLink, "gcBillingRequestId" => $billingRequest["id"]];
        }
    ));

    register_rest_route('join/v1', '/stripe/create-subscription', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $email = "";
            try {
                $body = $request->get_body();
                $data = json_decode($body, true);

                $email = $data['email'];

                $joinBlockLog->info("Received /stripe/create-subscription request: " . $body);

                $selectedPlanLabel = $data['membership'];

                $joinBlockLog->info('Attempting to find a matching plan', ['selectedPlanLabel' => $selectedPlanLabel]);

                $plan = Settings::getMembershipPlan($selectedPlanLabel);

                if (!$plan) {
                    throw new \Exception('Selected plan is not in the list of plans, this is unexpected');
                } else {
                    $joinBlockLog->info('Found a matching plan in the list of plans', $plan);
                }

                $joinBlockLog->info('Processing Stripe subscription creation request');

                StripeService::initialise();
                [$customer, $newCustomer] = StripeService::upsertCustomer($email);

                $subscription = StripeService::createSubscription($customer, $plan, $data["customMembershipAmount"] ?? null);

                return $subscription;
            } catch (\Exception $e) {
                $joinBlockLog->error(
                    'Failed to create Stripe subscription for user ' . $email . ": " . $e->getMessage(),
                    ['error' => $e]
                );
                throw $e;
            }
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

            $selectedPlanLabel = $data['membership'];

            $joinBlockLog->info('Attempting to find a matching plan', ['selectedPlanLabel' => $selectedPlanLabel]);

            $plan = Settings::getMembershipPlan($selectedPlanLabel);

            if (!$plan) {
                throw new \Exception('Selected plan is not in the list of plans, this is unexpected');
            } else {
                $joinBlockLog->info('Found a matching plan in the list of plans', $plan);
            }

            $joinBlockLog->info('Processing Stripe subscription creation request');

            $email = $data['email'];

            StripeService::initialise();
            [$customer, $newCustomer] = StripeService::upsertCustomer($email);

            $subscription = StripeService::createSubscription($customer, $plan);

            $confirmedPaymentIntent = StripeService::confirmSubscriptionPaymentIntent($subscription, $data['confirmationTokenId']);

            StripeService::updateCustomerDefaultPaymentMethod($customer->id, $subscription->latest_invoice->payment_intent->payment_method);

            return [
                "status" => $confirmedPaymentIntent->status,
                "new_customer" => $newCustomer,
                "stripe_customer" => $customer->toArray(),
                "stripe_subscription" => $subscription->toArray()
            ];
        }
    ));

    register_rest_route('join/v1', '/mailchimp', array(
        'methods' => 'POST',
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $data = json_decode($request->get_body(), true);

            if (empty($data['email'])) {
                $joinBlockLog->error('Email missing in Mailchimp join request');
                return new WP_REST_Response(['status' => 'invalid request'], 400);
            }

            $email = $data['email'];
            $joinBlockLog->info("Processing Mailchimp signup request for $email");

            try {
                MailchimpService::signup($email);
            } catch (\Exception $e) {
                $joinBlockLog->error("Mailchimp error for email $email: " . $e->getMessage());
                return new WP_REST_Response(['status' => 'internal server error'], 500);
            }

            $joinBlockLog->info("Completed Mailchimp signup request for $email");
            return new WP_REST_Response(['status' => 'ok'], 200);
        }
    ));

    register_rest_route('join/v1', '/gocardless/webhook', array(
        'methods' => ['GET', 'POST'],
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;
            $joinBlockLog->info("Received GoCardless webhook: " . $request->get_body());
            JoinService::ensureGoCardlessSubscriptionsCreated();
        }
    ));

    register_rest_route('join/v1', '/stripe/webhook', array(
        'methods' => ['GET', 'POST'],
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;
            $joinBlockLog->info("Received Stripe webhook: " . $request->get_body());
            $event = json_decode($request->get_body(), true);
            StripeService::initialise();
            StripeService::handleWebhook($event);
        }
    ));

    register_rest_route('join/v1', '/stripe/download-subscriptions', array(
        'methods' => ['GET'],
        'permission_callback' => function ($req) {
            return current_user_can('manage_options');
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;
            $joinBlockLog->info("Downloading Stripe subscriptions");

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="stripe_subscriptions.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            ExportStripeSubscriptions::run();

            // Prevent WP from sending a JSON response
            exit;
        }
    ));
});

// Happens after carbon_fields_register_fields
add_action('init', function () {
    $chargebee_site_name = Settings::get('CHARGEBEE_SITE_NAME');
    $chargebee_api_key = Settings::get('CHARGEBEE_API_KEY');
    Environment::configure($chargebee_site_name, $chargebee_api_key);
});

add_action('ck_join_block_gocardless_cron_hook', function () {
    global $wpdb;
    global $joinBlockLog;

    $joinBlockLog->info("Running ensureSubscriptionsCreated");

    $sql = "SELECT * FROM {$wpdb->prefix}options WHERE option_name LIKE 'JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_%'";
    // Ignore DB lint error as this is a safe query
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
    $results = $wpdb->get_results($sql);
    foreach ($results as $result) {
        $joinBlockLog->info("ensureSubscriptionsCreated: processing {$result->option_name}: {$result->option_value}");
        try {
            $data = json_decode($result->option_value, true);
            $createdAt = $data['createdAt'] ?? 0;

            $customer = GocardlessService::getCustomerIdByCompletedBillingRequest($data['gcBillingRequestId']);
            if (!$customer) {
                $joinBlockLog->error("ensureSubscriptionsCreated: could not process {$result->option_name}: user did not set up mandate.");
                // Try for one day
                $day = 24 * 60 * 60;

                $joinBlockLog->info("ensureSubscriptionsCreated: checking if should delete {$result->option_name}, created at {$createdAt}");

                if ((time() - $createdAt) > $day) {
                    $joinBlockLog->info("ensureSubscriptionsCreated: deleting unprocessable {$result->option_name}");
                    delete_option($result->option_name);
                } else {
                    $joinBlockLog->info("ensureSubscriptionsCreated: will retry {$result->option_name}");
                }
                continue;
            }

            JoinService::handleJoin($data);
            delete_option($result->option_name);
            $joinBlockLog->info("ensureSubscriptionsCreated: success, deleting option {$result->option_name}");
        } catch (\Exception $e) {
            $joinBlockLog->error("ensureSubscriptionsCreated: could not process {$result->option_value}: {$e->getMessage()}");
        }
    }
});

if (!wp_next_scheduled('ck_join_block_gocardless_cron_hook')) {
    wp_schedule_event(time(), 'hourly', 'ck_join_block_gocardless_cron_hook');
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('join update_an_from_stripe', function () {
        StripeService::initialise();
        $customers = StripeService::getCustomers();
        foreach ($customers as $c) {
            if ($c->email) {
                $dates = StripeService::getSubscriptionHistory($c->id);
                ActionNetworkService::updateCustomFields($c->email, [
                    "First Stripe Subscription Date" => $dates['firstSubscription'],
                    "First Stripe Payment Date" => $dates['firstPayment'],
                    "Latest Stripe Payment Date" => $dates['lastPayment'],
                ]);
            }
        }
    });
}
