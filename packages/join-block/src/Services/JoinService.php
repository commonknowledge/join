<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Exceptions\JoinBlockException;
use CommonKnowledge\JoinBlock\Exceptions\SubscriptionExistsException;
use CommonKnowledge\JoinBlock\Settings;

class JoinService
{
    // Membership amounts are money in major units, held as floats. Compare with
    // a tolerance rather than == / === so that a value which has been through
    // Stripe's minor-unit integers and back cannot fail on representation
    // alone. Half a penny is far below the smallest real price difference.
    public const AMOUNT_EPSILON = 0.005;

    // Join data whose CRM push failed after the payment succeeded. The member
    // has paid and is a member; only the CRM write is outstanding, so this is
    // kept for retry rather than surfaced as a failure to them.
    public const CRM_RETRY_OPTION_PREFIX = 'JOIN_FORM_PENDING_CRM_';

    // According to error messages from Chargebee, dates should be sent as the format yyyy-MM-dd.
    // Meaning 2021-12-25 for Christmas Day, 25th of December 2021.
    private static function formatDob($day, $month, $year)
    {
        # Create date at 12pm UTC to avoid timezone issues changing the printed date
        $date = new \DateTime('1970-01-01T12:00:00Z');
        $date->setDate($year, $month, $day);
        return $date->format('Y-m-d');
    }

    /**
     * Option name for a pending CRM push. Keyed on the Stripe subscription
     * where there is one, falling back to a hash of the email so a GoCardless
     * or Chargebee join still gets exactly one record per member.
     */
    private static function crmRetryKey($data)
    {
        $subscriptionId = $data['stripeSubscriptionId'] ?? '';
        if ($subscriptionId) {
            return self::CRM_RETRY_OPTION_PREFIX . $subscriptionId;
        }
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        return self::CRM_RETRY_OPTION_PREFIX . 'email_' . sha1($email);
    }

    /**
     * Durably record that a CRM push is still outstanding for a member whose
     * payment has already succeeded.
     *
     * This record is the only copy of what the member submitted — the join
     * form data is not otherwise persisted — so losing it means their details
     * can only be recovered by asking them again.
     */
    public static function queueCrmRetry($data, $service, $reason)
    {
        global $joinBlockLog;

        $optionName = self::crmRetryKey($data);

        $attempts = 1;
        $existing = get_option($optionName);
        if ($existing) {
            $decoded = json_decode($existing, true);
            $attempts = (int) ($decoded['attempts'] ?? 0) + 1;
        }

        update_option($optionName, wp_json_encode([
            'data' => $data,
            'service' => $service,
            'reason' => $reason,
            'attempts' => $attempts,
            'lastAttemptAt' => time(),
        ]));

        $email = $data['email'] ?? 'unknown';
        $joinBlockLog->error("Queued $service CRM retry for $email (attempt $attempts): $reason");
    }

    /**
     * Refresh the recovery snapshot with the payload we were actually handed.
     *
     * join.php writes this at create-subscription time, before the member has
     * finished the form, so the stored copy can be missing fields that the
     * final /join request carries. Any replay should use the freshest version.
     */
    private static function saveRecoverySnapshot($data)
    {
        $subscriptionId = $data['stripeSubscriptionId'] ?? '';
        if (!$subscriptionId) {
            return;
        }

        $optionName = "JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_{$subscriptionId}";

        $existing = get_option($optionName);
        if ($existing) {
            // Keep the original createdAt. ensureStripeSubscriptionsCreated()
            // uses it to decide when to stop retrying, so refreshing it on
            // every attempt would make the record immortal.
            $decoded = json_decode($existing, true);
            if (!empty($decoded['createdAt'])) {
                $data['createdAt'] = $decoded['createdAt'];
            }
        }
        if (empty($data['createdAt'])) {
            $data['createdAt'] = time();
        }

        update_option($optionName, wp_json_encode($data));
    }

