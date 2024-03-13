<?php

namespace CommonKnowledge\JoinBlock\Services;

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

        $existingCustomer = null;

        $savedCustomerId = $data['gcCustomerId'] ?? null;
        if ($savedCustomerId) {
            $existingCustomer = $client->customers()->get($savedCustomerId);
        }

        if (!$existingCustomer) {
            // Catch case when the user has managed to submit twice in succession
            // (should be impossible but you never know)
            $fiveMinsAgo = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 minutes'));
            $customers = $client->customers()->list([
                "params" => ["created_at[gt]" => $fiveMinsAgo]
            ]);
            foreach ($customers->records as $customer) {
                if ($customer->email === $data["email"]) {
                    $existingCustomer = $customer;
                }
            }
        }

        $customer = apply_filters("ck_join_flow_find_gocardless_customer", $existingCustomer, $data);
        $mandate = null;

        if ($customer) {
            $mandates = $client->mandates()->list([
                "params" => ["customer" => $customer->id]
            ]);
            if (count($mandates->records) > 0) {
                $joinBlockLog->info("Found existing mandate for " . $data['email']);
                $mandate = $mandates->records[0];
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

        $subscriptions = $client->subscriptions()->list([
            "params" => ["mandate" => $mandate->id]
        ]);
        foreach ($subscriptions->records as $subscription) {
            self::deleteCustomerSubscription($subscription->id);
        }

        $amountInPence = round(((float) $data['membershipPlan']['amount']) * 100);
        $subscriptionParams = [
            "amount" => $amountInPence,
            "currency" => $data['membershipPlan']['currency'],
            "name" => $data['membershipPlan']['label'],
            "interval_unit" => $data['membershipPlan']['frequency'],
            "links" => ["mandate" => $mandate->id]
        ];
        $subscription = $client->subscriptions()->create([
            "params" => $subscriptionParams
        ]);
        $subscription->links->customer = $customer->id;
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

    public static function getCustomerById($customerId)
    {
        $client = self::getClient();
        $customer = $client->customers()->get($customerId);
        return $customer;
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

    public static function getCustomerIdByBillingRequest($billingRequestId)
    {
        global $joinBlockLog;
        $client = self::getClient();

        try {
            $billingRequest = $client->billingRequests()->get($billingRequestId);
            return $billingRequest ? $billingRequest->links->customer : null;
        } catch (\Exception $e) {
            $joinBlockLog->error("Failed to get customer from billing request $billingRequestId: " . $e->getMessage());
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
