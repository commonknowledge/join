<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use MailchimpMarketing\ApiClient;
use CommonKnowledge\JoinBlock\Settings;

class MailchimpService
{
    public static function signup($data)
    {
        global $joinBlockLog;

        $email = $data['email'];

        $joinBlockLog->info("Adding {$data['email']} to Mailchimp");

        $mailchimp_api_key = Settings::get("MAILCHIMP_API_KEY");
        $mailchimp_audience_id = Settings::get("MAILCHIMP_AUDIENCE_ID");
        # Server name (e.g. us22) is at the end of the API key (e.g. ...-us22)
        $array_key_parts = explode("-", $mailchimp_api_key);
        $server = array_pop($array_key_parts);
        $mailchimp = new ApiClient();
        $mailchimp->setConfig([
            'apiKey' => $mailchimp_api_key,
            'server' => $server
        ]);

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
        $addTags = apply_filters('ck_join_flow_add_tags', $addTags, $data, 'mailchimp');
        $removeTags = apply_filters('ck_join_flow_remove_tags', $removeTags, $data, 'mailchimp');
        
        // Service-specific filter for Mailchimp-only customization
        $addTags = apply_filters('ck_join_flow_mailchimp_add_tags', $addTags, $data);
        $removeTags = apply_filters('ck_join_flow_mailchimp_remove_tags', $removeTags, $data);

        if ($data['isUpdateFlow']) {
            $mergeFields = [];
        } else {
            $mergeFields = [
                "FNAME" => $data['firstName'],
                "LNAME" => $data['lastName'],
                "PHONE" => $data['phoneNumber'],
                "ADDRESS" => [
                    "addr1" => $data["addressLine1"],
                    "addr2" => $data["addressLine2"],
                    "city" => $data["addressCity"],
                    "state" => "",
                    "zip" => $data["addressPostcode"],
                    "country" => $data["addressCountry"]
                ]
            ];

            $customFieldsConfig = $data['customFieldsConfig'] ?? [];
            foreach ($customFieldsConfig as $customField) {
                $mergeField = strtoupper(preg_replace('/[^A-Z_]/i', '_', $customField["id"]));
                $mergeFields[$mergeField] = $data[$customField["id"]] ?? "";
            }
        }

        $memberData = [
            "email_address" => $email,
            "status" => "subscribed",
            "merge_fields" => $mergeFields
        ];

        // Add tags if present - use simple array of tag names for addListMember
        if (!empty($addTags)) {
            $memberData["tags"] = array_values($addTags);
        }

        $memberExists = false;
        try {
            $mailchimp->lists->addListMember($mailchimp_audience_id, $memberData);
            $joinBlockLog->info("$email added to Mailchimp");
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $alreadySignedUp = str_contains($e->getMessage(), "Member Exists");
            if ($alreadySignedUp) {
                $joinBlockLog->info("$email already in Mailchimp");
                $memberExists = true;
            } else {
                throw $e;
            }
        }

        // Handle tag updates for existing members or if we need to remove tags
        // For existing members, we need to add tags via updateListMemberTags
        // For new members, we need to remove tags via updateListMemberTags (can't do it in addListMember)
        if ($memberExists || !empty($removeTags)) {
            try {
                $subscriberHash = md5(strtolower($email));
                $tagUpdates = [];
                
                // If member exists, add tags that weren't added during creation
                if ($memberExists && !empty($addTags)) {
                    foreach ($addTags as $tag) {
                        $tagUpdates[] = ["name" => $tag, "status" => "active"];
                    }
                }
                
                // Add tags to remove
                if (!empty($removeTags)) {
                    foreach ($removeTags as $tag) {
                        $tagUpdates[] = ["name" => $tag, "status" => "inactive"];
                    }
                }
                
                if (!empty($tagUpdates)) {
                    $mailchimp->lists->updateListMemberTags(
                        $mailchimp_audience_id,
                        $subscriberHash,
                        ["tags" => $tagUpdates]
                    );
                    $joinBlockLog->info("Updated tags for $email in Mailchimp");
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $joinBlockLog->error("Failed to update tags for $email in Mailchimp: " . $e->getMessage());
                // Don't throw - tag updates are not critical for existing members
            }
        }
    }

    public static function addTag($email, $tag)
    {
        global $joinBlockLog;

        $mailchimp_api_key = Settings::get("MAILCHIMP_API_KEY");
        $mailchimp_audience_id = Settings::get("MAILCHIMP_AUDIENCE_ID");
        # Server name (e.g. us22) is at the end of the API key (e.g. ...-us22)
        $array_key_parts = explode("-", $mailchimp_api_key);
        $server = array_pop($array_key_parts);
        $mailchimp = new ApiClient();
        $mailchimp->setConfig([
            'apiKey' => $mailchimp_api_key,
            'server' => $server
        ]);

        $subscriberHash = md5(strtolower($email));

        try {
            $mailchimp->lists->updateListMemberTags(
                $mailchimp_audience_id,
                $subscriberHash,
                ["tags" => [["name" => $tag, "status" => "active"]]]
            );
            $joinBlockLog->info("Added tag '$tag' to $email in Mailchimp");
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $joinBlockLog->error("Failed to add tag '$tag' to $email in Mailchimp: " . $e->getMessage());
            throw $e;
        }
    }

    public static function removeTag($email, $tag)
    {
        global $joinBlockLog;

        $mailchimp_api_key = Settings::get("MAILCHIMP_API_KEY");
        $mailchimp_audience_id = Settings::get("MAILCHIMP_AUDIENCE_ID");
        # Server name (e.g. us22) is at the end of the API key (e.g. ...-us22)
        $array_key_parts = explode("-", $mailchimp_api_key);
        $server = array_pop($array_key_parts);
        $mailchimp = new ApiClient();
        $mailchimp->setConfig([
            'apiKey' => $mailchimp_api_key,
            'server' => $server
        ]);

        $subscriberHash = md5(strtolower($email));

        try {
            $mailchimp->lists->updateListMemberTags(
                $mailchimp_audience_id,
                $subscriberHash,
                ["tags" => [["name" => $tag, "status" => "inactive"]]]
            );
            $joinBlockLog->info("Removed tag '$tag' from $email in Mailchimp");
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $joinBlockLog->error("Failed to remove tag '$tag' from $email in Mailchimp: " . $e->getMessage());
            throw $e;
        }
    }
}
