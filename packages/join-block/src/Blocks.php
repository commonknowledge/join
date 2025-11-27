<?php

namespace CommonKnowledge\JoinBlock;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use Carbon_Fields\Block;
use Carbon_Fields\Field;
use Carbon_Fields\Container\Block_Container;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Image_Field;

class Blocks
{
    const MINIMAL_BLOCK_MODE = "MINIMAL";
    const NORMAL_BLOCK_MODE = "NORMAL";

    public static function init()
    {
        self::registerScripts();
        self::registerBlocks();

        // Add a save hook to connect the webhook URL with a UUID. See Settings::ensureWebhookUrlIsSaved()
        // for an explanation.
        add_action('save_post', function ($_, $post) {
            $blocks = parse_blocks($post->post_content);
            Blocks::processBlocks($blocks);
        }, 10, 2);
    }

    public static function processBlocks($blocks)
    {
        foreach ($blocks as $block) {
            $custom_webhook_url = $block['attrs']['data']['custom_webhook_url'] ?? '';
            if ($custom_webhook_url) {
                Settings::ensureWebhookUrlIsSaved($custom_webhook_url);
            }
            $custom_membership_plans = $block['attrs']['data']['custom_membership_plans'] ?? [];
            Settings::saveMembershipPlans($custom_membership_plans);
            $innerBlocks = $block["innerBlocks"] ?? [];
            self::processBlocks($innerBlocks);
        }
    }

    private static function registerScripts()
    {
        global $joinBlockLog;

        if (is_admin()) {
            return;
        }

        $directoryName = dirname(__FILE__, 2);

        $joinFormJavascriptBundleLocation = 'build/join-flow/bundle.js';

        // Ignore sanitization error as this could break provided environment variables
        // If the environment is compromised, there are bigger problems!
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $debug = ($_ENV['DEBUG_JOIN_FLOW'] ?? 'false') === 'true';
        if ($debug) {
            $joinBlockLog->warning(
                'DEBUG_JOIN_FLOW environment variable set to true, meaning join form starting in debug mode. ' .
                    'Using local frontend serving from http://localhost:3000/bundle.js for form.'
            );

            wp_enqueue_script(
                'common-knowledge-join-flow-js',
                "http://localhost:3000/bundle.js",
                [],
                time(),
                true
            );
        } else {
            wp_enqueue_script(
                'common-knowledge-join-flow-js',
                plugins_url($joinFormJavascriptBundleLocation, __DIR__),
                [],
                filemtime("$directoryName/$joinFormJavascriptBundleLocation"),
                true
            );
        }

        add_filter('wp_footer', function () {
            global $post;

            // Only load the script on a page with the block
            $content = $post ? apply_filters('the_content', $post->post_content) : '';

            if (!str_contains($content, 'ck-join')) {
                wp_dequeue_script('common-knowledge-join-flow-js');
            }
        });
    }

    private static function registerBlocks()
    {
        self::registerJoinHeaderBlock();
        self::registerJoinFormBlock();
        self::registerJoinLinkBlock();
        self::registerMinimalJoinFormBlock();
    }

