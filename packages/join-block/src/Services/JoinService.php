<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Exceptions\JoinBlockException;
use CommonKnowledge\JoinBlock\Exceptions\SubscriptionExistsException;
use CommonKnowledge\JoinBlock\Settings;

class JoinService
{
    // According to error messages from Chargebee, dates should be sent as the format yyyy-MM-dd.
    // Meaning 2021-12-25 for Christmas Day, 25th of December 2021.
    private static function formatDob($day, $month, $year)
    {
        # Create date at 12pm UTC to avoid timezone issues changing the printed date
        $date = new \DateTime('1970-01-01T12:00:00Z');
        $date->setDate($year, $month, $day);
        return $date->format('Y-m-d');
    }

    public static function handleJoin($data)
    {
        $lockFile = null;
        try {
            $sessionToken = $data['sessionToken'] ?? null;
            $lockFile = self::lockSession($sessionToken);
            $chargebeeCustomer = self::tryHandleJoin($data);
            do_action('ck_join_flow_success', $data, $chargebeeCustomer);
        } catch (\Exception $e) {
            do_action('ck_join_flow_error', $data, $e);
            throw $e;
        } finally {
            self::unlockSession($lockFile);
        }
        return $chargebeeCustomer;
    }

    /**
     * This is a BLOCKING lock, which means threads will sleep
     * until they can get the lock. This forces sequential execution,
     * which means no race conditions.
     *
     * We still need to handle duplicate join requests, by
     * e.g. making sure the code doesn't create a subscription
     * if one already exists.
     * 
     * @return resource The file handle of the lock file
     */
    public static function lockSession($sessionToken)
    {
        global $joinBlockLog;

        if (!$sessionToken) {
            throw new \Exception("Unable to lock session: no token provided");
        }

        $joinBlockLog->info("Locking session $sessionToken");

        // Use WordPress get_temp_dir() as lock directory, this must be writable
        // otherwise many WordPress features do not work (e.g. file uploads)
        $lockFilepath = get_temp_dir() . '/' . $sessionToken;
        // Ignore fopen() error, as it is necessary for flock()
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $lockFile = fopen($lockFilepath, 'w');

        if (!$lockFile) {
            $joinBlockLog->error("Could not use lockfile for session $sessionToken");
            throw new \Exception("Unable to open lock file: " . esc_html($lockFilepath));
        }

        // Try to get exclusive access to this file. Will block (sleep) if
        // another process has locked the file, and wake when the other
        // process releases the lock.
        $lockSuccess = flock($lockFile, LOCK_EX);

        if (!$lockSuccess) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($lockFile);
            $joinBlockLog->error("Could not lock session $sessionToken");
            throw new \Exception("Unable to lock session: " . esc_html($sessionToken));
        }

        $joinBlockLog->info("Locked session $sessionToken");

