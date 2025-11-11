<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ActionNetworkService
{
    public static function signup($data)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Adding {$data['email']} to Action Network");

        $addTags = $data["membershipPlan"]["add_tags"] ?? "";
        $removeTags = $data["membershipPlan"]["remove_tags"] ?? "";

        $addTags = array_map(function ($tag) {
            return trim($tag);
        }, explode(",", $addTags));

        $removeTags = array_map(function ($tag) {
            return trim($tag);
        }, explode(",", $removeTags));

        // Allow third-party code to modify tags before they're applied
        // Generic filter applies to all services
        $addTags = apply_filters('ck_join_flow_add_tags', $addTags, $data, 'action_network');
        $removeTags = apply_filters('ck_join_flow_remove_tags', $removeTags, $data, 'action_network');
        
        // Service-specific filter for Action Network-only customization
        $addTags = apply_filters('ck_join_flow_action_network_add_tags', $addTags, $data);
        $removeTags = apply_filters('ck_join_flow_action_network_remove_tags', $removeTags, $data);

        $customFieldValues = [
            "How did you hear about us?" => $data['howDidYouHearAboutUs'],
            "How did you hear about us? (Details)" => $data['howDidYouHearAboutUsDetails'] ?? "",
            "Date of birth" => $data['dob'],
            "First Stripe Subscription Date" => $data['stripeFirstSubscriptionDate'],
            "First Stripe Payment Date" => $data['stripeFirstPaymentDate'],
            "Latest Stripe Payment Date" => $data['stripeLastPaymentDate'],
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

        try {
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
        } catch (RequestException $e) {
            $jsonData = json_encode($anData);
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $joinBlockLog->error("Action Network request failed with payload: $jsonData. Response: $responseBody");
            throw $e;
        }
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

    public static function updateCustomFields($email, $fields)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Updating " . $email . " in Action Network with fields " . json_encode($fields));

        $client = new Client();

        $data = [
            "person" => [
                "email_addresses" => [
                    [
                        "address" => $email,
                        "primary" => true
                    ]
                ],
                "custom_fields" => $fields,
            ],
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
