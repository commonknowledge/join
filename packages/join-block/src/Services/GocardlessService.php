<?php

namespace CommonKnowledge\JoinBlock\Services;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Exceptions\SubscriptionExistsException;
use CommonKnowledge\JoinBlock\Settings;

class GocardlessService
{
    /**
     * Returns a GoCardless Subscription instance, with
     * $subscription->links->customer set to the customer ID.
     */
    public static function createCustomerSubscription($data)
    {
        global $joinBlockLog;
        $client = self::getClient();

        $customer = null;

        // From cookie set when user returns from external GoCardless page
        $billingRequestId = $data['gcBillingRequestId'] ?? null;
        if ($billingRequestId) {
            $customerId = self::getCustomerIdByCompletedBillingRequest($billingRequestId);
            if ($customerId) {
                $customer = $client->customers()->get($customerId);
                $joinBlockLog->info("Got customer {$customer->id} from billing request {$billingRequestId}");
            }
        }

        if (!$customer) {
            // Catch case when the user has managed to submit twice in succession
            // (should be impossible but you never know)
            $fiveMinsAgo = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 minutes'));
            $customers = $client->customers()->list([
                "params" => ["created_at[gt]" => $fiveMinsAgo]
            ]);
            foreach ($customers->records as $c) {
                if ($c->email === $data["email"]) {
                    $customer = $c;
                }
            }
        }

        $mandate = null;

        if ($customer) {
            $mandates = $client->mandates()->list([
                "params" => ["customer" => $customer->id]
            ]);
            if (count($mandates->records) > 0) {
                $mandate = $mandates->records[0];
                $joinBlockLog->info("Found existing mandate for " . $data['email'] . " {$customer->id} {$mandate->id}");
            }
        }

        if (!$customer) {
            if (empty($data['firstName']) && empty($data['lastName'])) {
                $names = explode(' ', $data['ddAccountHolderName'] ?? '');
                $lastName = array_pop($names);
                $firstName = implode(' ', $names);
            } else {
                $firstName = $data['firstName'] ?? '';
                $lastName = $data['lastName'] ?? '';
            }

            $customer = $client->customers()->create([
                "params" => [
                    "email" => $data['email'],
                    "given_name" => $firstName,
                    "family_name" => $lastName,
                    "country_code" => $data['addressCountry'],
                    "phone_number" => $data['phoneNumber']
                ]
            ]);

            $account = $client->customerBankAccounts()->create([
                "params" => [
                    "account_number" => $data["ddAccountNumber"],
                    "branch_code" => $data["ddSortCode"],
                    "account_holder_name" => $data["ddAccountHolderName"],
                    "country_code" => $data["addressCountry"],
                    "links" => ["customer" => $customer->id]
                ]
            ]);
        }

        if ($customer) {
            $mandateId = $mandate ? $mandate->id : null;
            do_action("ck_join_flow_delete_existing_gocardless_customer", $data['email'], $customer->id, $mandateId);
        }

        if (!$mandate) {
            $mandate = $client->mandates()->create([
                "params" => [
                    "scheme" => "bacs",
                    "links" => [
                        "customer_bank_account" => $account->id
                    ]
                ]
            ]);
        }
        $amountInPence = round(((float) $data['membershipPlan']['amount']) * 100);

        $subscriptions = $client->subscriptions()->list([
            "params" => ["mandate" => $mandate->id]
        ]);
        $existingSubscriptionId = null;
        foreach ($subscriptions->records as $subscription) {
            if ($subscription->amount == $amountInPence && $subscription->status === "active") {
                $existingSubscriptionId = $subscription->id;
            } else {
                self::deleteCustomerSubscription($subscription->id);
            }
        }
        if ($existingSubscriptionId) {
            $joinBlockLog->info("Subscription exists for " . $data['email'] . ", customer {$customer->id}: $existingSubscriptionId");
            $optionName = "JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_{$billingRequestId}";
            $joinBlockLog->info("Subscription exists, deleting option {$optionName}");
            delete_option($optionName);
            throw new SubscriptionExistsException();
        }

        $subscriptionParams = [
            "amount" => $amountInPence,
            "currency" => $data['membershipPlan']['currency'],
            "name" => $data['membershipPlan']['label'],
            "interval_unit" => $data['membershipPlan']['frequency'],
            "links" => ["mandate" => $mandate->id]
        ];
        $joinBlockLog->info("Creating subscription for " . $data['email'] . ", customer {$customer->id}, params: " . wp_json_encode($subscriptionParams));
        $subscription = $client->subscriptions()->create([
            "params" => $subscriptionParams
        ]);
        $subscription->links->customer = $customer->id;
        $joinBlockLog->info("Created subscription for " . $data['email'] . ", customer {$customer->id}: " . $subscription->id);

        // Remove this session from the uncompleted list
        // (which is processed when GoCardless sends a webhook)
        $optionName = "JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_{$billingRequestId}";
        $joinBlockLog->info("Subscription created, deleting option {$optionName}");
        delete_option($optionName);

        return $subscription;
    }

