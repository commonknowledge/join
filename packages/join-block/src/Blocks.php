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

        $directoryName = dirname(__FILE__);

        $joinFormJavascriptBundleLocation = 'build/join-flow/bundle.js';

        if ($_ENV['DEBUG_JOIN_FLOW'] === 'true') {
            $joinBlockLog->warning(
                'DEBUG_JOIN_FLOW environment variable set to true, meaning join form starting in debug mode. ' .
                    'Using local frontend serving from http://localhost:3000/bundle.js for form.'
            );

            wp_enqueue_script(
                'join-block-js',
                "http://localhost:3000/bundle.js",
                [],
                false,
                true
            );
        } else {
            wp_enqueue_script(
                'join-block-js',
                plugins_url($joinFormJavascriptBundleLocation, __DIR__),
                [],
                filemtime("$directoryName/$joinFormJavascriptBundleLocation"),
                true
            );
        }
    }

    private static function registerBlocks()
    {
        /** @var Association_Field $joined_page_association */
        $joined_page_association = Field::make(
            'association',
            'joined_page',
            __('Page to redirect to after joining')
        );
        $joined_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))
            ->set_max(1);

        /** @var Block_Container $block_container */
        $block_container = Block::make(__('Join Form Fullscreen Takeover'))
            ->add_fields(array(
                Field::make('rich_text', 'home_address_copy', 'Privacy Copy'),
                Field::make('rich_text', 'privacy_copy', 'Privacy Copy'),
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

            $membership_plans = Settings::get("MEMBERSHIP_PLANS");
            $membership_plans_prepared = array_map(function ($plan) {
                return [
                    "value" => sanitize_title($plan["label"]),
                    "label" => $plan["label"],
                    "priceLabel" => $plan["price_label"],
                    "description" => $plan["description"]
                ];
            }, $membership_plans);
            $a = 3;

            $environment = [
                'HOME_URL' => $homeUrl,
                "WP_REST_API" => get_rest_url(),
                'SUCCESS_REDIRECT' => $successRedirect,
                "ASK_FOR_ADDITIONAL_DONATION" => Settings::get("ASK_FOR_ADDITIONAL_DONATION"),
                'CHARGEBEE_SITE_NAME' => Settings::get('CHARGEBEE_SITE_NAME'),
                "CHARGEBEE_API_PUBLISHABLE_KEY" => Settings::get('CHARGEBEE_API_PUBLISHABLE_KEY'),
                "COLLECT_DATE_OF_BIRTH" => Settings::get("COLLECT_DATE_OF_BIRTH"),
                "CREATE_AUTH0_ACCOUNT" => Settings::get("CREATE_AUTH0_ACCOUNT"),
                "MEMBERSHIP_PLANS" => $membership_plans_prepared,
                "PASSWORD_PURPOSE" => Settings::get("PASSWORD_PURPOSE"),
                "USE_CHARGEBEE" => Settings::get("USE_CHARGEBEE"),
                "USE_GOCARDLESS" => Settings::get("USE_GOCARDLESS"),
            ];
?>
            <script type="application/json" id="env">
                <?php echo json_encode($environment); ?>
            </script>
            <script src="https://js.chargebee.com/v2/chargebee.js"></script>
            <div class="mt-4" id="join-form"></div>
        <?php
        });

        /** @var Block_Container $block_container */
        $block_container = Block::make(__('Join Header'))
            ->add_fields(array(
                Field::make('text', 'heading', __('Block Heading')),
                Field::make('text', 'numbers', __('Numbers')),
                Field::make('text', 'slogan', __('Slogan')),
                Field::make('image', 'background_image', __('Background Image'))
            ));
        $block_container->set_render_callback(function ($fields, $attributes, $inner_blocks) {
            $backgroundImage = wp_get_attachment_image_src($fields['background_image'], 'full')[0] ?? null;
            if ($backgroundImage) {
                $background = (
                    "linear-gradient(89.93deg, rgba(33, 37, 41, 0.4) 31.12%, rgba(33, 37, 41, 0) 62.75%), " .
                    "url($backgroundImage);"
                );
            } else {
                $background = (
                    "linear-gradient(89.93deg, rgba(33, 37, 41, 0.4) 31.12%, rgba(33, 37, 41, 0) 62.75%);"
                );
            }
            $headerClass = "jumbotron jumbotron-fluid full-bleed bg-black bg-size-cover bg-position-center";
        ?>
            <div class="<?= $headerClass ?>" style="background-image: <?= $background ?>">
                <div class="container">
                    <h1 class="text-xl text-white text-no-transform">
                        <?php echo esc_html($fields['heading']); ?>
                    </h1>
                    <div class="w-50 mt-5 text-white">
                        <div class="text-md text-no-transform">
                            <?php echo esc_html($fields['numbers']); ?>
                        </div>
                        <div class="text-md text-no-transform">
                            <?php echo esc_html($fields['slogan']); ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php
        });

        /** @var Association_Field $join_page_association */
        $join_page_association = Field::make('association', 'join_page', __('Join page location'));
        $join_page_association->set_types(array(
            array(
                'type' => 'post',
                'post_type' => 'page',
            ),
        ))
            ->set_max(1);
        /** @var Block_Container $block_container */
        $block_container = Block::make(__('Join Form'))
            ->add_fields(array(
                Field::make('text', 'ready', __('Introduction')),
                Field::make('text', 'instructions', __('Instruction')),
                Field::make('text', 'button_cta', __('Button text')),
                $join_page_association
            ));
        $block_container->set_render_callback(function ($fields, $attributes, $inner_blocks) {
        ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <p><?php echo esc_html($fields['ready']); ?></p>
                    <p><?php echo esc_html($fields['instructions']); ?></p>
                    <form method="GET" action="<?php echo get_permalink($fields['join_page'][0]['id']) ?>">
                        <div class="row">
                            <div class="col-9">
                                <input type="email" id="email" name="email" class="form-control">
                            </div>
                            <div class="col-3">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo esc_html($fields['button_cta']); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php
        });

        /** @var Complex_Field $membership_benefits_field */
        $membership_benefits_field = Field::make('complex', 'membership_benefits', __('Benefits'));
        $membership_benefits_field->add_fields(array(
            Field::make('image', 'benefit_icon', __('Icon')),
            Field::make('text', 'benefit_title', __('Title')),
            Field::make('text', 'benefit_description', __('Description')),
        ));
        /** @var Block_Container $block_container */
        $block_container = Block::make(__('Membership Benefits'))
            ->add_fields(array(
                Field::make('text', 'title', __('Benefits title'))->set_required(true),
                Field::make('text', 'contact_email', __('Contact Email'))->set_required(true),
                $membership_benefits_field
            ));
        $block_container->set_render_callback(function ($fields, $attributes, $inner_blocks) {
        ?>
            <div class="row">
                <div class="col-lg-6">
                    <div class="text-xl mb-45px"><?php echo esc_html($fields['title']); ?></div>
                </div>
                <div class="col-lg-6">
                    <div>
                        <?php foreach ($fields['membership_benefits'] as $benefit) : ?>
                            <?php $icon = wp_get_attachment_image_src($benefit['benefit_icon'], 'full')[0] ?? ""; ?>
                            <div class="d-flex mb-45px">
                                <div class="mr-30px">
                                    <img class="membership-benefit-icon" src="<?= $icon ?>" />
                                </div>
                                <div>
                                    <div class="text-md mb-10px">
                                        <?php echo esc_html($benefit['benefit_title']); ?>
                                    </div>
                                    <div class="text-xs">
                                        <?php echo esc_html($benefit['benefit_description']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-xs">
                            <div>Need more information?</div>
                            <div>
                                Email us at
                                <a class="text-decoration-none" href="mailto:<?= $fields['contact_email'] ?>">
                                    <?= $fields['contact_email'] ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
<?php
        });
    }
}
