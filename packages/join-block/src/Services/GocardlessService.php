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

        // Catch case when the user has managed to submit twice in succession
        // (should be impossible but you never know)
        $fiveMinsAgo = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 minutes'));
        $customers = $client->customers()->list([
            "params" => ["created_at[gt]" => $fiveMinsAgo]
        ]);

        $existingCustomer = null;
        foreach ($customers->records as $customer) {
            if ($customer->email === $data["email"]) {
                $existingCustomer = $customer;
            }
        }

        if ($existingCustomer) {
            $mandates = $client->mandates()->list([
                "params" => ["customer" => $customer->id]
            ]);
            if (count($mandates->records) > 0) {
                $joinBlockLog->info("Found existing mandate for " . $data['email']);
                return $mandates->records[0];
            }
        }

        if (empty($data['firstName']) && empty($data['lastName'])) {
            $names = explode(' ', $data['ddAccountHolderName'] ?? '');
            $lastName = array_pop($names);
            $firstName = implode(' ', $names);
        } else {
            $firstName = $data['firstName'] ?? '';
            $lastName = $data['firstName'] ?? '';
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

        $mandate = $client->mandates()->create([
            "params" => [
                "scheme" => "bacs",
                "links" => [
                    "customer_bank_account" => $account->id
                ]
            ]
        ]);

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
