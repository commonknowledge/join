<?php

namespace CommonKnowledge\JoinBlock\Services;

use CommonKnowledge\JoinBlock\Settings;
use GuzzleHttp\Client;

class ActionNetworkService
{
    public static function signup($data)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Adding {$data['email']} to Mailchimp");

        if ($data['isUpdateFlow']) {
            $anData = [
                "person" => [
                    "email_addresses" => [
                        "address" => $data["email"],
                        "primary" => true,
                        "status" => $data["contactByEmail"] ? "subscribed" : "unsubscribed"
                    ]
                ]
            ];
        } else {
            $anData = [
                "person" => [
                    "email_addresses" => [[
                        "address" => $data["email"],
                        "primary" => true,
                        "status" => $data["contactByEmail"] ? "subscribed" : "unsubscribed"
                    ]],
                    "phone_numbers" => [[
                        "number" => $data["phoneNumber"],
                        "primary" => true,
                        "status" => $data["contactByPhone"] ? "subscribed" : "unsubscribed"
                    ]],
                    "given_name" => $data["firstName"],
                    "family_name" => $data["lastName"],
                    "postal_addresses" => [[
                        "primary" => true,
                        "address_lines" => [
                            $data["addressLine1"],
                            $data["addressLine2"],
                        ],
                        "locality" => $data["addressCity"],
                        "postal_code" => $data["addressPostcode"],
                        "country" => $data["addressCountry"]
                    ]]
                ]
            ];
        }

        $client = new Client();
        $client->request(
            "POST",
            "https://actionnetwork.org/api/v2/people/",
            [
                "headers" => [
                    "OSDI-API-Token" => Settings::get("ACTION_NETWORK_API_KEY")
                ],
                "json" => $anData,
            ]
        );
    }
}
