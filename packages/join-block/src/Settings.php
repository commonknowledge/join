<?php

namespace CommonKnowledge\JoinBlock;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Field\Select_Field;

class Settings
{
    public static function init()
    {
        $gc_environment_select = Field::make('select', 'gc_environment', __('GoCardless Environment'));
        /** @var Select_Field $gc_environment_select */
        $gc_environment_select->set_options(array(
            'Sandbox' => 'sandbox',
            'Live' => 'live',
        ));
        Container::make('theme_options', 'CK Join Block')
            ->add_fields(array(
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
        $val = carbon_get_theme_option($carbon_key);
        if ($val) {
            return $val;
        }
        $env_key = strtoupper($key);
        return $_ENV[$env_key] ?? $val;
    }
}
