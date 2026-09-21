<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use CommonKnowledge\JoinBlock\Helpers;
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
            $value = $data[$customField["id"]] ?? "";
            if (
                ($customField["field_type"] ?? "") === "checkbox"
                && !empty($customField["send_as_string"])
            ) {
                $value = $value ? "true" : "false";
            }
            $personData[$customField["id"]] = $value;
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
                if (self::putPersonTag($client, $baseUrl, $orgId, $accessToken, $personId, $tagId) !== 'ok') {
                    $joinBlockLog->error("Could not tag person $personId with tag $tagId in Zetkin");
                }
            }

            foreach ($removeTagIds as $tagId) {
                if (self::deletePersonTag($client, $baseUrl, $orgId, $accessToken, $personId, $tagId) === 'error') {
                    $joinBlockLog->error("Could not remove tag $tagId from person $personId in Zetkin");
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
     * Search Zetkin for a person by email. Returns the person array or null if not found.
     * Only available when OAuth credentials (CLIENT_ID, CLIENT_SECRET, JWT) are configured.
     *
     * @param string $email
     * @return array|null
     */
    public static function findPersonByEmail($email)
    {
        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return null;
        }

        return self::searchPeopleByEmail($zetkinContext, $email)[0] ?? null;
    }

    // The one place Zetkin is searched for a person. Zetkin's search is fuzzy,
    // so the results are filtered down to exact email matches. More than one
    // can come back, because nothing stops Zetkin holding duplicates.
    private static function searchPeopleByEmail($zetkinContext, $email)
    {
        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

        $response = $client->request("POST", "$baseUrl/orgs/$orgId/search/person", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ],
            "json" => ["q" => $email],
        ]);
        $responseData = json_decode($response->getBody()->getContents(), true);

        if (!empty($responseData["error"])) {
            throw new \Exception(json_encode($responseData["error"]));
        }

        $people = $responseData["data"] ?? [];

        return array_values(array_filter($people, fn($p) => $p["email"] === $email));
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

        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return;
        }

        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

        try {
            $searchEmail = $previousEmail ?? $email;
            $person = self::searchPeopleByEmail($zetkinContext, $searchEmail)[0] ?? null;

            if (!$person) {
                $joinBlockLog->warning("Cannot update person in Zetkin - no person found with email $searchEmail");
                return;
            }

            $personId = $person["id"];

            // Include new email if it changed
            if ($previousEmail && $previousEmail !== $email) {
                $personData['email'] = $email;
            }

            $updateData = Helpers::removeNullOrEmpty($personData);
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

    private static function getZetkinContext()
    {
        global $joinBlockLog;

        $clientId = Settings::get("ZETKIN_CLIENT_ID");
        $clientSecret = Settings::get("ZETKIN_CLIENT_SECRET");
        $jwt = Settings::get("ZETKIN_JWT");
        $baseUrl = (Settings::get("ZETKIN_ENVIRONMENT") === "live")
            ? "https://api.zetk.in/v1"
            : "http://api.dev.zetkin.org/v1";
        $orgId = Settings::get("ZETKIN_ORGANISATION_ID");

        if (!$clientId || !$clientSecret || !$jwt) {
            $joinBlockLog->warning("Zetkin API call skipped - missing OAuth credentials");
            return null;
        }

        $accessToken = self::getAccessToken($baseUrl, $clientId, $clientSecret, $jwt);
        $client = new \GuzzleHttp\Client();

        return [
            'baseUrl'     => $baseUrl,
            'orgId'       => $orgId,
            'accessToken' => $accessToken,
            'client'      => $client,
        ];
    }

    // Zetkin pages with p (zero-indexed) and pp (page size); an empty array
    // means the end of the list. Each call opens its own Zetkin context, so a
    // full walk costs one OAuth exchange per page.
    public static function listPeople($page = 0, $perPage = 100)
    {
        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return [];
        }

        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

        $response = $client->request("GET", "$baseUrl/orgs/$orgId/people?p=$page&pp=$perPage", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ]
        ]);
        $responseData = json_decode($response->getBody()->getContents(), true);

        if (!empty($responseData["error"])) {
            throw new \Exception("Could not list people in Zetkin: " . json_encode($responseData["error"]));
        }

        return $responseData["data"] ?? [];
    }

    public static function getPersonTags($personId)
    {
        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return [];
        }

        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

        $response = $client->request("GET", "$baseUrl/orgs/$orgId/people/$personId/tags", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ]
        ]);
        $responseData = json_decode($response->getBody()->getContents(), true);

        if (!empty($responseData["error"])) {
            throw new \Exception("Could not get tags for person $personId in Zetkin: " . json_encode($responseData["error"]));
        }

        return $responseData["data"] ?? [];
    }

    // Public wrapper over the same find-or-create the signup path uses, so
    // bulk jobs tag people with exactly the tags a signup would.
    public static function findOrCreateTagByTitle($title)
    {
        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return null;
        }

        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken] = $zetkinContext;

        $existingTags = self::getTags($baseUrl, $orgId, $accessToken);

        return self::findOrCreateTag($baseUrl, $orgId, $existingTags, $title, $accessToken);
    }

    public static function tryAddTagToPerson($personId, $tagId)
    {
        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return false;
        }

        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

        return self::putPersonTag($client, $baseUrl, $orgId, $accessToken, $personId, $tagId) === 'ok';
    }

    // A tag the person does not have is treated as success, not an error.
    public static function tryRemoveTagFromPerson($personId, $tagId)
    {
        $zetkinContext = self::getZetkinContext();
        if (!$zetkinContext) {
            return false;
        }

        ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

        return self::deletePersonTag($client, $baseUrl, $orgId, $accessToken, $personId, $tagId) !== 'error';
    }

    // Single implementation of "apply this tag to this person".
    // Returns 'ok' or 'error'; callers add their own context to the log.
    private static function putPersonTag($client, $baseUrl, $orgId, $accessToken, $personId, $tagId)
    {
        $response = $client->request("PUT", "$baseUrl/orgs/$orgId/people/$personId/tags/$tagId", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ]
        ]);
        $responseData = json_decode($response->getBody()->getContents(), true);

        return empty($responseData["error"]) ? 'ok' : 'error';
    }

    // Single implementation of "take this tag off this person".
    // Returns 'ok', 'missing' when the person did not have the tag, or 'error'.
    private static function deletePersonTag($client, $baseUrl, $orgId, $accessToken, $personId, $tagId)
    {
        $response = $client->request("DELETE", "$baseUrl/orgs/$orgId/people/$personId/tags/$tagId", [
            "headers" => [
                "Authorization" => "Bearer {$accessToken}",
                "Content-type" => "application/json",
            ],
            "http_errors" => false
        ]);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            return 'missing';
        }

        return $statusCode >= 400 ? 'error' : 'ok';
    }

    /**
     * Standalone function to find a person by email and apply a tag (string)
     */
    public static function addTag($email, $tag)
    {
        self::setTagByEmail($email, $tag, false);
    }

    /**
     * Standalone function to find a person by email and remove a tag (string)
     */
    public static function removeTag($email, $tag)
    {
        self::setTagByEmail($email, $tag, true);
    }

    // Resolves the person and the tag title, then applies the change to every
    // exact email match. Logs and swallows rather than throwing, which is what
    // both callers of addTag and removeTag relied on.
    private static function setTagByEmail($email, $tag, $remove)
    {
        global $joinBlockLog;

        $verb = $remove ? 'remove' : 'add';
        $done = $remove ? 'removed from' : 'added to';

        try {
            $zetkinContext = self::getZetkinContext();
            if (!$zetkinContext) {
                return;
            }

            ['baseUrl' => $baseUrl, 'orgId' => $orgId, 'accessToken' => $accessToken, 'client' => $client] = $zetkinContext;

            $matched = self::searchPeopleByEmail($zetkinContext, $email);
            if (empty($matched)) {
                $joinBlockLog->warning("Could not $verb tag '$tag' in Zetkin: no person found for $email");
                return;
            }

            $existingTags = self::getTags($baseUrl, $orgId, $accessToken);
            $existingTag = self::findOrCreateTag($baseUrl, $orgId, $existingTags, $tag, $accessToken);

            foreach ($matched as $person) {
                $result = $remove
                    ? self::deletePersonTag($client, $baseUrl, $orgId, $accessToken, $person["id"], $existingTag["id"])
                    : self::putPersonTag($client, $baseUrl, $orgId, $accessToken, $person["id"], $existingTag["id"]);

                if ($result === 'error') {
                    $joinBlockLog->error("Could not $verb tag '$tag' for $email in Zetkin");
                } elseif ($result === 'missing') {
                    $joinBlockLog->info("Tag '$tag' was not on $email in Zetkin");
                } else {
                    $joinBlockLog->info("Tag '$tag' $done $email in Zetkin");
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->error("Could not $verb tag '$tag' for $email in Zetkin: " . $e->getMessage());
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
