<?php

namespace CommonKnowledge\JoinBlock\Services;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Settings;
use GuzzleHttp\Client;

class ZetkinService
{
    public static function signup($data)
    {
        global $joinBlockLog;

        $email = $data['email'];

        $joinBlockLog->info("Adding {$data['email']} to Zetkin");

        $zetkin_org_id = Settings::get("ZETKIN_ORGANISATION_ID");
        $zetkin_form_id = Settings::get("ZETKIN_JOIN_FORM_ID");
        $zetkin_token = Settings::get("ZETKIN_JOIN_FORM_SUBMIT_TOKEN");
        $zetkin_membership_field = Settings::get("ZETKIN_MEMBERSHIP_CUSTOM_FIELD");
        $zetkin_join_date_field = Settings::get("ZETKIN_JOIN_DATE_CUSTOM_FIELD");
        $collect_hear_about_us = Settings::get("COLLECT_HEAR_ABOUT_US");

        $person_data = [
            "email" => $email,
        ];

        if ($zetkin_membership_field) {
            $membership = Settings::getMembershipPlan($data["membership"]);
            $label = $membership["label"] ?? "";
            if ($label) {
                $person_data[$zetkin_membership_field] = $label;
            }
        }

        if ($zetkin_join_date_field) {
            $person_data[$zetkin_join_date_field] = date("Y-m-d");
        }

        if ($collect_hear_about_us) {
            $person_data["hear_about_us"] = $data['howDidYouHearAboutUs'];
            $person_data["hear_about_us_details"] = $data['howDidYouHearAboutUsDetails'] ?? "";
        }

        $customFieldsConfig = $data['customFieldsConfig'] ?? [];
        foreach ($customFieldsConfig as $customField) {
            $person_data[$customField["id"]] = $data[$customField["id"]] ?? "";
        }

        if (!$data['isUpdateFlow']) {
            $person_data = array_merge($person_data, [
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

        $client = new Client();
        $base_url = (Settings::get("ZETKIN_ENVIRONMENT") === "live") ? "https://api.zetk.in" : "http://api.dev.zetkin.org";
        $response = $client->request(
            "POST",
            "$base_url/v1/orgs/$zetkin_org_id/join_forms/$zetkin_form_id/submissions",
            [
                "json" => [
                    "submit_token" => $zetkin_token,
                    "form_data" => $person_data
                ],
            ]
        );
        $body = $response->getBody()->getContents();

        $joinBlockLog->info("$email added to Zetkin: $body");
    }
}