    public static function deleteCustomerSubscription($id)
    {
        global $joinBlockLog;
        $client = self::getClient();

        try {
            $client->subscriptions()->cancel($id);
        } catch (\Exception $e) {
            $joinBlockLog->error("Failed to delete customer subscription $id: " . $e->getMessage());
        }
    }

    public static function removeCustomerById($customerId)
    {
        global $joinBlockLog;
        $joinBlockLog->info("Removing existing customer {$customerId}");
        $client = self::getClient();
        try {
            $client->customers()->remove($customerId);
            $joinBlockLog->info("Removed existing customer {$customerId}");
        } catch (\Exception $e) {
            $joinBlockLog->error("Failed to delete customer $customerId: " . $e->getMessage());
        }
    }

    public static function removeCustomerMandates($customerId, $dontRemoveId = null)
    {
        global $joinBlockLog;
        $joinBlockLog->info("Removing existing mandates for customer {$customerId}");
        $client = self::getClient();
        $mandates = $client->mandates()->list([
            "params" => ["customer" => $customerId]
        ]);
        foreach ($mandates->records as $mandate) {
            if ($mandate->id === $dontRemoveId) {
                $joinBlockLog->info("Not removing mandate for customer {$customerId}" . $mandate->id);
                continue;
            }
            try {
                $joinBlockLog->info("Removing existing mandate for customer {$customerId}" . $mandate->id);
                $client->mandates()->cancel($mandate->id);
                $joinBlockLog->info("Removed existing mandate for customer {$customerId}" . $mandate->id);
            } catch (\Exception $e) {
                $joinBlockLog->error("Failed to delete customer {$customerId} mandate {$mandate->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Create a Billing Request for a user to create a mandate on the
     * GoCardless website. Returns a GoCardless URL to redirect the
     * user to, and the Billing Request ID, which can be used
     * to get the Customer and Mandate IDs after the user has
     * completed the flow. This Billing Request ID could be
     * e.g. stored in a cookie to allow fetching the
     * Customer details on subsequent requests.
     */
    public static function getBillingRequestIdAndUrl($redirectUri, $exitUri)
    {
        $client = self::getClient();
        $billingRequest = $client->billingRequests()->create([
            "params" => [
                "mandate_request" => [
                    "scheme" => "bacs"
                ]
            ]
        ]);
        $billingRequestFlow = $client->billingRequestFlows()->create([
            "params" => [
                "redirect_uri" => $redirectUri,
                "exit_uri" => $exitUri,
                "links" => [
                    "billing_request" => $billingRequest->id
                ]
            ]
        ]);
        return [
            "id" => $billingRequest->id,
            "url" => $billingRequestFlow->authorisation_url
        ];
    }

    public static function getCustomerIdByCompletedBillingRequest($billingRequestId)
    {
        global $joinBlockLog;
        $client = self::getClient();

        try {
            $billingRequest = $client->billingRequests()->get($billingRequestId);
            if (empty($billingRequest->links->mandate_request_mandate)) {
                return null;
            }
            return $billingRequest->links->customer;
        } catch (\Exception $e) {
            $joinBlockLog->error("Failed to get customer from billing request $billingRequestId: " . $e->getMessage());
        }
    }

    public static function getCustomerIdByPayment($paymentId)
    {
        global $joinBlockLog;
        $client = self::getClient();

        try {
            $payment = $client->payments()->get($paymentId);
            if (!$payment) {
                return null;
            }
            $mandate = $client->mandates()->get($payment->links->mandate);
            return $mandate ? $mandate->links->customer : null;
        } catch (\Exception $e) {
            $joinBlockLog->error("Failed to get mandate from payment $paymentId: " . $e->getMessage());
        }
    }

    private static function getClient()
    {
        global $joinBlockLog;

        if (Settings::get('GC_ENVIRONMENT') === 'live') {
            $gocardlessEnvironment =  \GoCardlessPro\Environment::LIVE;
        } else {
            $joinBlockLog->warning('WP_ENV is not set to live, using GoCardless Sandbox environment.');
            $gocardlessEnvironment =  \GoCardlessPro\Environment::SANDBOX;
        }

        $client = new \GoCardlessPro\Client([
            'access_token' => Settings::get('GC_ACCESS_TOKEN'),
            'environment' => $gocardlessEnvironment
        ]);

        return $client;
    }
}
