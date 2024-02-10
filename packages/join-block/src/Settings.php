<?php

namespace CommonKnowledge\JoinBlock;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Select_Field;

const CONTAINER_ID = 'ck_join_flow';

class Settings
{
    public static function init()
    {
        /** @var Select_Field $gc_environment_select */
        $gc_environment_select = Field::make('select', 'gc_environment', __('GoCardless Environment'));
        $gc_environment_select->set_options(array(
            'Sandbox' => 'sandbox',
            'Live' => 'live',
        ));
        /** @var Complex_Field $membership_plans */
        $membership_plans = Field::make('complex', 'membership_plans');
        $membership_plans->add_fields([
            Field::make('text', 'label', "Name")->set_required(true),
            Field::make('text', 'price_label', "Price")->set_required(true)->set_help_text("E.G. £10 per month"),
            Field::make('text', 'description'),
        ])->set_min(1)->set_required(true);
        Container::make('theme_options', CONTAINER_ID, 'CK Join Block')
            ->add_fields(array(
                Field::make('separator', 'features', 'Features'),
                Field::make('checkbox', 'collect_date_of_birth'),
                Field::make('checkbox', 'ask_for_additional_donation'),
                Field::make('checkbox', 'create_auth0_account'),
                Field::make('checkbox', 'use_gocardless', 'Use GoCardless'),
                Field::make('checkbox', 'use_chargebee'),
                Field::make('separator', 'membership_plans_sep', 'Membership Plans'),
                $membership_plans,
                Field::make('separator', 'theme', 'Theme'),
                Field::make('color', 'theme_primary_color', 'Primary Color')
                    ->set_help_text("The color of interactive elements, e.g. buttons"),
                Field::make('color', 'theme_gray_color', 'Gray Color')
                    ->set_default_value('#dfdcda')
                    ->set_help_text("The color of de-emphasised elements"),
                Field::make('color', 'theme_background_color', 'Background Color')
                    ->set_default_value('#f4f1ee'),
                Field::make('textarea', 'custom_css'),
                Field::make('separator', 'copy', 'Copy'),
                Field::make('text', 'organisation_name')->set_help_text("The name that will appear on the member's bank statement")
                    ->set_required(true),
                Field::make('text', 'organisation_bank_name')->set_help_text("The name that will appear on the member's bank statement")
                    ->set_required(true),
                Field::make('text', 'organisation_email_address')->set_help_text("The support email for members")
                    ->set_required(true),
                Field::make('rich_text', 'password_purpose')
                    ->set_help_text("E.G. Use this password to log in at https://example.com"),
                Field::make('rich_text', 'home_address_copy')
                    ->set_help_text("E.G. We'll use this to connect you with your local group.."),
                Field::make('rich_text', 'privacy_copy')
                    ->set_help_text("E.G. We will always do our very best to keep the information we hold about you safe and secure."),
                Field::make('separator', 'chargebee', 'Chargebee'),
                Field::make('text', 'chargebee_site_name'),
                Field::make('text', 'chargebee_api_key'),
                Field::make('text', 'chargebee_api_publishable_key'),
                Field::make('separator', 'gocardless', 'GoCardless'),
                Field::make('text', 'gc_access_token'),
                $gc_environment_select,
                Field::make('separator', 'auth0', 'Auth0'),
                Field::make('text', 'auth0_domain')->set_help_text(
                    "The name of the Auth0 site - e.g. example.auth0.com"
                ),
                Field::make('text', 'auth0_client_id')->set_help_text("Machine to machine credentials"),
                Field::make('text', 'auth0_client_secret')->set_help_text("Machine to machine secret"),
                Field::make('text', 'auth0_management_audience')->set_help_text(
                    "Auth0 Management API audience, labelled as Identifier on the " .
                        "API > Auth0 Management API > Settings page"
                ),
            ));
    }

    public static function get($key)
    {
        $carbon_key = strtolower($key);
        $val = carbon_get_theme_option($carbon_key, "carbon_fields_container_" . CONTAINER_ID);
        if ($val) {
            return $val;
        }
        $env_key = strtoupper($key);
        return $_ENV[$env_key] ?? $val;
    }
}