        // Lock acquired
        return $lockFile;
    }

    /**
     * @param resource $lockFile The file handle of the lock file
     */
    public static function unlockSession($lockFile)
    {
        global $joinBlockLog;

        if (!$lockFile) {
            return;
        }

        // Release the file lock
        flock($lockFile, LOCK_UN);

        $fileInfo = stream_get_meta_data($lockFile);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($lockFile);
        // Remove the file. Other threads that are waiting for the lock
        // will not be affected, because the lock operates on the file
        // descriptor (which will still be valid), not on the file itself.
        // See: https://www.man7.org/linux/man-pages/man2/flock.2.html
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink($fileInfo['uri']);
        $joinBlockLog->info("Unlocked session {$fileInfo['uri']}");
    }

    /**
     * Attempts to send the user data to configured 3rd party services.
     * Returns the Chargebee customer, if Chargebee is enabled.
     */
    private static function tryHandleJoin($data)
    {
        global $joinBlockLog;

        $joinBlockLog->info('Beginning join process: ' . wp_json_encode($data));

        $data = apply_filters("ck_join_flow_pre_handle_join", $data);

        if (!empty($data["isUpdateFlow"])) {
            do_action("ck_join_flow_update_flow_ensure_customer_exists", $data['email']);
        }

        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

        if (!empty($data['phoneNumber'] && !empty($data['addressCountry']))) {
            $phoneNumberDetails = $phoneUtil->parse($data['phoneNumber'], $data['addressCountry']);
            $data['phoneNumber'] = $phoneUtil->format($phoneNumberDetails, \libphonenumber\PhoneNumberFormat::E164);
        }

        $billingAddress = [
            "firstName" => $data['firstName'] ?? '',
            "lastName" => $data['lastName'] ?? '',
            "line1" => $data['addressLine1'] ?? '',
            "line2" => $data['addressLine2'] ?? '',
            "city" => $data['addressCity'] ?? '',
            "state" => $data['addressCounty'] ?? '',
            "zip" => $data['addressPostcode'] ?? '',
            "country" => $data['addressCountry'] ?? ''
        ];

        $data['membershipPlan'] = Settings::getMembershipPlan($data['membership'] ?? '');
        if (!$data['membershipPlan']) {
            $error = 'Invalid membership plan: ' . $data['membership'];
            $joinBlockLog->error($error);
            throw new \Exception(esc_html($error));
        }

        $membershipAmount = (float) $data['membershipPlan']['amount'] ?? 0;
        if ($data['membershipPlan']['allow_custom_amount']) {
            $minimumAmount = $membershipAmount;
            $membershipAmount = (float) $data['customMembershipAmount'] ?? 0;
            if ($membershipAmount < $minimumAmount || $membershipAmount > 1000) {
                $error = "Invalid membership amount: $membershipAmount < $minimumAmount or > 1000";
                $joinBlockLog->error($error);
                throw new \Exception(esc_html($error));
            }
            $data['membershipPlan']['amount'] = $membershipAmount;
        }

        $customerResult = null;

        if (!empty($data['dobDay'])) {
            $data['dob'] = self::formatDob($data['dobDay'], $data['dobMonth'], $data['dobYear']);
        }

        $useChargebee = Settings::get('USE_CHARGEBEE');
        if ($useChargebee) {
            $customerResult = ChargeBeeService::upsertCustomer($data, $billingAddress);
        }

        $subscription = null;
        if (Settings::get('USE_GOCARDLESS')) {
            $subscription = self::handleGocardless($data);
            if ($subscription && $useChargebee) {
                $customerResult = ChargeBeeService::createDirectDebitChargebeeCustomer($data, $billingAddress, $subscription);
            }
        }
        $data['gocardlessSubscription'] = $subscription ? $subscription->id : null;
        $data['gocardlessMandate'] = $subscription ? $subscription->links->mandate : null;
        $data['gocardlessCustomer'] = $subscription ? $subscription->links->customer : null;

        if (Settings::get("USE_STRIPE")) {
            StripeService::initialise();
            $subscriptionInfo = StripeService::removeExistingSubscriptions($data["email"], $data["stripeCustomerId"] ?? null, $data["stripeSubscriptionId"] ?? null);
            if ($subscriptionInfo["amount"] !== $membershipAmount) {
                $email = $data['email'];
                $subAmount = round($subscriptionInfo["unitAmount"] / 100, 2);
                $joinBlockLog->error("Found mismatched subscription amounts for $email - claimed: $membershipAmount, found in stripe: $subAmount");
                throw new JoinBlockException("Invalid subscription amount", 8);
            }
            $data["stripeFirstSubscriptionDate"] = $subscriptionInfo["firstSubscription"];
            $data["stripeFirstPaymentDate"] = $subscriptionInfo["firstPayment"];
            $data["stripeLastPaymentDate"] = $subscriptionInfo["lastPayment"];
        }

        $subscriptionPlanId = '';
        if ($useChargebee && $customerResult) {
            $customer = $customerResult->customer();
            $hostedPageId = $data["cbHostedPageId"] ?? null;
            if ($hostedPageId) {
                $subscriptionPlanId = ChargebeeService::getChargebeeHostedPageSubscription($data, $hostedPageId);
            } else {
                $subscriptionPlanId = ChargebeeService::createChargebeeSubscription($data, $customer);
            }
        }

        if (Settings::get("CREATE_AUTH0_ACCOUNT")) {
            try {
                Auth0Service::createAuth0User($data, $subscriptionPlanId, $customer ? $customer->id : "Unknown");
            } catch (\Exception $exception) {
                $joinBlockLog->error('Auth0 user creation failed', ['exception' => $exception]);
                throw new JoinBlockException('Auth0 user creation failed', 7);
            }
        }

        if (Settings::get("USE_MAILCHIMP")) {
            $email = $data['email'];
            $joinBlockLog->info("Processing Mailchimp signup request for $email");
            try {
                MailchimpService::signup($data);
                $joinBlockLog->info("Completed Mailchimp signup request for $email");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Mailchimp error for email $email: " . $exception->getMessage());
                throw $exception;
            }
        }

        if (Settings::get("USE_ACTION_NETWORK")) {
            $email = $data['email'];
            $joinBlockLog->info("Processing Action Network signup request for $email");
            try {
                ActionNetworkService::signup($data);
                $joinBlockLog->info("Completed Action Network signup request for $email");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Action Network error for email $email: " . $exception->getMessage());
                throw $exception;
            }
        }

        if (Settings::get("USE_ZETKIN")) {
            $email = $data['email'];
            $joinBlockLog->info("Processing Zetkin signup request for $email");
            try {
                ZetkinService::signup($data);
                $joinBlockLog->info("Completed Zetkin signup request for $email");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Zetkin error for email $email: " . $exception->getMessage());
                throw $exception;
            }
        }

        $webhookUuid = $data['webhookUuid'] ?? '';
        if ($webhookUuid) {
            $webhookUrl = Settings::getWebhookUrl($webhookUuid);
            if ($webhookUrl) {
                self::sendDataToWebhook($data, $webhookUrl);
            }
        }

        return $customerResult ? $customerResult->customer() : null;
    }

    public static function sendDataToWebhook($data, $webhookUrl)
    {
        global $joinBlockLog;

        $excludedFields = ["ddAccountNumber", "ddSortCode", "paymentToken"];

        foreach ($excludedFields as $excludedField) {
            unset($data[$excludedField]);
        }

        $data = self::addPostcodesIOData($data);
        // Set this as some users reuse the same tab for multiple form submissions,
        // which prevents sessionToken being unique for each user journey
        $data["userSessionToken"] = $data["email"] . ':' . $data["sessionToken"];
        $webhookData = apply_filters('ck_join_flow_pre_webhook_post', [
            "headers" => [
                'Content-Type' => 'application/json',
            ],
            "body" => wp_json_encode($data)
        ]);
        $webhookResponse = wp_remote_post($webhookUrl, $webhookData);
        if ($webhookResponse instanceof \WP_Error) {
            $error = $webhookResponse->get_error_message();
            $joinBlockLog->error('Webhook ' . $webhookUrl . ' failed: ' . $error);
            throw new \Exception(esc_html($error));
        }
    }

    public static function ensureGoCardlessSubscriptionsCreated()
    {
        global $wpdb;
        global $joinBlockLog;

        $joinBlockLog->info("Running ensureSubscriptionsCreated");

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}options WHERE option_name LIKE %s",
                'JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_%'
            )
        );
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
    }

    public static function toggleMemberLapsed($email, $lapsed = true, $paymentDate = null)
    {
        global $joinBlockLog;

        $action = $lapsed ? "Marking" : "Unmarking";
        $done = $lapsed ? "Marked" : "Unmarked";
        $joinBlockLog->info("$action member $email as lapsed");

        if (Settings::get("USE_ACTION_NETWORK")) {
            $joinBlockLog->info("$action member $email as lapsed in Action Network");
            try {
                if ($lapsed) {
                    ActionNetworkService::addTag($email, Settings::get("LAPSED_TAG"));
                } else {
                    ActionNetworkService::removeTag($email, Settings::get("LAPSED_TAG"));
                    ActionNetworkService::updateCustomFields($email, [
                        "Latest Stripe Payment Date" => $paymentDate ?? date('Y-m-d'),
                    ]);
                }
                $joinBlockLog->info("$done member $email as lapsed in Action Network");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Action Network error for email $email: " . $exception->getMessage());
                throw $exception;
            }
        }
    }

    private static function handleGocardless($data)
    {
        global $joinBlockLog;

        $subscription = null;

        if ($data['paymentMethod'] === 'directDebit') {
            $joinBlockLog->info('Creating Direct Debit subscription via GoCardless: ' . wp_json_encode($data));

            /*
                Handle different GoCardless errors.

                For a complete list of errors see https://developer.gocardless.com/api-reference/#errors-error-types

                By far the most common are validation errors. These are often the result of user error
                or problems with their provided account.

                The other exceptions caught here are the result of misuse of the GoCardless API.
                They should rarely occur if at all.
            */
            try {
                $data = apply_filters('ck_join_flow_pre_gocardless_subscription_create', $data);
                $subscription = GocardlessService::createCustomerSubscription($data);
            } catch (\GoCardlessPro\Core\Exception\ValidationFailedException $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit subscription creation failed as account details were invalid: ' .
                        $exception->getMessage()
                );

                throw new JoinBlockException(
                    'GoCardless Direct Debit subscription creation failed due to validation',
                    3,
                    // Ignore sanitization error as this could break error handling.
                    // The data comes directly from the GoCardless API, so can be trusted
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    $exception->getErrors()
                );
            } catch (\GoCardlessPro\Core\Exception\InvalidApiUsageException $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit subscription creation failed due to invalid usage of the API ' .
                        $exception->getMessage() . " " . $exception->getTraceAsString()
                );

                throw new JoinBlockException(
                    'GoCardless Direct Debit subscription creation failed due to invalid API usage',
                    5
                );
            } catch (\GoCardlessPro\Core\Exception\InvalidStateException $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit subscription creation failed due to invalid state ' . $exception->getMessage()
                );

                throw new JoinBlockException(
                    'GoCardless Direct Debit subscription creation failed due to invalid state - ' .
                        'this usually means a request in flight is in a unclear state',
                    6
                );
            } catch (SubscriptionExistsException $e) {
                throw $e;
            } catch (\Exception $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit subscription creation failed with unknown exception: ' .
                        get_class($exception),
                    ['exception' => $exception]
                );
                throw new \Exception('GoCardless Direct Debit subscription creation failed', esc_html($exception->getCode()));
            }

            $joinBlockLog->info('Direct Debit subscription via GoCardless successful');
        }

        return $subscription;
    }

    private static function addPostcodesIOData($data)
    {
        global $joinBlockLog;

        $postcode = $data['addressPostcode'] ?? '';
        if (!$postcode) {
            return $data;
        }
        // Remove whitespace
        $postcode = preg_replace('#\s+#', '', $postcode);
        $response = wp_remote_get("https://api.postcodes.io/postcodes/$postcode");
        $body = wp_remote_retrieve_body($response);
        $error = null;
        $postcodeData = null;
        try {
            $postcodeData = json_decode($body, true);
        } catch (\Exception $e) {
            $error = $e;
        }

        if (empty($postcodeData['result'])) {
            $message = 'Error getting PostcodesIO data for postcode ' . $postcode . '. Response: ' . $response;
            $errMessage = $error ? $error->getMessage() : 'Unknown error';
            $message .= '. Error: ' . $errMessage;
            $joinBlockLog->error($message);
        }

        $data['postcodesIOData'] = $postcodeData['result'] ?? null;
        return $data;
    }
}
