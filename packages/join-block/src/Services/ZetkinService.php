<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Settings;
use GuzzleHttp\Client;

class ZetkinService
{
    public static function signup($data)
    {
        global $joinBlockLog;

        $email = $data['email'];

        $joinBlockLog->info("Adding {$data['email']} to Zetkin");

        $zetkinOrgId = Settings::get("ZETKIN_ORGANISATION_ID");
        $zetkin_form_id = Settings::get("ZETKIN_JOIN_FORM_ID");
        $zetkin_token = Settings::get("ZETKIN_JOIN_FORM_SUBMIT_TOKEN");
        $zetkin_membership_field = Settings::get("ZETKIN_MEMBERSHIP_CUSTOM_FIELD");
        $zetkin_join_date_field = Settings::get("ZETKIN_JOIN_DATE_CUSTOM_FIELD");
        $collect_hear_about_us = Settings::get("COLLECT_HEAR_ABOUT_US");

        $personData = [
            "email" => $email,
        ];

        if ($zetkin_membership_field) {
            $membership = Settings::getMembershipPlan($data["membership"]);
            $label = $membership["label"] ?? "";
            if ($label) {
                $personData[$zetkin_membership_field] = $label;
            }
        }

        if ($zetkin_join_date_field) {
            $personData[$zetkin_join_date_field] = date("Y-m-d");
        }

        if ($collect_hear_about_us) {
            $personData["hear_about_us"] = $data['howDidYouHearAboutUs'];
            $personData["hear_about_us_details"] = $data['howDidYouHearAboutUsDetails'] ?? "";
        }

        $customFieldsConfig = $data['customFieldsConfig'] ?? [];
        foreach ($customFieldsConfig as $customField) {
            $personData[$customField["id"]] = $data[$customField["id"]] ?? "";
        }

        if (!$data['isUpdateFlow']) {
            $personData = array_merge($personData, [
                "email" => $email,
                "first_name" => $data['firstName'],
                "last_name" => $data['lastName'],
                "phone" => $data['phoneNumber'],
                "street_address" => $data["addressLine1"],
                "co_address" => $data["addressLine2"],
                "city" => $data["addressCity"],
                "zip_code" => $data["addressPostcode"],
                "country" => $data["addressCountry"],
            ]);
        }

        $addTags = $data["membershipPlan"]["add_tags"] ?? "";
        $removeTags = $data["membershipPlan"]["remove_tags"] ?? "";

        $addTags = array_map(function ($tag) {
            return trim($tag);
        }, explode(",", $addTags));

        $removeTags = array_map(function ($tag) {
            return trim($tag);
        }, explode(",", $removeTags));

        // Filter out empty tags
        $addTags = array_filter($addTags, function ($tag) {
            return !empty($tag);
        });
        $removeTags = array_filter($removeTags, function ($tag) {
            return !empty($tag);
        });

        // Allow third-party code to modify tags before they're applied
        // Generic filter applies to all services
        $addTags = apply_filters('ck_join_flow_add_tags', $addTags, $data, 'zetkin');
        $removeTags = apply_filters('ck_join_flow_remove_tags', $removeTags, $data, 'zetkin');

        // Service-specific filter for Mailchimp-only customization
        $addTags = apply_filters('ck_join_flow_zetkin_add_tags', $addTags, $data);
        $removeTags = apply_filters('ck_join_flow_zetkin_remove_tags', $removeTags, $data);

        $client = new Client();
        $baseUrl = (Settings::get("ZETKIN_ENVIRONMENT") === "live") ? "https://api.zetk.in/v1" : "http://api.dev.zetkin.org/v1";

        $clientId = Settings::get("ZETKIN_CLIENT_ID");
        $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
        $jwt = Settings::get("ZETKIN_JWT");

        if ($clientId && $clientSecret && $jwt) {
            try {
                self::addPerson($baseUrl, $zetkinOrgId, $clientId, $clientSecret, $jwt, $personData, $addTags, $removeTags);
            } catch (\Exception $e) {
                $joinBlockLog->info("Error adding $email to Zetkin: {$e->getMessage()}");
                throw $e;
            }
            return;
        }

        $response = $client->request(
            "POST",
            "$baseUrl/orgs/$zetkinOrgId/join_forms/$zetkin_form_id/submissions",
            [
                "json" => [
                    "submit_token" => $zetkin_token,
                    "form_data" => $personData
                ],
            ]
        );
        $body = $response->getBody()->getContents();

        $joinBlockLog->info("$email added to Zetkin: $body");
    }

