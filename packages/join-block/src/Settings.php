<?php

namespace CommonKnowledge\JoinBlock;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Html_Field;
use Carbon_Fields\Field\Select_Field;

const CONTAINER_ID = 'ck_join_flow';

class Settings
{
    public const GET_ADDRESS_IO = 'get_address_io';
    public const IDEAL_POSTCODES = 'ideal_postcodes';

    public static function init()
    {
        /** @var Select_Field $gc_environment_select */
        $gc_environment_select = Field::make('select', 'gc_environment', __('GoCardless Environment'));
        $gc_environment_select->set_options(array(
            'sandbox' => 'Sandbox',
            'live' => 'Live',
        ));
        /** @var Select_Field $postcode_provider_select */
        $postcode_provider_select = Field::make('select', 'postcode_address_provider');
        $postcode_provider_select->set_options(array(
            self::GET_ADDRESS_IO => 'getAddress.io',
            self::IDEAL_POSTCODES => 'ideal-postcodes.co.uk'
        ));
        $membership_plans = self::createMembershipPlansField('membership_plans');

        $fields = [
            Field::make('separator', 'features', 'Features'),
            Field::make('checkbox', 'collect_date_of_birth'),
            Field::make('checkbox', 'collect_phone_and_email_contact_consent')
                ->set_help_text('May or may not be necessary for your organisation to be given this explicit consent'),
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
            Field::make('separator', 'postcodes', 'Postcode Address Providers'),
            $postcode_provider_select,
            Field::make('text', self::IDEAL_POSTCODES . '_api_key', 'Ideal Postcodes API Key'),
            Field::make('text', self::GET_ADDRESS_IO . '_api_key', 'getAddress.io API Key'),
            Field::make('separator', 'webhook'),
            Field::make('text', 'step_webhook_url')->set_help_text('Webhook called after each step of the form'),
            Field::make('text', 'webhook_url', 'Join Complete Webhook URL')->set_help_text('Webhook called after the join process is complete'),
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
        ];

        $fields = apply_filters('ck_join_flow_settings_fields', $fields);

        /** @var Html_Field $logField */
        $logField = Field::make('html', 'ck_join_flow_log_contents');
        $logField->set_html(function () {
            global $joinBlockLogLocation;
            $log = @file_get_contents($joinBlockLogLocation);
            if (!$log) {
                $log = 'Could not load error log. Please contact Common Knowledge support.';
            }
            return "<pre>$log</pre>";
        });
        $fields[] = Field::make('separator', 'ck_join_flow_log', 'CK Join Flow Log');
        $fields[] = $logField;

        Container::make('theme_options', CONTAINER_ID, 'CK Join Block')->add_fields($fields);

        // Add a save hook to connect the webhook URL with a UUID. See Settings::ensureWebhookUrlIsSaved()
        // for an explanation. Also saves the membership plan amounts.
        add_filter('carbon_fields_theme_options_container_saved', function ($_, $container) {
            if ($container && $container->id === 'carbon_fields_container_' . CONTAINER_ID) {
                $webhook_url = Settings::get('webhook_url');
                Settings::ensureWebhookUrlIsSaved($webhook_url);
                $membership_plans = Settings::get('membership_plans');
                Settings::saveMembershipPlans($membership_plans);
            }
        }, 10, 2);
    }

    public static function createMembershipPlansField($name = 'membership_plans')
    {
        /** @var Select_Field $payment_frequency_select */
        $payment_frequency_select = Field::make('select', 'frequency');
        $payment_frequency_select->set_options(array(
            'yearly' => 'Yearly',
            'monthly' => 'Monthly',
            'weekly' => 'Weekly',
        ))->set_default_value('monthly');
        /** @var Select_Field $payment_currency_select */
        $payment_currency_select = Field::make('select', 'currency');
        $payment_currency_select->set_options(array(
            'GBP' => 'GBP (£)',
            'EUR' => 'EUR (€)',
            'USD' => 'USD ($)',
        ))->set_default_value('GBP');
        /** @var Complex_Field $membership_plans */
        $membership_plans = Field::make('complex', $name);
        $membership_plans->add_fields([
            Field::make('text', 'label', "Name")->set_required(true),
            Field::make('text', 'amount', "Price")->set_required(true)->set_attribute('type', 'number')
                ->set_help_text("Price without currency, e.g. 10"),
            Field::make('checkbox', 'allow_custom_amount', 'Allow users to change the amount')
                ->set_help_text('This ignores the above price and requires users to choose the amount to pay.'),
            $payment_frequency_select,
            $payment_currency_select,
            Field::make('text', 'description'),
        ])->set_min(1)->set_required(true);
        return $membership_plans;
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

    // Webhook URLs are stored in the wp_options table, and associated to a UUID.
    // The UUID is sent to the front-end instead of the URL, as it should be
    // kept secret from the user. The below function getWebhookUrl() is used
    // to retrieve the URL from its UUID.
    public static function ensureWebhookUrlIsSaved($webhook_url)
    {
        // Don't save empty URLs
        if (!$webhook_url) {
            return '';
        }
        $webhook_uuid = get_option('ck_join_flow_webhook_uuid_' . $webhook_url);
        if (!$webhook_uuid) {
            $webhook_uuid = wp_generate_uuid4();
            // Save options linking both ways for performance reasons (query by option_name is indexed)
            update_option('ck_join_flow_webhook_uuid_' . $webhook_url, $webhook_uuid);
            update_option('ck_join_flow_webhook_url_' . $webhook_uuid, $webhook_url);
        }
        return $webhook_uuid;
    }

    // Get the UUID associated with this webhook url to send to the frontend
    public static function getWebhookUuid($webhook_url)
    {
        return self::ensureWebhookUrlIsSaved($webhook_url);
    }

    // Get the webhook URL from its associated UUID
    // Used when receiving data from the frontend
    public static function getWebhookUrl($webhook_uuid)
    {
        // Don't fall back to the default Settings::get('webhook_url')
        // This would allow a user to submit to the default webhook by
        // malforming the uuid, which should not be allowed
        return get_option('ck_join_flow_webhook_url_' . $webhook_uuid);
    }

    public static function saveMembershipPlans($membership_plans)
    {
        foreach ($membership_plans as $plan) {
            update_option('ck_join_flow_membership_plan_' . sanitize_title($plan['label']), $plan);
        }
    }

    public static function getMembershipPlan($membership_plan_label)
    {
        return get_option('ck_join_flow_membership_plan_' . sanitize_title($membership_plan_label));
    }
}
