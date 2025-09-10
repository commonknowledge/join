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

        try {
            $mailchimp->lists->addListMember($mailchimp_audience_id, [
                "email_address" => $email,
                "status" => "subscribed",
                "merge_fields" => $mergeFields
            ]);
            $joinBlockLog->info("$email added to Mailchimp");
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $alreadySignedUp = str_contains($e->getMessage(), "Member Exists");
            if ($alreadySignedUp) {
                $joinBlockLog->info("$email already in Mailchimp");
            } else {
                throw $e;
            }
        }
    }
}
