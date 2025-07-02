<?php

namespace CommonKnowledge\JoinBlock;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use Carbon_Fields\Container;
use Carbon_Fields\Datastore\Empty_Datastore;
use Carbon_Fields\Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Html_Field;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Helper\Helper;
use CommonKnowledge\JoinBlock\Services\StripeService;

const CONTAINER_ID = 'ck_join_flow';

class Settings
{
    public const GET_ADDRESS_IO = 'get_address_io';
    public const IDEAL_POSTCODES = 'ideal_postcodes';

    public static function init()
    {
        /** @var Select_Field $gc_environment_select */
        $gc_environment_select = Field::make('select', 'gc_environment', __('GoCardless Environment', 'common-knowledge-join-flow'));
        $gc_environment_select->set_options(array(
            'sandbox' => 'Sandbox',
            'live' => 'Live',
        ));
        /** @var Select_Field $zetkin_environment_select */
        $zetkin_environment_select = Field::make('select', 'zetkin_environment', __('Zetkin Environment', 'common-knowledge-join-flow'));
        $zetkin_environment_select->set_options(array(
            'sandbox' => 'Sandbox',
            'live' => 'Live',
        ));
        /** @var Select_Field $postcode_provider_select */
        $postcode_provider_select = Field::make('select', 'postcode_address_provider');
        $postcode_provider_select->set_options(array(
            "" => "None",
            self::GET_ADDRESS_IO => 'getAddress.io',
            self::IDEAL_POSTCODES => 'ideal-postcodes.co.uk'
        ));

        // set_required(true) is not applied so that this error can be handled by the custom
        // error handler below, resulting in a more helpful message to the user.
        $membership_plans = self::createMembershipPlansField('membership_plans');

        $feature_fields = [
            Field::make('checkbox', 'collect_date_of_birth'),
            Field::make('checkbox', 'collect_county'),
            Field::make('checkbox', 'collect_phone_and_email_contact_consent')
                ->set_help_text('May or may not be necessary for your organisation to be given this explicit consent'),
            Field::make('checkbox', 'create_auth0_account'),
            Field::make('checkbox', 'use_zetkin', 'Use Zetkin'),
            Field::make('checkbox', 'use_gocardless', 'Use GoCardless'),
            Field::make('checkbox', 'use_gocardless_api', 'Use GoCardless Custom Pages')
                ->set_help_text('Requires a GoCardless Pro account with the custom pages addon'),
            Field::make('checkbox', 'use_chargebee'),
            Field::make('checkbox', 'use_stripe', 'Use Stripe')
                ->set_help_text('Use Stripe as a payment provider'),
            Field::make('checkbox', 'use_action_network', 'Use Action Network')
                ->set_help_text('Save sign-ups in Action Network'),
            Field::make('checkbox', 'use_mailchimp', 'Use Mailchimp')
                ->set_help_text('Save sign-ups in Mailchimp')
        ];

        $membership_plans_fields = [
            Field::make('text', 'lapsed_tag')
                ->set_default_value("Lapsed - failed payment")
                ->set_help_text("Will be applied to members in your CMS if they delete or do not pay their subscription"),
            Field::make('separator', 'membership_plans_sep', 'Membership Plans'),
            $membership_plans,
        ];

        $theme_fields = [
            Field::make('color', 'theme_primary_color', 'Primary Color')
                ->set_default_value('#007bff')
                ->set_help_text("The color of interactive elements, e.g. buttons"),
            Field::make('color', 'theme_gray_color', 'Gray Color')
                ->set_default_value('#dfdcda')
                ->set_help_text("The color of de-emphasised elements"),
            Field::make('color', 'theme_background_color', 'Background Color')
                ->set_default_value('#f4f1ee'),
            Field::make('textarea', 'custom_css'),
        ];

        $copy_fields = [
            Field::make('text', 'organisation_name')->set_help_text("The name that will appear on the member's bank statement")
                ->set_required(true),
            Field::make('text', 'organisation_bank_name')->set_help_text("The name that will appear on the member's bank statement")
                ->set_required(true),
            Field::make('text', 'organisation_email_address')->set_help_text("The support email for members")
                ->set_required(true),
            Field::make('text', 'about_you_heading')
                ->set_default_value("Tell us about you"),
            Field::make('rich_text', 'about_you_copy')
                ->set_default_value("All fields marked with an asterisk (*) are required."),
            Field::make('text', 'date_of_birth_heading')
                ->set_default_value("Date of birth"),
            Field::make('rich_text', 'date_of_birth_copy')
                ->set_default_value("We collect every member's date of birth because our membership types are based on age."),
            Field::make('rich_text', 'password_purpose')
                ->set_help_text("E.G. Use this password to log in at https://example.com"),
            Field::make('rich_text', 'home_address_copy')
                ->set_help_text("E.G. We'll use this to connect you with your local group."),
            Field::make('text', 'custom_fields_heading', 'Form section heading (leave blank for no heading)')->set_default_value("More about you"),
            Field::make('text', 'contact_details_heading')
                ->set_default_value("Contact details"),
            Field::make('rich_text', 'contact_details_copy')
                ->set_default_value("We’ll use this to keep in touch about things that matter to you."),
            Field::make('rich_text', 'contact_consent_copy')
                ->set_default_value("How would you like us to contact you?"),
            Field::make('text', 'hear_about_us_heading', '"How did you hear about us?" heading')->set_default_value("How did you hear about us?"),
            Field::make('text', 'hear_about_us_options', '"How did you hear about us?" options')
                ->set_help_text("Options for the dropdown, comma separated")
                ->set_default_value('From another member, An email from us, Social media, Press/radio, TV, Other'),
            Field::make('text', 'hear_about_us_details', '"How did you hear about us?" additional details textbox label')->set_default_value("Further information"),
            Field::make('rich_text', 'privacy_copy')
                ->set_help_text("E.G. We will always do our very best to keep the information we hold about you safe and secure."),
            Field::make('text', 'membership_tiers_heading')
                ->set_default_value("Choose the plan that’s right for you"),
            Field::make('rich_text', 'membership_tiers_copy')
                ->set_help_text("E.G. Choose tier X if you are Y, otherwise choose tier Z."),
            Field::make('text', 'subscription_day_of_month_copy', 'Only valid for monthly subscriptions')->set_default_value("Day of month to take payment"),
        ];
        $integration_fields = [
            Field::make('separator', 'zetkin', 'Zetkin'),
            Field::make('text', 'zetkin_organisation_id', 'Zetkin Organisation ID')->set_attribute('type', 'number'),
            Field::make('text', 'zetkin_join_form_id', 'Zetkin Join Form ID'),
            Field::make('text', 'zetkin_join_form_submit_token', 'Zetkin Join Form Submission Token'),
            Field::make('text', 'zetkin_membership_custom_field', 'Zetkin Membership Tier Custom Field'),
            $zetkin_environment_select,

            Field::make('separator', 'chargebee', 'Chargebee'),
            Field::make('text', 'chargebee_site_name'),
            Field::make('text', 'chargebee_api_key'),
            Field::make('text', 'chargebee_api_publishable_key'),

            Field::make('separator', 'gocardless', 'GoCardless'),
            Field::make('text', 'gc_access_token'),
            $gc_environment_select,

            Field::make('separator', 'stripe', 'Stripe'),
            Field::make('text', 'stripe_publishable_key', 'Stripe publishable key'),
            Field::make('text', 'stripe_secret_key', 'Stripe secret key'),
            Field::make('checkbox', 'stripe_direct_debit', 'Enable direct debit through Stripe (must be enabled in your Stripe account)'),

            Field::make('separator', 'postcodes', 'Postcode Address Providers'),
            $postcode_provider_select,
            Field::make('text', self::IDEAL_POSTCODES . '_api_key', 'Ideal Postcodes API Key'),
            Field::make('text', self::GET_ADDRESS_IO . '_api_key', 'getAddress.io API Key'),

            Field::make('separator', 'mailchimp', 'Mailchimp'),
            Field::make('text', 'mailchimp_api_key', 'Mailchimp API key')->set_help_text('Instructions here under "Generate an API key": https://eepurl.com/dyijVH'),
            Field::make('text', 'mailchimp_audience_id', 'Mailchimp audience ID')->set_help_text('Instructions here under "Find Your Audience ID": https://eepurl.com/dyilJL'),

            Field::make('separator', 'action_network', 'Action Network'),
            Field::make('text', 'action_network_api_key', 'Action Network API key')->set_help_text('Instructions here: https://help.actionnetwork.org/hc/en-us/articles/203853205-Does-the-Action-Network-have-an-API-and-how-do-I-access-it'),

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

        // The only existing third party use of this filter is for the London Renters Union plugin, which add support for Airtable.
        // This feels like it would file under integrations.
        //
        // Here maintain this compatability, but do not rename the filter to align more precisely for the moment.
        $integration_fields = apply_filters('ck_join_flow_settings_fields', $integration_fields);

        $logging_fields = [];
        /** @var Html_Field $logField */
        $logField = Field::make('html', 'ck_join_flow_log_contents');
        $logField->set_html(function () {
            $joinBlockLogLocation = __DIR__ . "/../logs";
            $logfiles = scandir($joinBlockLogLocation, SCANDIR_SORT_DESCENDING);
            // Ignore file_get_contents error because this will always be a local file
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $log = $logfiles ? @file_get_contents($joinBlockLogLocation . '/' . $logfiles[0]) : "";
            if (!$log) {
                $log = 'Could not load error log. Please contact Common Knowledge support.';
            }
            return "<pre style=\"max-width:150ch\">$log</pre>";
        });
        $logging_fields[] = Field::make('separator', 'ck_join_flow_log', 'CK Join Flow Log');
        $logging_fields[] = $logField;

        CK_Theme_Options_Container::make('theme_options', CONTAINER_ID, 'Join')
            ->add_tab('Features', $feature_fields)
            ->add_tab('Membership Plans', $membership_plans_fields)
            ->add_tab('Theme', $theme_fields)
            ->add_tab('Copy', $copy_fields)
            ->add_tab('Integrations', $integration_fields)
            ->add_tab('Logging', $logging_fields);

        add_filter('carbon_fields_container_is_valid_save', function ($valid, $container) {
            if (!$valid) {
                return false;
            }

            if (!($container instanceof CK_Theme_Options_Container)) {
                return;
            }

            $errors = [];

            $input = Helper::input();

            // Update field values from input before cross-validation
            foreach ($container->get_fields() as $field) {
                $field->set_value_from_input($input);
            }

            //
            $required_credentials = [
                "create_auth0_account" => [
                    "label" => "Auth0",
                    "credentials_fields" => [
                        "auth0_client_id",
                        "auth0_client_secret",
                        "auth0_management_audience"
                    ]
                ],
                "use_zetkin" => [
                    "label" => "Zetkin",
                    "credentials_fields" => [
                        "zetkin_organisation_id",
                        "zetkin_join_form_id",
                        "zetkin_join_form_submit_token",
                    ]
                ],
                "use_gocardless" => [
                    "label" => "GoCardless",
                    "credentials_fields" => [
                        "gc_access_token"
                    ]
                ],
                "use_stripe" => [
                    "label" => "Stripe",
                    "credentials_fields" => [
                        "stripe_publishable_key",
                        "stripe_secret_key"
                    ]
                ],
                "use_chargebee" => [
                    "label" => "ChargeBee",
                    "credentials_fields" => [
                        "chargebee_site_name",
                        "chargebee_api_key",
                        "chargebee_api_publishable_key"
                    ]
                ],
                "use_action_network" => [
                    "label" => "Action Network",
                    "credentials_fields" => [
                        "action_network_api_key"
                    ]
                ],
                "use_mailchimp" => [
                    "label" => "MailChimp",
                    "credentials_fields" => [
                        "mailchimp_audience_id",
                        "mailchimp_api_key"
                    ]
                ]
            ];

            // Cross-validate fields
            foreach ($container->get_fields() as $field) {
                $base_name = $field->get_base_name();
                $value = $field->get_value();

                if ($field->get_base_name() === "membership_plans") {
                    if (!$value) {
                        $errors[] = "You must add at least one membership plan.";
                    }
                }

                if (array_key_exists($base_name, $required_credentials) && $value === "yes") {
                    $validation_config = $required_credentials[$base_name];
                    $credentials_field_names = $validation_config["credentials_fields"];
                    foreach ($credentials_field_names as $credentials_field_name) {
                        $credentials_field = $container->get_field_by_name($credentials_field_name);
                        if (!$credentials_field->get_value()) {
                            $errors[] = $validation_config["label"] . " credentials are missing (see Integrations tab).";
                            break;
                        }
                    }
                }

                if ($base_name === "use_gocardless_api" && $value === "yes") {
                    $use_gocardless_field = $container->get_field_by_name("use_gocardless");
                    if ($use_gocardless_field->get_value() !== "yes") {
                        $errors[] = "Must select Use GoCardless to use GoCardless Custom Pages.";
                    }
                }

                if ($base_name === "postcode_address_provider") {
                    if ($value === self::GET_ADDRESS_IO) {
                        $get_address_io_field = $container->get_field_by_name(self::GET_ADDRESS_IO . '_api_key');
                        if (!$get_address_io_field->get_value()) {
                            $errors[] = "Missing getAddress.io API key.";
                        }
                    }
                    if ($value === self::IDEAL_POSTCODES) {
                        $ideal_postcodes_field = $container->get_field_by_name(self::IDEAL_POSTCODES . '_api_key');
                        if (!$ideal_postcodes_field->get_value()) {
                            $errors[] = "Missing Ideal Postcodes key.";
                        }
                    }
                }
            }

            if (!$errors) {
                return true;
            }

            $container->add_errors($errors);
            return false;
        }, 10, 2);

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

        add_action('ck_join_flow_membership_plan_saved', function ($membershipPlan) {
            global $joinBlockLog;

            if (!Settings::get('USE_STRIPE')) {
                return;
            }

            $joinBlockLog->info('Creating or retrieving membership plan in Stripe', $membershipPlan);

            StripeService::initialise();
            [$newOrExistingProduct, $newOrExistingPrice] = StripeService::createMembershipPlanIfItDoesNotExist($membershipPlan);

            $joinBlockLog->info('Membership plan created or retrieved from Stripe', [
                'product' => $newOrExistingProduct->id,
                'price' => $newOrExistingPrice->id,
            ]);

            $membershipPlan['stripe_product_id'] = $newOrExistingProduct->id;
            $membershipPlan['stripe_price_id'] = $newOrExistingPrice->id;

            $membershipPlanID = sanitize_title($membershipPlan['label']);
            update_option('ck_join_flow_membership_plan_' . $membershipPlanID, $membershipPlan);

            $joinBlockLog->info("Membership plan {$membershipPlanID} saved");

            $joinBlockLog->info('Membership plan retrieved from options', self::getMembershipPlan($membershipPlanID));

            wp_cache_delete('ck_join_flow_membership_plan_' . $membershipPlanID, 'options');
        });
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
                ->set_help_text('This makes the above price a minimum. Not compatible with Stripe.'),
            $payment_frequency_select,
            $payment_currency_select,
            Field::make('text', 'description'),
            Field::make('text', 'add_tags')->set_help_text("Comma-separated tags to add to this member in Action Network."),
            Field::make('text', 'remove_tags')->set_help_text("Comma-separated tags to remove from this member in Action Network.")
        ])->set_min(1);
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
        // Ignore sanitization error as this could break provided environment variables
        // If the environment is compromised, there are bigger problems!
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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

            do_action('ck_join_flow_membership_plan_saved', $plan);
        }
    }

    public static function getMembershipPlan($membership_plan_label)
    {
        return get_option('ck_join_flow_membership_plan_' . sanitize_title($membership_plan_label));
    }
}
