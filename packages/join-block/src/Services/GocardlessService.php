<?php

namespace CommonKnowledge\JoinBlock\Services;

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

        if ($_ENV['WP_ENV'] === 'production') {
            $gocardlessEnvironment =  \GoCardlessPro\Environment::LIVE;
        } else {
            $joinBlockLog->warning('WP_ENV is not set to production, using GoCardless Sandbox environment.');
            $gocardlessEnvironment =  \GoCardlessPro\Environment::SANDBOX;
        }

        $client = new \GoCardlessPro\Client([
            'access_token' => $_ENV['GC_ACCESS_TOKEN'],
            'environment' => $gocardlessEnvironment
        ]);

        return $client;
    }
}
