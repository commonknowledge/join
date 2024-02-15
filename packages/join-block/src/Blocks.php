<?php

namespace CommonKnowledge\JoinBlock;

use Carbon_Fields\Block;
use Carbon_Fields\Field;
use Carbon_Fields\Container\Block_Container;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Image_Field;

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
            if (!str_contains($content, 'ck-join')) {
                wp_dequeue_script('ck-join-block-js');
            }
        });
    }

    private static function registerBlocks()
    {
        self::registerJoinHeaderBlock();
        self::registerJoinFormBlock();
        self::registerJoinLinkBlock();
    }

    private static function registerJoinHeaderBlock()
    {
        /** @var Image_Field $image_field */
        $image_field = Field::make('image', 'image');
        $image_field->set_value_type('url');
        /** @var Block_Container $join_header_block */
        $join_header_block = Block::make(__('CK Join Page Header'))
            ->add_fields(array(
                Field::make('text', 'title')->set_help_text('Use e.g. [first_name:member] to insert [url_query_parameter:default]'),
                $image_field,
            ));
        $join_header_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            $title = $fields['title'];
            if (str_contains($title, '[') && str_contains($title, ']')) {
                preg_match_all('#\[([^:\]]+)(:[^\]]*)?\]#', $title, $matches);
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $param = $matches[1][$i];
                    $default = $matches[2][$i];
                    if ($default) {
                        $default = substr($default, 1); // remove leading ':'
                    }
                    $value = $_GET[$param] ?? $default;
                    $to_replace = $matches[0][$i];
                    $title = str_replace($to_replace, $value, $title);
                }
            }
            self::echoBlockCss();
        ?>
            <!-- wrap in .ck-join-flow so 'namespaced' styles apply -->
                <div class="ck-join-flow">
                    <div class="ck-join-page-header">
                        <h1><?= $title ?></h1>
                        <img src="<?= $fields['image'] ?>" alt="<?= $fields['title'] ?>">
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
            __('Page to redirect to after joining')
        )->set_required(true);
        $joined_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))->set_max(1);

        /** @var Complex_Field $custom_membership_plans */
        $custom_membership_plans = Field::make('complex', 'custom_membership_plans');
        $custom_membership_plans->add_fields([
            Field::make('text', 'label', "Name")->set_required(true),
            Field::make('text', 'price_label', "Price")->set_required(true)->set_help_text("E.G. £10 per month"),
            Field::make('text', 'description'),
        ])->set_help_text('Leave blank to use the default plans from the settings page.');

        /** @var Block_Container $join_form_block */
        $join_form_block = Block::make(__('CK Join Form'))
            ->add_fields(array(
                Field::make('separator', 'ck_join_form', 'CK Join Form'),
                $joined_page_association,
                Field::make('checkbox', 'ask_for_additional_donation'),
                $custom_membership_plans,
                Field::make('text', 'custom_webhook_url')->set_help_text('Leave blank to use the default webhook from the settings page.')
            ));
        $join_form_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            if (is_multisite()) {
                $currentBlogId = get_current_blog_id();
                $homeUrl = get_home_url($currentBlogId);
            } else {
                $homeUrl = home_url();
            }

            $successRedirect = get_page_link($fields['joined_page'][0]['id']);

            $membership_plans = $fields['custom_membership_plans'] ?? [];
            if (!$membership_plans) {
                $membership_plans = Settings::get("MEMBERSHIP_PLANS") ?? [];
            }

            $membership_plans_prepared = array_map(function ($plan) {
                return [
                    "value" => sanitize_title($plan["label"]),
                    "label" => $plan["label"],
                    "priceLabel" => $plan["price_label"],
                    "description" => $plan["description"]
                ];
            }, $membership_plans);

            $webhook_url = $fields['custom_webhook_url'] ?? '';
            if (!$webhook_url) {
                $webhook_url = Settings::get("WEBHOOK_URL");
            }
            $webhook_uuid = Settings::getWebhookUuid($webhook_url);

            $environment = [
                'HOME_URL' => $homeUrl,
                "WP_REST_API" => get_rest_url(),
                'SUCCESS_REDIRECT' => $successRedirect,
                "ASK_FOR_ADDITIONAL_DONATION" => $fields['ask_for_additional_donation'] ?? false,
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
                "POSTCODE_API_KEY" => Settings::get("POSTCODE_API_KEY"),
                "USE_CHARGEBEE" => Settings::get("USE_CHARGEBEE"),
                "USE_GOCARDLESS" => Settings::get("USE_GOCARDLESS"),
                "WEBHOOK_UUID" => $webhook_uuid ? $webhook_uuid : '',
            ];
            self::echoBlockCss();
?>

            <script type="application/json" id="env">
                <?php echo json_encode($environment); ?>
            </script>
            <script src="https://js.chargebee.com/v2/chargebee.js"></script>
            <div class="ck-join-flow">
                <div class="ck-join-form mt-4"></div>
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
            }
        }, 10, 2);
    }

    private static function registerJoinLinkBlock()
    {
        /** @var Association_Field $join_page_association */
        $join_page_association = Field::make(
            'association',
            'join_page',
            __('Join Us Page (with CK Join Form block)')
        )->set_required(true);
        $join_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))->set_max(1);

        /** @var Block_Container $join_form_block */
        $join_form_block = Block::make(__('CK Join Form Link'))
            ->add_fields(array(
                Field::make('text', 'title'),
                Field::make('rich_text', 'introduction'),
                Field::make('checkbox', 'include_email_input'),
                $join_page_association,
            ));
        $join_form_block->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            $link = get_page_link($fields['join_page'][0]['id']);
            self::echoBlockCss();
        ?>
            <!-- wrap in .ck-join-flow so 'namespaced' styles apply -->
            <?php if ($fields['include_email_input']) : ?>
                <div class="ck-join-flow">
                    <div class="ck-join-form-link">
                        <h2><?= $fields['title'] ?></h2>
                        <div class="ck-join-form-link-intro"><?= wpautop($fields['introduction']) ?></div>
                        <form action="<?= $link ?>" method="get" class="form-group">
                            <label for="ck-join-flow-email" class="form-label">Your email</label>
                            <div class="ck-join-form-link-input">
                                <input type="text" id="ck-join-flow-email" name="email" class="form-control">
                                <button class="btn btn-primary">Join</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else : ?>
                <div class="ck-join-flow">
                    <div class="ck-join-form-link">
                        <h2><?= $fields['title'] ?></h2>
                        <a href="<?= $link ?>"><?= wpautop($fields['introduction']) ?></a>
                    </div>
                </div>
            <?php endif; ?>

        <?php
        });
    }

    private static function echoBlockCss()
    {
        ?>
        <style>
            :root {
                --ck-join-form-primary-color: <?= Settings::get("THEME_PRIMARY_COLOR") ?>;
                --ck-join-form-gray-color: <?= Settings::get("THEME_GRAY_COLOR") ?>;
                --ck-join-form-background-color: <?= Settings::get("THEME_BACKGROUND_COLOR") ?>;
            }

            <?= Settings::get('custom_css') ?>
        </style>
<?php
    }
}
