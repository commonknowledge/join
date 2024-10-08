<?php

namespace CommonKnowledge\JoinBlock\Services;

use MailchimpMarketing\ApiClient;
use CommonKnowledge\JoinBlock\Settings;

class MailchimpService
{
    public static function signup($email)
    {
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

        try {
            $mailchimp->lists->addListMember($mailchimp_audience_id, [
                "email_address" => $email,
                "status" => "subscribed",
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $alreadySignedUp = str_contains($e->getMessage(), "Member Exists");
            if (!$alreadySignedUp) {
                throw $e;
            }
        }
    }
}