    private static function addPerson($baseUrl, $orgId, $clientId, $clientSecret, $jwtGrant, $personData, $addTags, $removeTags)
    {
        global $joinBlockLog;
        try {
            $accessToken = self::getAccessToken($baseUrl, $clientId, $clientSecret, $jwtGrant);

            $client = new \GuzzleHttp\Client();
            $response = $client->request("POST", "$baseUrl/orgs/$orgId/people", [
                "headers" => [
                    "Authorization" => "Bearer {$accessToken}",
                    "Content-type" => "application/json",
                ],
                "json" => $personData
            ]);
            $responseData = json_decode($response->getBody()->getContents(), true);
            if (!empty($responseData["error"])) {
                throw new \Exception("Could not create person: " . json_encode($responseData["error"]));
            }

            if (empty($responseData["data"])) {
                throw new \Exception("Could not create person: empty response");
            }

            $personId = $responseData["data"]["id"];

            $existingTags = self::getTags($baseUrl, $orgId, $accessToken);

            $addTags[] = "Unconfirmed";
            $addTagIds = [];
            foreach ($addTags as $tag) {
                $existingTag = self::findOrCreateTag($baseUrl, $orgId, $existingTags, $tag, $accessToken);
                $addTagIds[] = $existingTag["id"];
            }

            $removeTagIds = [];
            foreach ($removeTags as $tag) {
                $existingTag = self::findOrCreateTag($baseUrl, $orgId, $existingTags, $tag, $accessToken);
                $removeTagIds[] = $existingTag["id"];
            }

            foreach ($addTagIds as $tagId) {
                $response = $client->request("PUT", "$baseUrl/orgs/$orgId/people/$personId/tags/$tagId", [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                        "Content-type" => "application/json",
                    ],
                ]);
                $responseData = json_decode($response->getBody()->getContents(), true);
                if (!empty($responseData["error"])) {
                    $joinBlockLog->error("Could not tag person: " . json_encode($responseData["error"]));
                }
            }

            foreach ($removeTagIds as $tagId) {
                $response = $client->request("DELETE", "$baseUrl/orgs/$orgId/people/$personId/tags/$tagId", [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                        "Content-type" => "application/json",
                    ],
                    "http_errors" => false
                ]);
                $responseData = json_decode($response->getBody()->getContents(), true);
                if (!empty($responseData["error"])) {
                    $msg = $responseData["error"]["title"] ?? "";
                    if ($msg !== "404 Not Found") {
                        $joinBlockLog->error("Could not untag person: " . json_encode($responseData["error"]));
                    }
                }
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                if ($e->getResponse()->getStatusCode() === 403) {
                    throw new \Exception(
                        "Zetkin API returned 403 Forbidden. This indicates an authentication or " .
                        "authorisation problem — the most likely cause is that the ZETKIN_JWT has " .
                        "expired. Regenerate the JWT grant in the Zetkin console and update the " .
                        "ZETKIN_JWT setting in WordPress. Raw response: " .
                        $e->getResponse()->getBody()->getContents()
                    );
                }
                throw new \Exception("Bad Zetkin response: " . $e->getResponse()->getBody()->getContents());
            }
            throw new \Exception("Request failed: " . $e->getMessage());
        }
    }

    private static function getTags($baseUrl, $orgId, $accessToken)
    {
        $client = new \GuzzleHttp\Client();
        $response = $client->request("GET", "$baseUrl/orgs/$orgId/people/tags", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ]
        ]);
        $responseData = json_decode($response->getBody()->getContents(), true);
        if (!empty($responseData["error"])) {
            throw new \Exception("Could not get tags: " . json_encode($responseData["error"]));
        }

        return $responseData["data"] ?? [];
    }

    private static function findOrCreateTag($baseUrl, $orgId, $tags, $title, $accessToken)
    {
        $matchingTags = array_filter($tags, function ($tag) use ($title) {
            return strtolower($tag['title']) === strtolower($title);
        });

        if (count($matchingTags) >= 1) {
            return array_values($matchingTags)[0];
        }

        $client = new \GuzzleHttp\Client();
        $response = $client->request("POST", "$baseUrl/orgs/$orgId/people/tags", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ],
            "json" => [
                "title" => $title,
            ]
        ]);
        $responseData = json_decode($response->getBody()->getContents(), true);
        if (!empty($responseData["error"])) {
            throw new \Exception("Could not create tag $title: " . json_encode($responseData["error"]));
        }

        if (empty($responseData["data"])) {
            throw new \Exception("Could not create tag $title: empty response");
        }

        return $responseData["data"];
    }

    /**
     * Find a person in Zetkin by email and update their contact details.
     * Used to sync changes made via the Stripe customer portal.
     *
     * @param string      $email         The person's current email
     * @param array       $personData    Fields to update (null values are ignored)
     * @param string|null $previousEmail Previous email if it changed, used as the lookup key
     */
    public static function updatePerson($email, $personData, $previousEmail = null)
    {
        global $joinBlockLog;

        try {
            $clientId = Settings::get("ZETKIN_CLIENT_ID");
            $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
            $jwt = Settings::get("ZETKIN_JWT");
            $baseUrl = (Settings::get("ZETKIN_ENVIRONMENT") === "live") ? "https://api.zetk.in/v1" : "http://api.dev.zetkin.org/v1";
            $orgId = Settings::get("ZETKIN_ORGANISATION_ID");

            if (!$clientId || !$clientSecret || !$jwt) {
                $joinBlockLog->warning("Cannot update person $email in Zetkin - missing OAuth credentials");
                return;
            }

            $accessToken = self::getAccessToken($baseUrl, $clientId, $clientSecret, $jwt);
            $client = new \GuzzleHttp\Client();

            $searchEmail = $previousEmail ?? $email;
            $response = $client->request("POST", "$baseUrl/orgs/$orgId/search/person", [
                "headers" => [
                    "Authorization" => "Bearer {$accessToken}",
                    "Content-type" => "application/json",
                ],
                "json" => ["q" => $searchEmail],
            ]);
            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!empty($responseData["error"])) {
                throw new \Exception(json_encode($responseData["error"]));
            }

            $people = $responseData["data"] ?? [];
            $person = null;
            foreach ($people as $candidate) {
                if ($candidate["email"] === $searchEmail) {
                    $person = $candidate;
                    break;
                }
            }

            if (!$person) {
                $joinBlockLog->warning("Cannot update person in Zetkin - no person found with email $searchEmail");
                return;
            }

            $personId = $person["id"];

            // Include new email if it changed
            if ($previousEmail && $previousEmail !== $email) {
                $personData['email'] = $email;
            }

            $updateData = array_filter($personData, fn($v) => $v !== null && $v !== '');
            if (empty($updateData)) {
                return;
            }

            $response = $client->request("PATCH", "$baseUrl/orgs/$orgId/people/$personId", [
                "headers" => [
                    "Authorization" => "Bearer {$accessToken}",
                    "Content-type" => "application/json",
                ],
                "json" => $updateData,
            ]);
            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!empty($responseData["error"])) {
                throw new \Exception("Could not update person: " . json_encode($responseData["error"]));
            }

            $joinBlockLog->info("Updated person $email in Zetkin (person ID $personId)");
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                if ($e->getResponse()->getStatusCode() === 403) {
                    throw new \Exception(
                        "Zetkin API returned 403 Forbidden updating $email. " .
                        "Check ZETKIN_JWT has not expired. Raw response: " .
                        $e->getResponse()->getBody()->getContents()
                    );
                }
                throw new \Exception("Bad Zetkin response updating $email: " . $e->getResponse()->getBody()->getContents());
            }
            throw new \Exception("Request failed updating $email in Zetkin: " . $e->getMessage());
        }
    }

    /**
     * Standalone function to find a person by email and apply a tag (string)
     */
    public static function addTag($email, $tag)
    {
        global $joinBlockLog;
        try {
            $clientId = Settings::get("ZETKIN_CLIENT_ID");
            $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
            $jwt = Settings::get("ZETKIN_JWT");
            $baseUrl = (Settings::get("ZETKIN_ENVIRONMENT") === "live") ? "https://api.zetk.in/v1" : "http://api.dev.zetkin.org/v1";
            $orgId = Settings::get("ZETKIN_ORGANISATION_ID");

            $accessToken = self::getAccessToken($baseUrl, $clientId, $clientSecret, $jwt);
            $client = new \GuzzleHttp\Client();
            $response = $client->request("POST", "$baseUrl/orgs/$orgId/search/person", [
                "headers" => [
                    "Authorization" => "Bearer {$accessToken}",
                    "Content-type" => "application/json",
                ],
                "json" => [
                    "q" => $email,
                ]
            ]);
            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!empty($responseData["error"])) {
                throw new \Exception(json_encode($responseData["error"]));
            }

            $people = $responseData["data"] ?? [];
            $existingTags = self::getTags($baseUrl, $orgId, $accessToken);
            $existingTag = self::findOrCreateTag($baseUrl, $orgId, $existingTags, $tag, $accessToken);
            foreach ($people as $person) {
                if ($person["email"] !== $email) {
                    continue;
                }
                $personId = $person["id"];
                $tagId = $existingTag["id"];
                $response = $client->request("PUT", "$baseUrl/orgs/$orgId/people/$personId/tags/$tagId", [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                        "Content-type" => "application/json",
                    ]
                ]);
                $responseData = json_decode($response->getBody()->getContents(), true);
                if (!empty($responseData["error"])) {
                    $msg = $responseData["error"]["title"] ?? "";
                    if ($msg !== "404 Not Found") {
                        $joinBlockLog->error("Could not untag person: " . json_encode($responseData["error"]));
                    }
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->error("Could not tag $email in Zetkin with $tag: " . $e->getMessage());
        }
    }

    /**
     * Standalone function to find a person by email and remove a tag (string)
     */
    public static function removeTag($email, $tag)
    {
        global $joinBlockLog;
        try {
            $clientId = Settings::get("ZETKIN_CLIENT_ID");
            $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
            $jwt = Settings::get("ZETKIN_JWT");
            $baseUrl = (Settings::get("ZETKIN_ENVIRONMENT") === "live") ? "https://api.zetk.in/v1" : "http://api.dev.zetkin.org/v1";
            $orgId = Settings::get("ZETKIN_ORGANISATION_ID");

            $accessToken = self::getAccessToken($baseUrl, $clientId, $clientSecret, $jwt);
            $client = new \GuzzleHttp\Client();
            $response = $client->request("POST", "$baseUrl/orgs/$orgId/search/person", [
                "headers" => [
                    "Authorization" => "Bearer {$accessToken}",
                    "Content-type" => "application/json",
                ],
                "json" => [
                    "q" => $email,
                ]
            ]);
            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!empty($responseData["error"])) {
                throw new \Exception(json_encode($responseData["error"]));
            }

            $people = $responseData["data"] ?? [];
            $existingTags = self::getTags($baseUrl, $orgId, $accessToken);
            $existingTag = self::findOrCreateTag($baseUrl, $orgId, $existingTags, $tag, $accessToken);
            foreach ($people as $person) {
                if ($person["email"] !== $email) {
                    continue;
                }
                $personId = $person["id"];
                $tagId = $existingTag["id"];
                $response = $client->request("DELETE", "$baseUrl/orgs/$orgId/people/$personId/tags/$tagId", [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                        "Content-type" => "application/json",
                    ],
                    "http_errors" => false
                ]);
                $responseData = json_decode($response->getBody()->getContents(), true);
                if (!empty($responseData["error"])) {
                    $joinBlockLog->error("Could not tag person: " . json_encode($responseData["error"]));
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->error("Could not tag $email in Zetkin with $tag: " . $e->getMessage());
        }
    }

    private static function getAccessToken($baseUrl, $clientId, $clientSecret, $jwtGrant)
    {
        $client = new \GuzzleHttp\Client();

        $oauthResponse = $client->request('POST', "$baseUrl/oauth/token", [
            'auth' => [$clientId, $clientSecret],
            'form_params' => [
                'assertion' => $jwtGrant,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'scope' => 'level2',
            ],
        ]);

        $oauthData = json_decode($oauthResponse->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Bad Zetkin oauth response: " . $oauthResponse->getBody()->getContents());
        }

        if (isset($oauthData['error'])) {
            $msg = "Zetkin OAuth error: " . json_encode($oauthData);
            throw new \Exception($msg);
        }

        return $oauthData['access_token'];
    }
}