    private static function registerJoinHeaderBlock()
    {
        /** @var Image_Field $image_field */
        $image_field = Field::make('image', 'image');
        $image_field->set_value_type('url');
        /** @var Block_Container $join_header_block */
        $join_header_block = Block::make(__('CK Join Page Header', 'common-knowledge-join-flow'))
            ->add_fields(array(
                Field::make('text', 'title')->set_help_text('Use e.g. [first_name:member] to insert [url_query_parameter:default]'),
                $image_field,
            ));
        $join_header_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            $title = $fields['title'];
            if (str_contains($title, '[') && str_contains($title, ']')) {
                // Find and replace all "[value:default]" template markers using query params
                preg_match_all('#\[([^:\]]+)(:[^\]]*)?\]#', $title, $matches);
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $param = $matches[1][$i];
                    $default = $matches[2][$i];
                    if ($default) {
                        $default = substr($default, 1); // remove leading ':'
                    }

                    // Ignore checks that assume $_GET use is form processing, which it is not
                    // (it is only used to complete the title template, which just requires sanitization to be safe)

                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
                    $value = sanitize_text_field($_GET[$param] ?? $default);

                    $to_replace = $matches[0][$i];
                    $title = str_replace($to_replace, $value, $title);
                }
            }
            self::enqueueBlockCss();
?>
            <!-- wrap in .ck-join-flow so 'namespaced' styles apply -->
            <div class="ck-join-flow">
                <div class="ck-join-page-header">
                    <h1><?php echo esc_html($title) ?></h1>
                    <img src="<?php echo esc_url($fields['image']) ?>" alt="<?php echo esc_attr($fields['title']) ?>">
                </div>
            </div>
        <?php
        });
    }

    private static function registerJoinFormBlock()
    {
        /** @var Association_Field $joined_page_association */
        $joined_page_association = Field::make(
            'association',
            'joined_page',
            __('Page to redirect to after joining', 'common-knowledge-join-flow')
        )->set_required(true);
        $joined_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))->set_max(1);

        /** @var Complex_Field $custom_membership_plans */
        $custom_membership_plans = Settings::createMembershipPlansField('custom_membership_plans')
            ->set_help_text('Leave blank to use the default plans from the settings page.');

        $custom_fields = self::createCustomFieldsField();

        /** @var Block_Container $join_form_block */
        $join_form_block = Block::make(__('CK Join Form', 'common-knowledge-join-flow'))
            ->add_fields(array(
                Field::make('separator', 'ck_join_form', 'CK Join Form'),
                $joined_page_association,
                Field::make('checkbox', 'require_address')->set_default_value(true),
                Field::make('checkbox', 'hide_address')
                    ->set_help_text('Check to completely hide the address section from the form.'),
                Field::make('checkbox', 'require_phone_number')->set_default_value(true),
                Field::make('checkbox', 'ask_for_additional_donation'),
                Field::make('checkbox', 'hide_home_address_copy')
                    ->set_help_text('Check to hide the copy that explains why the address is collected.'),
                Field::make('checkbox', 'include_skip_payment_button')
                    ->set_help_text(
                        'Check to include an additional button on the first page to skip to the thank you page ' .
                            '(which could include a form for additional questions)'
                    ),
                Field::make('checkbox', 'is_update_flow', 'Is Update Flow (e.g. for existing members)')
                    ->set_help_text(
                        'Check to skip collecting member details (e.g. name, address). If checked, this page must ' .
                            'be linked to with the email URL search parameter set, e.g. /become-paid-member/?email=someone@example.com. ' .
                            'This can be achieved by using the CK Join Form Link block on a landing page, and linking to this page.'
                    ),
                $custom_fields,
                $custom_membership_plans,
                Field::make('text', 'custom_webhook_url')
                    ->set_help_text('Leave blank to use the default Join Complete webhook from the settings page.'),
                Field::make('text', 'custom_sidebar_heading')
                    ->set_help_text('Leave blank to use the default from settings page.'),
                Field::make('text', 'custom_membership_stage_label')
                    ->set_help_text('Leave blank to use the default from settings page.'),
                Field::make('text', 'custom_joining_verb')
                    ->set_help_text('Leave blank to use the default from settings page (e.g., "Joining").'),

            ));
        $join_form_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            self::enqueueBlockCss();
            self::echoEnvironment($fields, self::NORMAL_BLOCK_MODE);
            if (Settings::get("USE_CHARGEBEE")) {
                wp_enqueue_script("chargebee", "https://js.chargebee.com/v2/chargebee.js", [], "v2", ["in_footer" => false]);
            }
        ?>
            <div class="ck-join-flow">
                <div class="ck-join-form mt-4"></div>
            </div>
        <?php
        });
    }

    private static function createCustomFieldsField()
    {
        /** @var Select_Field $field_type */
        $field_type = Field::make('select', 'field_type');
        $field_type->set_options(array(
            'text' => 'Text',
            'checkbox' => 'Checkbox',
            'number' => 'Number',
            'select' => 'Select',
            'radio' => 'Radio'
        ))->set_default_value('text');
        /** @var Complex_Field $custom_fields */
        $custom_fields = Field::make('complex', 'custom_fields');
        $custom_fields->add_fields([
            Field::make('text', 'label', "Label")->set_required(true)->set_help_text("The label to display to the user."),
            Field::make('text', 'id', "ID")->set_required(true)->set_help_text("The ID or name of the custom field in your membership system."),
            Field::make("checkbox", "required"),
            $field_type,
            Field::make('textarea', 'options', "Options")
                ->set_help_text("The allowed field values (separated by new lines). The 'value : label' format is also supported if required, e.g. <br /><pre>red : Red\nblue : Blue</pre>")
                ->set_conditional_logic([
                [
                    'field' => 'field_type',
                    'value' => ['select', 'radio'],
                    'compare' => 'IN',
                ]
            ]),
            Field::make('rich_text', 'instructions')->set_help_text("Text to display below the field."),
        ]);
        return $custom_fields;
    }

    private static function registerJoinLinkBlock()
    {
        /** @var Association_Field $join_page_association */
        $join_page_association = Field::make(
            'association',
            'join_page',
            __('Join Us Page (with CK Join Form block)', 'common-knowledge-join-flow')
        )->set_required(true);
        $join_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))->set_max(1);

        /** @var Block_Container $join_form_block */
        $join_form_block = Block::make(__('CK Join Form Link', 'common-knowledge-join-flow'))
            ->add_fields(array(
                Field::make('text', 'title'),
                Field::make('rich_text', 'introduction'),
                Field::make('checkbox', 'include_email_input'),
                $join_page_association,
            ));
        $join_form_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            $link = get_page_link($fields['join_page'][0]['id']);
            self::enqueueBlockCss();
        ?>
            <!-- wrap in .ck-join-flow so 'namespaced' styles apply -->
            <?php if ($fields['include_email_input']) : ?>
                <div class="ck-join-flow">
                    <div class="ck-join-form-link">
                        <h2><?php echo esc_html($fields['title']) ?></h2>
                        <div class="ck-join-form-link-intro">
                            <?php echo wp_kses_post(wpautop($fields['introduction'])) ?>
                        </div>
                        <form action="<?php echo esc_url($link) ?>" method="get" class="form-group">
                            <label for="ck-join-flow-email" class="form-label">Your email</label>
                            <div class="ck-join-form-link-input">
                                <input type="text" id="ck-join-flow-email" name="email" class="form-control" required>
                                <button class="btn btn-primary">Join</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else : ?>
                <div class="ck-join-flow">
                    <div class="ck-join-form-link">
                        <a href="<?php echo esc_url($link) ?>">
                            <h2><?php echo esc_html($fields['title']) ?></h2>
                            <?php echo wp_kses_post(wpautop($fields['introduction'])) ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        <?php
        });
    }

    private static function registerMinimalJoinFormBlock()
    {
        /** @var Association_Field $joined_page_association */
        $joined_page_association = Field::make(
            'association',
            'joined_page',
            __('Page to redirect to after joining', 'common-knowledge-join-flow')
        )->set_required(true);
        $joined_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))->set_max(1);

        /** @var Complex_Field $custom_membership_plans */
        $custom_membership_plans = Settings::createMembershipPlansField('custom_membership_plans')
            ->set_help_text('Leave blank to use the default plans from the settings page.');

        /** @var Block_Container $join_form_block */
        $join_form_block = Block::make(__('Minimalist Join Form', 'common-knowledge-join-flow'))
            ->add_fields(array(
                Field::make('separator', 'ck_join_form', 'Minimalist Join Form'),
                $joined_page_association,
                $custom_membership_plans,
                Field::make('text', 'custom_webhook_url')
                    ->set_help_text('Leave blank to use the default Join Complete webhook from the settings page.'),

            ));

        $join_form_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            static::echoEnvironment($fields, self::MINIMAL_BLOCK_MODE);
        ?>
            <div class="ck-minimalist-join-flow ck-join-form">
                <div class="ck-minimalist-join-form"><!-- Minimalist Join Form attaches here --></div>
            </div>
        <?php
        });

        // Add a save hook to connect the webhook URL with a UUID. See Settings::ensureWebhookUrlIsSaved()
        // for an explanation.
        add_action('save_post', function ($_, $post) {
            $blocks = parse_blocks($post->post_content);

            foreach ($blocks as $block) {
                $custom_webhook_url = $block['attrs']['data']['custom_webhook_url'] ?? '';

                if ($custom_webhook_url) {
                    Settings::ensureWebhookUrlIsSaved($custom_webhook_url);
                }

                $custom_membership_plans = $block['attrs']['data']['custom_membership_plans'] ?? [];
                Settings::saveMembershipPlans($custom_membership_plans);
            }
        }, 10, 2);
    }

    /**
     * @param array $fields An associative array of carbon fields set on the Block
     * @param string $block_mode 'NORMAL' or 'MINIMAL', from class constants e.g. Blocks::NORMAL_BLOCK_MODE
     */
    private static function echoEnvironment($fields, $block_mode)
    {
        if (is_multisite()) {
            $currentBlogId = get_current_blog_id();
            $homeUrl = get_home_url($currentBlogId);
        } else {
            $homeUrl = home_url();
        }

        if (!empty($fields['joined_page'][0]['id'])) {
            $successRedirect = get_page_link($fields['joined_page'][0]['id']);
        } else {
            $successRedirect = $homeUrl;
        }

        $membership_plans = $fields['custom_membership_plans'] ?? [];
        if (!$membership_plans) {
            $membership_plans = Settings::get("MEMBERSHIP_PLANS") ?? [];
        }

        $membership_plans_prepared = [];
        foreach ($membership_plans as $plan) {
            $membership_plans_prepared[] = [
                "value" => Settings::getMembershipPlanId($plan),
                "label" => $plan["label"],
                "allowCustomAmount" => $plan["allow_custom_amount"] ?? false,
                "amount" => $plan["amount"],
                "currency" => $plan["currency"],
                "frequency" => $plan["frequency"],
                "description" => $plan["description"]
            ];
        }

        $webhook_url = $fields['custom_webhook_url'] ?? '';
        if (!$webhook_url) {
            $webhook_url = Settings::get("WEBHOOK_URL");
        }
        $webhook_uuid = Settings::getWebhookUuid($webhook_url);

        $use_postcode_lookup = false;
        $postcode_provider = Settings::get('POSTCODE_ADDRESS_PROVIDER');
        if ($postcode_provider === Settings::GET_ADDRESS_IO) {
            $apiKey = Settings::get(Settings::GET_ADDRESS_IO . '_api_key');
            $use_postcode_lookup = (bool) $apiKey;
        } else {
            $apiKey = Settings::get(Settings::IDEAL_POSTCODES . '_api_key');
            $use_postcode_lookup = (bool) $apiKey;
        }

        $hearAboutUsOptions = Settings::get("HEAR_ABOUT_US_OPTIONS") ?? "";
        $hearAboutUsOptions = array_values(
            array_filter(
                array_map(
                    function ($o) {
                        return trim($o);
                    },
                    explode(",", $hearAboutUsOptions)
                ),
                function ($o) {
                    return (bool) $o;
                }
            )
        );

        $custom_fields = array_map(function ($field) {
            $field['instructions'] = wpautop($field['instructions'] ?? '');
            return $field;
        }, $fields['custom_fields'] ?? []);

        // Determine sidebar heading
        $sidebar_heading = $fields['custom_sidebar_heading'] ?? '';
        if (!$sidebar_heading) {
            $sidebar_heading = Settings::get("JOIN_FORM_SIDEBAR_HEADING");
        }

        // Determine membership stage label
        $membership_stage_label = $fields['custom_membership_stage_label'] ?? '';
        if (!$membership_stage_label) {
            $membership_stage_label = Settings::get("MEMBERSHIP_STAGE_LABEL");
        }

        // Determine joining verb
        $joining_verb = $fields['custom_joining_verb'] ?? '';
        if (!$joining_verb) {
            $joining_verb = Settings::get("JOINING_VERB");
        }

        $environment = [
            'HOME_URL' => $homeUrl,
            "WP_REST_API" => get_rest_url(),
            'SUCCESS_REDIRECT' => $successRedirect,
            'ABOUT_YOU_COPY' => wpautop(Settings::get("ABOUT_YOU_COPY")),
            'ABOUT_YOU_HEADING' => Settings::get("ABOUT_YOU_HEADING"),
            "ASK_FOR_ADDITIONAL_DONATION" => $fields['ask_for_additional_donation'] ?? false,
            'CHARGEBEE_SITE_NAME' => Settings::get('CHARGEBEE_SITE_NAME'),
            "CHARGEBEE_API_PUBLISHABLE_KEY" => Settings::get('CHARGEBEE_API_PUBLISHABLE_KEY'),
            "COLLECT_COUNTY" => Settings::get("COLLECT_COUNTY"),
            "COLLECT_DATE_OF_BIRTH" => Settings::get("COLLECT_DATE_OF_BIRTH"),
            "COLLECT_HEAR_ABOUT_US" => Settings::get("COLLECT_HEAR_ABOUT_US"),
            "COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT" => Settings::get("COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT"),
            "CONTACT_CONSENT_COPY" => wpautop(Settings::get("CONTACT_CONSENT_COPY")),
            "CONTACT_DETAILS_COPY" => wpautop(Settings::get("CONTACT_DETAILS_COPY")),
            "CONTACT_DETAILS_HEADING" => Settings::get("CONTACT_DETAILS_HEADING"),
            "CREATE_AUTH0_ACCOUNT" => Settings::get("CREATE_AUTH0_ACCOUNT"),
            "CUSTOM_FIELDS" => $custom_fields ?? [],
            "CUSTOM_FIELDS_HEADING" => Settings::get("CUSTOM_FIELDS_HEADING"),
            'DATE_OF_BIRTH_COPY' => wpautop(Settings::get("DATE_OF_BIRTH_COPY")),
            'DATE_OF_BIRTH_HEADING' => Settings::get("DATE_OF_BIRTH_HEADING"),
            "HEAR_ABOUT_US_DETAILS" => Settings::get("HEAR_ABOUT_US_DETAILS"),
            "HEAR_ABOUT_US_HEADING" => Settings::get("HEAR_ABOUT_US_HEADING"),
            "HEAR_ABOUT_US_OPTIONS" => $hearAboutUsOptions,
            "HIDE_ZERO_PRICE_DISPLAY" => Settings::get("HIDE_ZERO_PRICE_DISPLAY"),
            "HOME_ADDRESS_COPY" => wpautop(Settings::get("HOME_ADDRESS_COPY")),
            "MEMBERSHIP_TIERS_HEADING" => Settings::get("MEMBERSHIP_TIERS_HEADING"),
            "MEMBERSHIP_TIERS_COPY" => wpautop(Settings::get("MEMBERSHIP_TIERS_COPY")),
            "MINIMAL_JOIN_FORM" => $block_mode === self::MINIMAL_BLOCK_MODE,
            "IS_UPDATE_FLOW" => $fields['is_update_flow'] ?? false,
            "INCLUDE_SKIP_PAYMENT_BUTTON" => $fields['include_skip_payment_button'] ?? false,
            "JOIN_FORM_SIDEBAR_HEADING" => $sidebar_heading,
            "JOINING_VERB" => $joining_verb,
            "MEMBERSHIP_PLANS" => $membership_plans_prepared,
            "MEMBERSHIP_STAGE_LABEL" => $membership_stage_label,
            "ORGANISATION_NAME" => Settings::get("ORGANISATION_NAME"),
            "ORGANISATION_BANK_NAME" => Settings::get("ORGANISATION_BANK_NAME"),
            "ORGANISATION_EMAIL_ADDRESS" => Settings::get("ORGANISATION_EMAIL_ADDRESS"),
            "PASSWORD_PURPOSE" => wpautop(Settings::get("PASSWORD_PURPOSE")),
            "POSTCODE_ADDRESS_PROVIDER" => Settings::get("POSTCODE_ADDRESS_PROVIDER"),
            "PRIVACY_COPY" => wpautop(Settings::get("PRIVACY_COPY")),
            "REQUIRE_ADDRESS" => $fields["require_address"] ?? false,
            "HIDE_ADDRESS" => $fields["hide_address"] ?? false,
            "REQUIRE_PHONE_NUMBER" => $fields["require_phone_number"] ?? false,
            "SENTRY_DSN" => Settings::get("SENTRY_DSN"),
            "STRIPE_DIRECT_DEBIT" => Settings::get("STRIPE_DIRECT_DEBIT"),
            "STRIPE_DIRECT_DEBIT_ONLY" => Settings::get("STRIPE_DIRECT_DEBIT_ONLY"),
            "STRIPE_PUBLISHABLE_KEY" => Settings::get("STRIPE_PUBLISHABLE_KEY"),
            "SUBSCRIPTION_DAY_OF_MONTH_COPY" => Settings::get("SUBSCRIPTION_DAY_OF_MONTH_COPY"),
            "USE_CHARGEBEE" => Settings::get("USE_CHARGEBEE"),
            "USE_CHARGEBEE_HOSTED_PAGES" => Settings::get("USE_CHARGEBEE_HOSTED_PAGES"),
            "USE_GOCARDLESS" => Settings::get("USE_GOCARDLESS"),
            "USE_GOCARDLESS_API" => Settings::get("USE_GOCARDLESS_API"),
            "USE_MAILCHIMP" => Settings::get("USE_MAILCHIMP"),
            "USE_POSTCODE_LOOKUP" => $use_postcode_lookup,
            "USE_STRIPE" => Settings::get("USE_STRIPE"),
            "USE_VARIABLE_MEMBERSHIP_PLAN" => $fields['use_variable_membership_plan'] ?? false,
            "WEBHOOK_UUID" => $webhook_uuid ? $webhook_uuid : '',
        ];
        ?>
        <script type="application/json" id="env">
            <?php echo wp_json_encode($environment); ?>
        </script>
<?php
    }

    private static function enqueueBlockCss()
    {
        // Dynamic inline CSS cannot have a fixed version
        wp_register_style("common-knowledge-join-flow-block-css", false, [], time());
        wp_enqueue_style("common-knowledge-join-flow-block-css");

        $inline_style = ":root {\n";
        $primary_color = Settings::get("THEME_PRIMARY_COLOR");
        $gray_color = Settings::get("THEME_GRAY_COLOR");
        $background_color = Settings::get("THEME_BACKGROUND_COLOR");
        if ($primary_color) {
            $inline_style .= "    --ck-join-form-primary-color: $primary_color;\n";
        }
        if ($gray_color) {
            $inline_style .= "    --ck-join-form-gray-color: $gray_color;\n";
        }
        if ($background_color) {
            $inline_style .= "    --ck-join-form-background-color: $background_color;\n";
        }
        $inline_style .= "}\n " . Settings::get('custom_css');

        wp_add_inline_style("common-knowledge-join-flow-block-css", $inline_style);
    }
}
