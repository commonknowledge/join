<?php

namespace CommonKnowledge\JoinBlock;

use Carbon_Fields\Block;
use Carbon_Fields\Field;
use Carbon_Fields\Container\Block_Container;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Complex_Field;

class Blocks
{
    public static function init()
    {
        self::registerScripts();
        self::registerBlocks();
    }

    private static function registerScripts()
    {
        global $joinBlockLog;

        $directoryName = dirname(__FILE__, 2);

        $joinFormJavascriptBundleLocation = 'build/join-flow/bundle.js';

        $debug = ($_ENV['DEBUG_JOIN_FLOW'] ?? 'false') === 'true';
        if ($debug) {
            $joinBlockLog->warning(
                'DEBUG_JOIN_FLOW environment variable set to true, meaning join form starting in debug mode. ' .
                    'Using local frontend serving from http://localhost:3000/bundle.js for form.'
            );

            wp_enqueue_script(
                'ck-join-block-js',
                "http://localhost:3000/bundle.js",
                [],
                false,
                true
            );
        } else {
            wp_enqueue_script(
                'ck-join-block-js',
                plugins_url($joinFormJavascriptBundleLocation, __DIR__),
                [],
                filemtime("$directoryName/$joinFormJavascriptBundleLocation"),
                true
            );
        }

        add_filter('wp_footer', function () {
            global $post;

            // Only load the script on a page with the block
            $content = $post ? $post->post_content : '';
            if (!str_contains($content, 'join-form-fullscreen-takeover')) {
                wp_dequeue_script('ck-join-block-js');
            }
        });
    }

    private static function registerBlocks()
    {
        /** @var Association_Field $joined_page_association */
        $joined_page_association = Field::make(
            'association',
            'joined_page',
            __('Page to redirect to after joining')
        )->set_required(true);
        $joined_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))->set_max(1);

        /** @var Block_Container $block_container */
        $block_container = Block::make(__('Join Form Fullscreen Takeover'))
            ->add_fields(array(
                $joined_page_association
            ));
        $block_container->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            if (is_multisite()) {
                $currentBlogId = get_current_blog_id();
                $homeUrl = get_home_url($currentBlogId);
            } else {
                $homeUrl = home_url();
            }

            $successRedirect = get_page_link($fields['joined_page'][0]['id']);
            $privacyCopy = $fields['privacy_copy'] ?? '';

            $membership_plans = Settings::get("MEMBERSHIP_PLANS") ?? [];
            $membership_plans_prepared = array_map(function ($plan) {
                return [
                    "value" => sanitize_title($plan["label"]),
                    "label" => $plan["label"],
                    "priceLabel" => $plan["price_label"],
                    "description" => $plan["description"]
                ];
            }, $membership_plans);

            $environment = [
                'HOME_URL' => $homeUrl,
                "WP_REST_API" => get_rest_url(),
                'SUCCESS_REDIRECT' => $successRedirect,
                "ASK_FOR_ADDITIONAL_DONATION" => Settings::get("ASK_FOR_ADDITIONAL_DONATION"),
                'CHARGEBEE_SITE_NAME' => Settings::get('CHARGEBEE_SITE_NAME'),
                "CHARGEBEE_API_PUBLISHABLE_KEY" => Settings::get('CHARGEBEE_API_PUBLISHABLE_KEY'),
                "COLLECT_DATE_OF_BIRTH" => Settings::get("COLLECT_DATE_OF_BIRTH"),
                "CREATE_AUTH0_ACCOUNT" => Settings::get("CREATE_AUTH0_ACCOUNT"),
                "HOME_ADDRESS_COPY" => wpautop(Settings::get("HOME_ADDRESS_COPY")),
                "MEMBERSHIP_PLANS" => $membership_plans_prepared,
                "ORGANISATION_NAME" => Settings::get("ORGANISATION_NAME"),
                "ORGANISATION_BANK_NAME" => Settings::get("ORGANISATION_BANK_NAME"),
                "ORGANISATION_EMAIL_ADDRESS" => Settings::get("ORGANISATION_EMAIL_ADDRESS"),
                "PASSWORD_PURPOSE" => wpautop(Settings::get("PASSWORD_PURPOSE")),
                "PRIVACY_COPY" => wpautop(Settings::get("PRIVACY_COPY")),
                "USE_CHARGEBEE" => Settings::get("USE_CHARGEBEE"),
                "USE_GOCARDLESS" => Settings::get("USE_GOCARDLESS"),
            ];
?>
            <style>
                :root {
                    --ck-join-form-primary-color: <?= Settings::get("THEME_PRIMARY_COLOR") ?>;
                    --ck-join-form-gray-color: <?= Settings::get("THEME_GRAY_COLOR") ?>;
                    --ck-join-form-background-color: <?= Settings::get("THEME_BACKGROUND_COLOR") ?>;
                }
                <?= Settings::get('custom_css') ?>
            </style>
            <script type="application/json" id="env">
                <?php echo json_encode($environment); ?>
            </script>
            <script src="https://js.chargebee.com/v2/chargebee.js"></script>
            <div class="ck-join-form mt-4"></div>
        <?php
        });
    }
}
