<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ActionNetworkService
{
    // Bounds for getPersonTagNames(). Action Network's default page size is 25,
    // so 10 pages covers people with up to ~250 taggings. People with more than
    // that fall back to the un-optimised path (just call the API and let
    // Action Network handle the no-op).
    private const MAX_TAGGING_PAGES = 10;
    private const HTTP_TIMEOUT_SECONDS = 10;

    // Sentinel returned by getPersonTagNames() when enumeration was aborted
    // (page cap hit, HTTP failure). Distinct from null (person not found) and
    // from [] (person has zero tags).
    private const TAG_ENUMERATION_ABORTED = false;

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
            $value = $data[$customField["id"]] ?? "";
            if (
                ($customField["field_type"] ?? "") === "checkbox"
                && !empty($customField["send_as_string"])
            ) {
                $value = $value ? "true" : "false";
            }
            $customFieldValues[$customField["id"]] = $value;
        }

        if ($data['isUpdateFlow']) {
            $anData = [
                "person" => [
                    "email_addresses" => [[
                        "address" => $data["email"],
                        "primary" => true,
                        "status" => $data["contactByEmail"] ? "subscribed" : "unsubscribed"
                    ]],
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

        // TODO: remove after REI debugging complete
        $joinBlockLog->info("Action Network signup request payload: " . json_encode($anData));

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

    public static function personExists($email)
    {
        return self::getPerson($email) !== null;
    }

    private static function getPerson($email)
    {
        global $joinBlockLog;

        $client = new Client();

        $query = [
            "filter" => "email_address eq '" . $email . "'"
        ];

        // TODO: remove after REI debugging complete
        $joinBlockLog->info("Action Network getPerson request query: " . json_encode($query));

        $response = $client->request(
            "GET",
            "https://actionnetwork.org/api/v2/people/",
            [
                "headers" => [
                    "OSDI-API-Token" => Settings::get("ACTION_NETWORK_API_KEY")
                ],
                "query" => $query,
                "timeout" => self::HTTP_TIMEOUT_SECONDS,
                "connect_timeout" => self::HTTP_TIMEOUT_SECONDS,
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true);
        $people = $data["_embedded"]["osdi:people"] ?? [];
        return $people[0] ?? null;
    }

    /**
     * Returns the names of tags applied to the person, or null if the person
     * does not exist, or self::TAG_ENUMERATION_ABORTED (false) if we hit the
     * page cap or an HTTP error. Callers should treat the aborted sentinel as
     * "unknown" and fall back to calling the underlying API anyway.
     */
    private static function getPersonTagNames($email)
    {
        global $joinBlockLog;

        $person = self::getPerson($email);
        if ($person === null) {
            return null;
        }

        $taggingsHref = $person["_links"]["osdi:taggings"]["href"] ?? null;
        if (!$taggingsHref) {
            return [];
        }

        $client = new Client();
        $requestOptions = [
            "headers" => ["OSDI-API-Token" => Settings::get("ACTION_NETWORK_API_KEY")],
            "timeout" => self::HTTP_TIMEOUT_SECONDS,
            "connect_timeout" => self::HTTP_TIMEOUT_SECONDS,
        ];

        $tagNames = [];
        $nextHref = $taggingsHref;
        $page = 0;
        try {
            while ($nextHref) {
                if ($page >= self::MAX_TAGGING_PAGES) {
                    $joinBlockLog->warning(
                        "Action Network getPersonTagNames($email): aborting enumeration after " .
                        self::MAX_TAGGING_PAGES . " pages — falling back to un-optimised path"
                    );
                    return self::TAG_ENUMERATION_ABORTED;
                }
                $page++;

                $response = $client->request("GET", $nextHref, $requestOptions);
                $body = json_decode($response->getBody()->getContents(), true);
                $taggings = $body["_embedded"]["osdi:taggings"] ?? [];
                foreach ($taggings as $tagging) {
                    $tagHref = $tagging["_links"]["osdi:tag"]["href"] ?? null;
                    if (!$tagHref) {
                        continue;
                    }
                    $tagResponse = $client->request("GET", $tagHref, $requestOptions);
                    $tagBody = json_decode($tagResponse->getBody()->getContents(), true);
                    if (!empty($tagBody["name"])) {
                        $tagNames[] = $tagBody["name"];
                    }
                }
                $candidateNext = $body["_links"]["next"]["href"] ?? null;
                // Defensive: Action Network shouldn't return a cyclic next link,
                // but guard against it explicitly rather than spinning.
                if ($candidateNext === $nextHref) {
                    $joinBlockLog->warning(
                        "Action Network getPersonTagNames($email): next link did not advance — aborting enumeration"
                    );
                    return self::TAG_ENUMERATION_ABORTED;
                }
                $nextHref = $candidateNext;
            }
        } catch (\Exception $e) {
            $joinBlockLog->warning(
                "Action Network getPersonTagNames($email): enumeration failed (" . $e->getMessage() .
                ") — falling back to un-optimised path"
            );
            return self::TAG_ENUMERATION_ABORTED;
        }

        return $tagNames;
    }

    // Callers must hold the per-email JoinService lock — see JoinService::acquireLock().
    // The get/check/post sequence is otherwise a TOCTOU race.
    public static function addTag($email, $tag)
    {
        global $joinBlockLog;

        $tagNames = self::getPersonTagNames($email);
        if ($tagNames === null) {
            $joinBlockLog->warning("Skipping Action Network addTag('$tag') for $email: person does not exist");
            return;
        }
        if (is_array($tagNames) && in_array($tag, $tagNames, true)) {
            $joinBlockLog->info("Skipping Action Network addTag('$tag') for $email: tag already applied");
            return;
        }
        // $tagNames === TAG_ENUMERATION_ABORTED falls through and we apply the
        // tag anyway — Action Network treats a repeat add as a no-op.

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

        // TODO: remove after REI debugging complete
        $joinBlockLog->info("Action Network addTag request payload: " . json_encode($data));

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

    // Callers must hold the per-email JoinService lock — see JoinService::acquireLock().
    // The get/check/post sequence is otherwise a TOCTOU race.
    public static function removeTag($email, $tag)
    {
        global $joinBlockLog;

        $tagNames = self::getPersonTagNames($email);
        if ($tagNames === null) {
            $joinBlockLog->warning("Skipping Action Network removeTag('$tag') for $email: person does not exist");
            return;
        }
        if (is_array($tagNames) && !in_array($tag, $tagNames, true)) {
            $joinBlockLog->info("Skipping Action Network removeTag('$tag') for $email: tag not applied");
            return;
        }
        // $tagNames === TAG_ENUMERATION_ABORTED falls through and we send the
        // remove anyway — Action Network treats removing an absent tag as a no-op.

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

        // TODO: remove after REI debugging complete
        $joinBlockLog->info("Action Network removeTag request payload: " . json_encode($data));

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

    // Callers must hold the per-email JoinService lock — see JoinService::acquireLock().
    // The get/diff/post sequence is otherwise a TOCTOU race.
    public static function updateCustomFields($email, $fields)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Updating " . $email . " in Action Network with fields " . json_encode($fields));

        $diff = $fields;
        try {
            $person = self::getPerson($email);
            if ($person === null) {
                $joinBlockLog->warning("Skipping Action Network updateCustomFields for $email: person does not exist");
                return;
            }
            $currentFields = $person["custom_fields"] ?? [];
            $diff = [];
            foreach ($fields as $key => $value) {
                $current = $currentFields[$key] ?? null;
                // Compare as strings to side-step type differences in the API response
                // (e.g. dates round-tripped through JSON).
                if ((string) $current !== (string) $value) {
                    $diff[$key] = $value;
                }
            }
            if (empty($diff)) {
                $joinBlockLog->info("Skipping Action Network updateCustomFields for $email: all fields already up to date");
                return;
            }
        } catch (\Exception $e) {
            // Pre-check failed (timeout, transient error). Fall through and POST
            // all fields anyway — Action Network treats unchanged custom fields
            // as a no-op, so we preserve correctness and just lose the optimization.
            $joinBlockLog->warning(
                "Action Network updateCustomFields($email): pre-check failed (" . $e->getMessage() .
                ") — falling back to un-optimised path"
            );
            $diff = $fields;
        }

        $client = new Client();

        $data = [
            "person" => [
                "email_addresses" => [
                    [
                        "address" => $email,
                        "primary" => true
                    ]
                ],
                "custom_fields" => $diff,
            ],
        ];

        // TODO: remove after REI debugging complete
        $joinBlockLog->info("Action Network updateCustomFields request payload: " . json_encode($data));

        $client->request(
            "POST",
            "https://actionnetwork.org/api/v2/people/",
            [
                "headers" => [
                    "OSDI-API-Token" => Settings::get("ACTION_NETWORK_API_KEY")
                ],
                "json" => $data,
                "timeout" => self::HTTP_TIMEOUT_SECONDS,
                "connect_timeout" => self::HTTP_TIMEOUT_SECONDS,
            ]
        );
    }
}
