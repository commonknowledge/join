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

    // After this many failed attempts the record is parked for a human rather
    // than retried forever. It is never deleted: parking keeps the member's
    // submitted details, which exist nowhere else.
    public const CRM_RETRY_MAX_ATTEMPTS = 12;

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
     * where there is one, falling back to the email so a GoCardless or
     * Chargebee join still gets exactly one record per member.
     *
     * Each fallback must be non-empty before it is used. handleJoin() can be
     * called without an email — it falls back to sessionToken for its lock —
     * and hashing an empty string would give every such record the same option
     * name, so each would silently overwrite the last.
     */
    public static function crmRetryKey($data)
    {
        $subscriptionId = trim((string) ($data['stripeSubscriptionId'] ?? ''));
        if ($subscriptionId !== '') {
            return self::CRM_RETRY_OPTION_PREFIX . $subscriptionId;
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '') {
            return self::CRM_RETRY_OPTION_PREFIX . 'email_' . sha1($email);
        }

        $sessionToken = trim((string) ($data['sessionToken'] ?? ''));
        if ($sessionToken !== '') {
            return self::CRM_RETRY_OPTION_PREFIX . 'session_' . sha1($sessionToken);
        }

        // Last resort: the payload itself. Two identical payloads collapsing to
        // one record is correct de-duplication; two different ones must not
        // collide, which hashing a missing identifier would guarantee.
        return self::CRM_RETRY_OPTION_PREFIX . 'payload_' . sha1((string) wp_json_encode($data));
    }

    /**
     * Durably record that a CRM push is still outstanding for a member whose
     * payment has already succeeded.
     *
     * This record is the only copy of what the member submitted — the join
     * form data is not otherwise persisted — so losing it means their details
     * can only be recovered by asking them again.
     *
     * @param array $data The join payload.
     * @param array|null $services Service keys still outstanding, or null for
     *                             "every configured CRM", used when we do not
     *                             know how far the original join got.
     */
    public static function queueCrmRetry($data, $services, $reason)
    {
        global $joinBlockLog;

        $optionName = self::crmRetryKey($data);

        $attempts = 1;
        $existing = get_option($optionName);
        if ($existing) {
            $decoded = json_decode($existing, true);
            $attempts = (int) ($decoded['attempts'] ?? 0) + 1;
        }

        // autoload disabled: each record carries the full join payload, and a
        // backlog of them would otherwise be read into memory on every single
        // page request.
        update_option($optionName, wp_json_encode([
            'data' => $data,
            'services' => $services,
            'reason' => $reason,
            'attempts' => $attempts,
            'lastAttemptAt' => time(),
        ]), false);

        $email = $data['email'] ?? 'unknown';
        $label = $services ? implode(', ', $services) : 'all configured CRMs';
        $joinBlockLog->error("Queued CRM retry for $email ($label, attempt $attempts): $reason");
    }

    /**
     * Push a member to every configured CRM, returning the service keys that
     * failed.
     *
     * Contains no payment code at all — no subscription creation, cancellation
     * or Chargebee/GoCardless/Auth0 calls. The retry worker depends on that
     * structurally: replaying a CRM push must never be able to touch a payment
     * that is working fine, and from here it cannot reach the code that would.
     *
     * Each service is independent — one failing must not stop the others.
     *
     * @param array $data The join payload.
     * @param array|null $only Restrict to these service keys, or null for every
     *                         configured service.
     * @return string[] Service keys that failed.
     */
    public static function pushToCrms($data, $only = null)
    {
        global $joinBlockLog;

        $email = $data['email'] ?? 'unknown';
        $wanted = function ($service) use ($only) {
            return $only === null || in_array($service, $only, true);
        };
        $failed = [];

        if (Settings::get("USE_MAILCHIMP") && $wanted('mailchimp')) {
            $joinBlockLog->info("Processing Mailchimp signup request for $email");
            try {
                MailchimpService::signup($data);
                $joinBlockLog->info("Completed Mailchimp signup request for $email");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Mailchimp error for email $email: " . $exception->getMessage());
                $failed[] = 'mailchimp';
            }
        }

        if (Settings::get("USE_ACTION_NETWORK") && $wanted('action_network')) {
            $joinBlockLog->info("Processing Action Network signup request for $email");
            try {
                ActionNetworkService::signup($data);
                $joinBlockLog->info("Completed Action Network signup request for $email");
            } catch (\Exception $exception) {
                $joinBlockLog->error(
                    "Action Network error for email $email after successful payment — money taken with no CRM"
                    . " record yet: " . $exception->getMessage()
                );
                $failed[] = 'action_network';
            }
        }

        if (Settings::get("USE_ZETKIN") && $wanted('zetkin')) {
            $joinBlockLog->info("Processing Zetkin signup request for $email");
            try {
                ZetkinService::signup($data);
                $joinBlockLog->info("Completed Zetkin signup request for $email");
            } catch (\Exception $exception) {
                $joinBlockLog->error("Zetkin error for email $email: " . $exception->getMessage());
                $failed[] = 'zetkin';
            }
        }

        return $failed;
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

        // autoload disabled — see queueCrmRetry(): full join payload.
        update_option($optionName, wp_json_encode($data), false);
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

            $customerId = StripeService::resolveCustomerId($email, $data["stripeCustomerId"] ?? null);

            // A recovery replay runs on data saved hours or days earlier, by
            // which time the member may have joined again. Cancelling
            // "previous" subscriptions from that stale view would cancel the
            // live one. De-duplication belongs to the live flow, which knows
            // the current subscription; a replay only ever reads.
            if (empty($data['isRecoveryReplay'])) {
                StripeService::cancelPreviousSubscriptions($email, $customerId, $subscriptionId);
            } else {
                $joinBlockLog->info("Recovery replay for $email: leaving existing subscriptions untouched");
            }

            $subscriptionInfo = StripeService::getSubscriptionDates($email, $customerId);
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

        // The payment has already succeeded by this point, so a CRM failure is
        // not something the member can act on. Record what is outstanding and
        // let ensureCrmPushesCompleted() finish it, rather than showing them an
        // error and inviting the resubmit that creates a duplicate subscription.
        $failedCrms = self::pushToCrms($data);
        if ($failedCrms) {
            self::queueCrmRetry($data, $failedCrms, 'push failed during join');
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
                    // Replay: complete the join without touching payment state.
                    $data['isRecoveryReplay'] = true;
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
                        // Services unknown: the join never ran, so retry them all.
                        self::queueCrmRetry(
                            $data,
                            null,
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

    /**
     * Seconds to wait before the next attempt: 1h, 2h, 4h ... capped at 24h.
     *
     * A CRM that is down is usually down for everyone, so retrying every queued
     * member hourly helps nobody and buries the useful log lines.
     */
    public static function crmRetryBackoffSeconds($attempts)
    {
        $hours = min(2 ** max(0, (int) $attempts - 1), 24);
        return $hours * 3600;
    }

    public static function crmRetryIsDue($record, $now = null)
    {
        $now = $now ?? time();
        $lastAttemptAt = (int) ($record['lastAttemptAt'] ?? 0);
        $backoff = self::crmRetryBackoffSeconds($record['attempts'] ?? 0);
        return ($now - $lastAttemptAt) >= $backoff;
    }

    /**
     * Read-only check that the member's payment still stands.
     *
     * Deliberately never mutates. This runs long after the join, by which time
     * the member may have re-joined or changed plan, so altering a subscription
     * from this stale data would damage a payment that is working fine.
     *
     * @return bool|null true = paid, false = no payment, null = could not tell.
     */
    private static function paymentStillStands($data)
    {
        $subscriptionId = $data['stripeSubscriptionId'] ?? '';
        if (!$subscriptionId) {
            // A GoCardless or Chargebee join: there is no Stripe subscription to
            // check, and the record only exists because a payment succeeded.
            return true;
        }

        StripeService::initialise();
        return StripeService::subscriptionWasPaid($subscriptionId);
    }

    /**
     * Stop retrying a record, without ever deleting it.
     *
     * Parking is how the worker makes progress on something it cannot finish.
     * The record is the only copy of what the member submitted, so it stays for
     * a human to pick up — but it is not re-attempted, and not re-logged on
     * every subsequent run.
     */
    private static function parkCrmRetry($optionName, $record, $reason)
    {
        global $joinBlockLog;

        $record['parked'] = true;
        $record['parkedReason'] = $reason;
        $record['parkedAt'] = time();

        update_option($optionName, wp_json_encode($record), false);

        $joinBlockLog->error(
            "ensureCrmPushesCompleted: parked $optionName — $reason. The submitted details are preserved in"
            . " that option; complete the CRM record manually."
        );
    }

    /**
     * Retry outstanding CRM pushes for members whose payment already succeeded.
     *
     * Complements reconcileRecentMemberships(), which can detect a member who
     * paid but never reached the CRM yet has no copy of their submitted details
     * to fix it with. This worker holds that copy.
     *
     * Touches no payment state: it reads Stripe to confirm the payment stands,
     * then only writes to CRMs.
     */
    public static function ensureCrmPushesCompleted()
    {
        global $wpdb;
        global $joinBlockLog;

        $joinBlockLog->info("Running ensureCrmPushesCompleted");

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}options WHERE option_name LIKE %s",
                self::CRM_RETRY_OPTION_PREFIX . '%'
            )
        );

        foreach ($results as $result) {
            $name = $result->option_name;
            try {
                $record = json_decode($result->option_value, true);

                if (!is_array($record)) {
                    // Keep the raw value: it is still the only copy of whatever
                    // was written here, and may be recoverable by hand.
                    self::parkCrmRetry(
                        $name,
                        ['raw' => $result->option_value],
                        'record could not be decoded'
                    );
                    continue;
                }

                if (!empty($record['parked'])) {
                    continue;
                }

                $data = $record['data'] ?? null;
                $email = $data['email'] ?? '';

                if (!$data || !$email) {
                    // Without an email there is no CRM to push to and no lock to
                    // take, so this can never succeed. Park it rather than
                    // re-reading and re-logging it on every run forever.
                    self::parkCrmRetry($name, $record, 'no usable join data (missing payload or email)');
                    continue;
                }

                $attempts = (int) ($record['attempts'] ?? 0);
                if ($attempts >= self::CRM_RETRY_MAX_ATTEMPTS) {
                    self::parkCrmRetry($name, $record, "gave up after $attempts attempts");
                    continue;
                }

                if (!self::crmRetryIsDue($record)) {
                    continue;
                }

                $paymentStands = self::paymentStillStands($data);
                if ($paymentStands === null) {
                    // Could not reach Stripe. Leave the attempt count alone so a
                    // provider outage does not burn through the retry budget.
                    $joinBlockLog->warning(
                        "ensureCrmPushesCompleted: could not confirm payment for $email, will try again later"
                    );
                    continue;
                }
                if ($paymentStands === false) {
                    self::parkCrmRetry($name, $record, "no surviving payment for $email — refund or complete by hand");
                    continue;
                }

                // Same lock the /join endpoint and the webhooks take, so a retry
                // cannot interleave with a live write for the same person.
                $lockFile = self::acquireLock($email);
                try {
                    $failed = self::pushToCrms($data, $record['services'] ?? null);
                } finally {
                    self::releaseLock($lockFile);
                }

                if (!$failed) {
                    delete_option($name);
                    // Deliberately does not fire ck_join_flow_success: handleJoin()
                    // now completes even when a CRM push fails, so the hook has
                    // already fired for this member and the heartbeat digest would
                    // count them twice.
                    $joinBlockLog->info("ensureCrmPushesCompleted: completed CRM push for $email, cleared $name");
                    continue;
                }

                $record['services'] = $failed;
                $record['attempts'] = $attempts + 1;
                $record['lastAttemptAt'] = time();
                update_option($name, wp_json_encode($record), false);
                $joinBlockLog->warning(
                    "ensureCrmPushesCompleted: still outstanding for $email (" . implode(', ', $failed) . "),"
                    . " attempt " . ($attempts + 1) . " of " . self::CRM_RETRY_MAX_ATTEMPTS
                );
            } catch (\Exception $e) {
                $joinBlockLog->error("ensureCrmPushesCompleted: could not process $name: " . $e->getMessage());
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
