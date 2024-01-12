<?php

namespace CommonKnowledge\JoinBlock\Services;

use CommonKnowledge\JoinBlock\Settings;

class GocardlessService
{
    public static function createCustomerMandate($data)
    {
        $client = self::getClient();

        $customer = $client->customers()->create([
            "params" => [
                "email" => $data['email'],
                "given_name" => $data['firstName'],
                "family_name" => $data['lastName'],
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

        return $client->mandates()->create([
            "params" => [
                "scheme" => "bacs",
                "links" => [
                    "customer_bank_account" => $account->id
                ]
            ]
        ]);
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