    public static function handleJoin($data)
    {
        global $joinBlockLog;

        $lockFile = null;
        try {
            $lockKey = $data['email'] ?? null;
            if (!$lockKey) {
                $lockKey = $data['sessionToken'] ?? null;
                if ($lockKey) {
                    $joinBlockLog->warning("handleJoin called without email; falling back to sessionToken for lock key");
                }
            }
            $lockFile = self::acquireLock($lockKey);
            self::saveRecoverySnapshot($data);
            $chargebeeCustomer = self::tryHandleJoin($data);
            // The join is complete, so it no longer needs to be picked up by
            // ensureStripeSubscriptionsCreated() (the webhook/cron recovery path)
            if (!empty($data['stripeSubscriptionId'])) {
                delete_option("JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_{$data['stripeSubscriptionId']}");
            }
            do_action('ck_join_flow_success', $data, $chargebeeCustomer);
        } catch (\Exception $e) {
            do_action('ck_join_flow_error', $data, $e);
            throw $e;
        } finally {
            self::releaseLock($lockFile);
        }
        return $chargebeeCustomer;
    }

    /**
     * Acquire a BLOCKING exclusive lock keyed by an opaque string (typically
     * an email address). Threads sleep until they can get the lock, forcing
     * sequential execution and avoiding race conditions on per-person CRM
     * mutations across the /join endpoint and webhook handlers.
     *
     * We still need to handle duplicate join requests, by
     * e.g. making sure the code doesn't create a subscription
     * if one already exists.
     *
     * NOTE: flock() is per-host. If this app is ever deployed across multiple
     * PHP-FPM hosts, this lock no longer protects — a distributed lock
     * (Redis, DB row lock) would be needed.
     *
     * @return resource The file handle of the lock file
     */
    public static function acquireLock($key)
    {
        global $joinBlockLog;

        if (!$key) {
            throw new \Exception("Unable to acquire lock: no key provided");
        }

        // Normalize so emails with differing case / whitespace collide on the
        // same lock, and so the resulting filename is safe for any tmp dir.
        $normalizedKey = sha1(strtolower(trim((string) $key)));

        $joinBlockLog->info("Locking key $normalizedKey");

        // Use WordPress get_temp_dir() as lock directory, this must be writable
        // otherwise many WordPress features do not work (e.g. file uploads)
        $lockFilepath = get_temp_dir() . '/join-lock-' . $normalizedKey;
        // Ignore fopen() error, as it is necessary for flock()
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $lockFile = fopen($lockFilepath, 'w');

        if (!$lockFile) {
            $joinBlockLog->error("Could not use lockfile for key $normalizedKey");
            throw new \Exception("Unable to open lock file: " . esc_html($lockFilepath));
        }

        // Try to get exclusive access to this file. Will block (sleep) if
        // another process has locked the file, and wake when the other
        // process releases the lock.
        $lockSuccess = flock($lockFile, LOCK_EX);

        if (!$lockSuccess) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($lockFile);
            $joinBlockLog->error("Could not lock key $normalizedKey");
            throw new \Exception("Unable to acquire lock: " . esc_html($normalizedKey));
        }

        $joinBlockLog->info("Locked key $normalizedKey");

