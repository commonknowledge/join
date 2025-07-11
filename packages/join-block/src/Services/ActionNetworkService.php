<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Settings;
use GuzzleHttp\Client;

class ActionNetworkService
{
    public static function signup($data)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Adding {$data['email']} to Mailchimp");

        $addTags = $data["membershipPlan"]["add_tags"] ?? "";
        $removeTags = $data["membershipPlan"]["remove_tags"] ?? "";

        $addTags = array_map(function ($tag) {
            return trim($tag);
        }, explode(",", $addTags));

        $removeTags = array_map(function ($tag) {
            return trim($tag);
        }, explode(",", $removeTags));

        $customFieldValues = [
            "How did you hear about us?" => $data['howDidYouHearAboutUs'],
            "How did you hear about us? (Details)" => $data['howDidYouHearAboutUsDetails'],
            "Date of birth" => $data['dob']
        ];
        $customFieldsConfig = $data['customFieldsConfig'] ?? [];
        foreach ($customFieldsConfig as $customField) {
            $customFieldValues[$customField["id"]] = $data[$customField["id"]] ?? "";
        }

        if ($data['isUpdateFlow']) {
            $anData = [
                "person" => [
                    "email_addresses" => [
                        "address" => $data["email"],
                        "primary" => true,
                        "status" => $data["contactByEmail"] ? "subscribed" : "unsubscribed"
                    ],
                ],
                "add_tags" => $addTags,
                "remove_tags" => $removeTags,
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
                    ]],
                    "custom_fields" => $customFieldValues,
                ],
                "add_tags" => $addTags,
                "remove_tags" => $removeTags,
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

    public static function addTag($email, $tag)
    {
        $client = new Client();

        $data = [
            "add_tags" => [$tag],
            "person" => [
                "email_addresses" => [
                    [
                        "address" => $email,
                        "primary" => true
                    ]
                ]
            ]
        ];

        $client->request(
            "POST",
            "https://actionnetwork.org/api/v2/people/",
            [
                "headers" => [
                    "OSDI-API-Token" => Settings::get("ACTION_NETWORK_API_KEY")
                ],
                "json" => $data
            ]
        );
    }

    public static function removeTag($email, $tag)
    {
        $client = new Client();

        $data = [
            "remove_tags" => [$tag],
            "person" => [
                "email_addresses" => [
                    [
                        "address" => $email,
                        "primary" => true
                    ]
                ]
            ]
        ];

        $client->request(
            "POST",
            "https://actionnetwork.org/api/v2/people/",
            [
                "headers" => [
                    "OSDI-API-Token" => Settings::get("ACTION_NETWORK_API_KEY")
                ],
                "json" => $data
            ]
        );
    }
}