        // Lock acquired
        return $lockFile;
    }

    /**
     * @param resource $lockFile The file handle of the lock file
     */
    public static function releaseLock($lockFile)
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
        $joinBlockLog->info("Unlocked {$fileInfo['uri']}");
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

        if (!empty($data['phoneNumber'])) {
            try {
                $phoneNumberDetails = $phoneUtil->parse($data['phoneNumber'], null);
                $data['phoneNumber'] = $phoneUtil->format($phoneNumberDetails, \libphonenumber\PhoneNumberFormat::E164);
            } catch (\libphonenumber\NumberParseException $e) {
                // Frontend should always send E.164. Leave as-is if parsing fails.
            }
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
        $data['membershipPlan']['remove_tags'] = Settings::computeTagsToRemove($data['membershipPlan']);

        $membershipAmount = (float) ($data['membershipPlan']['amount'] ?? 0);
        if ($data['membershipPlan']['allow_custom_amount']) {
            $minimumAmount = $membershipAmount;
            $membershipAmount = (float) ($data['customMembershipAmount'] ?? 0);
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

        $isOneOffSupporterDonation = !empty($data["donationSupporterMode"]) && empty($data["recurDonation"]);

        if (Settings::get("USE_STRIPE") && !$isOneOffSupporterDonation) {
            StripeService::initialise();
            $email = $data['email'];
            $subscriptionId = $data["stripeSubscriptionId"] ?? null;

            // Verify the amount BEFORE cancelling anything. Cancelling first
            // means a failed verification leaves the member with their previous
            // subscription cancelled and no new membership to show for it.
            try {
                $actualAmount = StripeService::getSubscriptionAmount($subscriptionId);
            } catch (\Exception $e) {
                $joinBlockLog->error(
                    "Could not read subscription amount for $email from Stripe: " . $e->getMessage()
                );
                throw new JoinBlockException("Could not verify subscription amount", 9);
            }

            // Distinct from a mismatch: we do not know the amount, so we cannot
            // say it is wrong. Treated as retryable rather than telling a member
            // who has already paid that their amount is invalid.
            if ($actualAmount === null) {
                $subscriptionLabel = $subscriptionId ?: 'none';
                $joinBlockLog->error(
                    "Could not determine subscription amount for $email (subscription: $subscriptionLabel)"
                );
                throw new JoinBlockException("Could not verify subscription amount", 9);
            }

            if (abs($actualAmount - $membershipAmount) > self::AMOUNT_EPSILON) {
                $joinBlockLog->error(
                    "Found mismatched subscription amounts for $email - claimed: $membershipAmount,"
                    . " found in stripe: $actualAmount"
                );
                throw new JoinBlockException("Invalid subscription amount", 8);
            }

            $subscriptionInfo = StripeService::cancelPreviousSubscriptions(
                $email,
                $data["stripeCustomerId"] ?? null,
                $subscriptionId
            );
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
                // A Mailchimp failure should not block a successful join.
                // The member record can be retro-added to Mailchimp once the underlying issue is resolved.
                $joinBlockLog->error("Mailchimp error for email $email: " . $exception->getMessage());
            }
        }

        if (Settings::get("USE_ACTION_NETWORK")) {
            $email = $data['email'];
            $joinBlockLog->info("Processing Action Network signup request for $email");
            try {
                ActionNetworkService::signup($data);
                $joinBlockLog->info("Completed Action Network signup request for $email");
            } catch (\Exception $exception) {
                // The payment has already succeeded, so the member has joined
                // and there is nothing they can do about a CRM failure. Failing
                // the request would show them an error they cannot act on, and
                // invite a resubmit — which creates a duplicate subscription.
                // Record the outstanding push instead, and retry it out of band.
                $joinBlockLog->error(
                    "Action Network error for email $email after successful payment — money taken with no CRM"
                    . " record yet: " . $exception->getMessage()
                );
                self::queueCrmRetry($data, 'action_network', $exception->getMessage());
            }
        }

        if (Settings::get("USE_ZETKIN")) {
            $email = $data['email'];
            $joinBlockLog->info("Processing Zetkin signup request for $email");
            try {
                ZetkinService::signup($data);
                $joinBlockLog->info("Completed Zetkin signup request for $email");
            } catch (\Exception $exception) {
                // Non-blocking: Zetkin is a secondary integration. The Stripe payment is
                // the essential step and has already completed by this point. A Zetkin
                // failure (e.g. expired credentials) should not surface an error to the
                // member — they have successfully joined. The member record can be
                // retro-added to Zetkin once the underlying issue is resolved.
                $joinBlockLog->error("Zetkin error for email $email: " . $exception->getMessage());
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

    /**
     * Complete joins where the member paid via Stripe but the /join endpoint was
     * never hit (e.g. they closed the tab after the card payment succeeded).
     * The /stripe/create-subscription endpoint saves the join form data in a
     * JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_{subscriptionId} option, which is
     * deleted by handleJoin() on success.
     *
     * Called with a subscription ID from the invoice.paid webhook (to complete
     * a specific join immediately), and without one from the hourly cron (to
     * sweep up anything the webhook missed).
     *
     * @param string|null $subscriptionId Process only this subscription, or all if null
     */
    public static function ensureStripeSubscriptionsCreated($subscriptionId = null)
    {
        global $wpdb;
        global $joinBlockLog;

        $joinBlockLog->info("Running ensureStripeSubscriptionsCreated");

        if ($subscriptionId) {
            $optionName = "JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_{$subscriptionId}";
            $optionValue = get_option($optionName);
            if (!$optionValue) {
                return;
            }
            $results = [(object) ['option_name' => $optionName, 'option_value' => $optionValue]];
        } else {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}options WHERE option_name LIKE %s",
                    'JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_%'
                )
            );
        }

        StripeService::initialise();

        foreach ($results as $result) {
            $name = $result->option_name;
            $joinBlockLog->info("ensureStripeSubscriptionsCreated: processing {$name}: {$result->option_value}");
            try {
                $data = json_decode($result->option_value, true);
                $createdAt = $data['createdAt'] ?? 0;

                $subscription = null;
                try {
                    $subscription = \Stripe\Subscription::retrieve($data['stripeSubscriptionId']);
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    $joinBlockLog->info(
                        "ensureStripeSubscriptionsCreated: subscription missing in Stripe for {$name}: " .
                        $e->getMessage()
                    );
                }

                if ($subscription && in_array($subscription->status, ['active', 'trialing'])) {
                    self::handleJoin($data);
                    delete_option($name);
                    $joinBlockLog->info("ensureStripeSubscriptionsCreated: success, deleting option {$name}");
                    continue;
                }

                // The subscription is unpaid. Stripe expires incomplete subscriptions
                // after ~23 hours, so after a day there is nothing left to wait for.
                $day = 24 * 60 * 60;
                $expired = !$subscription
                    || in_array($subscription->status, ['incomplete_expired', 'canceled']);

                if ($expired || (time() - $createdAt) > $day) {
                    // If money was actually collected (e.g. the subscription was
                    // cancelled after a successful or still-settling payment),
                    // discarding the join data silently would strand a paid
                    // member outside the CRM.
                    $paidInvoice = false;
                    if ($subscription && !empty($subscription->latest_invoice)) {
                        try {
                            $latestInvoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);
                            $paidInvoice = $latestInvoice->status === 'paid';
                        } catch (\Exception $e) {
                            $joinBlockLog->warning(
                                "ensureStripeSubscriptionsCreated: could not inspect latest invoice for {$name}: "
                                . $e->getMessage()
                            );
                        }
                    }
                    if ($paidInvoice) {
                        $joinBlockLog->error(
                            "ensureStripeSubscriptionsCreated: subscription {$data['stripeSubscriptionId']} has a"
                            . " paid invoice — payment collected without a completed join. Preserving the"
                            . " submitted data for retry."
                        );
                        // Do not discard. This is the only record of what the
                        // member submitted, so dropping it makes the join
                        // unrecoverable without asking them for it again.
                        self::queueCrmRetry(
                            $data,
                            'action_network',
                            'payment collected without a completed join'
                        );
                    } else {
                        $joinBlockLog->info("ensureStripeSubscriptionsCreated: deleting unprocessable {$name}");
                    }
                    delete_option($name);
                } else {
                    $joinBlockLog->info("ensureStripeSubscriptionsCreated: not yet paid, will retry {$name}");
                }
            } catch (\Exception $e) {
                $joinBlockLog->error(
                    "ensureStripeSubscriptionsCreated: could not process {$result->option_value}: " .
                    $e->getMessage()
                );
            }
        }
    }

    public static function shouldLapseMember($email, $context = [], $default = true)
    {
        return (bool) apply_filters('ck_join_flow_should_lapse_member', $default, $email, $context);
    }

    public static function shouldUnlapseMember($email, $context = [], $default = true)
    {
        return (bool) apply_filters('ck_join_flow_should_unlapse_member', $default, $email, $context);
    }

    public static function shouldMarkMemberLapsing($email, $context = [], $default = true)
    {
        return (bool) apply_filters('ck_join_flow_should_mark_member_lapsing', $default, $email, $context);
    }

    public static function toggleMemberLapsed($email, $lapsed = true, $paymentDate = null, $context = [])
    {
        global $joinBlockLog;

        $action = $lapsed ? "Marking" : "Unmarking";
        $done = $lapsed ? "Marked" : "Unmarked";
        $joinBlockLog->info("$action member $email as lapsed");

        if (!Settings::get("LAPSED_TAG")) {
            $joinBlockLog->warning("Skipping lapsed tag update for $email - no lapsed tag has been set. Configure it under WP Admin > CK Join Flow > Membership Plans > Lapsed Tag.");
        }

        if (Settings::get("LAPSED_TAG") && Settings::get("USE_ACTION_NETWORK")) {
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

        if (Settings::get("LAPSED_TAG") && Settings::get("USE_MAILCHIMP")) {
            $joinBlockLog->info("$action member $email as lapsed in Mailchimp");
            try {
                if ($lapsed) {
                    MailchimpService::addTag($email, Settings::get("LAPSED_TAG"));
                } else {
                    MailchimpService::removeTag($email, Settings::get("LAPSED_TAG"));
                }
                $joinBlockLog->info("$done member $email as lapsed in Mailchimp");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Mailchimp error for email $email: " . $exception->getMessage());
            }
        }

        if (Settings::get("LAPSED_TAG") && Settings::get("USE_ZETKIN")) {
            $clientId = Settings::get("ZETKIN_CLIENT_ID");
            $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
            $jwt = Settings::get("ZETKIN_JWT");
            if ($clientId && $clientSecret && $jwt) {
                $joinBlockLog->info("$action member $email as lapsed in Zetkin");
                try {
                    if ($lapsed) {
                        ZetkinService::addTag($email, Settings::get("LAPSED_TAG"));
                    } else {
                        ZetkinService::removeTag($email, Settings::get("LAPSED_TAG"));
                    }
                    $joinBlockLog->info("$done member $email as lapsed in Zetkin");
                } catch (\Exception $exception) {
                    $joinBlockLog->error("Zetkin error for email $email: " . $exception->getMessage());
                    throw $exception;
                }
            } else {
                $joinBlockLog->warning("Can't $action member $email as lapsed in Zetkin - need OAuth credentials");
            }
        }

        // Whether the member is recovering or progressing to fully lapsed, the
        // transient "lapsing" state is over - clear that tag if it is set.
        if (Settings::get("LAPSING_TAG")) {
            try {
                self::toggleMemberLapsing($email, false, $context);
            } catch (\Exception $exception) {
                $joinBlockLog->error("Error clearing lapsing tag for $email: " . $exception->getMessage());
            }
        }

        if ($lapsed) {
            do_action('ck_join_flow_member_lapsed', $email, $context);
        } else {
            do_action('ck_join_flow_member_unlapsed', $email, $context);
        }
    }

    public static function toggleMemberLapsing($email, $lapsing = true, $context = [])
    {
        global $joinBlockLog;

        $action = $lapsing ? "Marking" : "Unmarking";
        $done = $lapsing ? "Marked" : "Unmarked";
        $joinBlockLog->info("$action member $email as lapsing");

        if (!Settings::get("LAPSING_TAG")) {
            $joinBlockLog->warning("Skipping lapsing tag update for $email - no lapsing tag has been set. Configure it under WP Admin > CK Join Flow > Membership Plans > Lapsing Tag.");
            return;
        }

        if (Settings::get("USE_ACTION_NETWORK")) {
            $joinBlockLog->info("$action member $email as lapsing in Action Network");
            try {
                if ($lapsing) {
                    ActionNetworkService::addTag($email, Settings::get("LAPSING_TAG"));
                } else {
                    ActionNetworkService::removeTag($email, Settings::get("LAPSING_TAG"));
                }
                $joinBlockLog->info("$done member $email as lapsing in Action Network");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Action Network error for email $email: " . $exception->getMessage());
                throw $exception;
            }
        }

        if (Settings::get("USE_MAILCHIMP")) {
            $joinBlockLog->info("$action member $email as lapsing in Mailchimp");
            try {
                if ($lapsing) {
                    MailchimpService::addTag($email, Settings::get("LAPSING_TAG"));
                } else {
                    MailchimpService::removeTag($email, Settings::get("LAPSING_TAG"));
                }
                $joinBlockLog->info("$done member $email as lapsing in Mailchimp");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Mailchimp error for email $email: " . $exception->getMessage());
            }
        }

        if (Settings::get("USE_ZETKIN")) {
            $clientId = Settings::get("ZETKIN_CLIENT_ID");
            $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
            $jwt = Settings::get("ZETKIN_JWT");
            if ($clientId && $clientSecret && $jwt) {
                $joinBlockLog->info("$action member $email as lapsing in Zetkin");
                try {
                    if ($lapsing) {
                        ZetkinService::addTag($email, Settings::get("LAPSING_TAG"));
                    } else {
                        ZetkinService::removeTag($email, Settings::get("LAPSING_TAG"));
                    }
                    $joinBlockLog->info("$done member $email as lapsing in Zetkin");
                } catch (\Exception $exception) {
                    $joinBlockLog->error("Zetkin error for email $email: " . $exception->getMessage());
                    throw $exception;
                }
            } else {
                $joinBlockLog->warning("Can't $action member $email as lapsing in Zetkin - need OAuth credentials");
            }
        }

        if ($lapsing) {
            do_action('ck_join_flow_member_lapsing', $email, $context);
        } else {
            do_action('ck_join_flow_member_unlapsing', $email, $context);
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
