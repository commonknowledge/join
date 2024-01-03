<?php

use Carbon_Fields\Block;
use Carbon_Fields\Field;

add_action('carbon_fields_register_fields', function () {
    Block::make(__('Join Form Fullscreen Takeover'))
    ->add_fields(array(
        Field::make('association', 'joined_page', __('Page to redirect to after joining'))
            ->set_types(array(
                array(
                    'type' => 'post',
                    'post_type' => 'page',
                ),
            ))
            ->set_max(1)
        ))
    ->set_render_callback(function ($fields, $attributes, $inner_blocks) {
        if (is_multisite()) {
            $currentBlogId = get_current_blog_id();
            $homeUrl = get_home_url($currentBlogId);
        } else {
            $homeUrl = home_url();
        }

        $successRedirect = get_page_link($fields['joined_page'][0]['id']);

        $environment = [
            'HOME_URL' => $homeUrl,
            "WP_REST_API" => get_rest_url(),
            'SUCCESS_REDIRECT' => $successRedirect,
            'CHARGEBEE_SITE_NAME' => $_ENV["CHARGEBEE_SITE_NAME"],
            "CHARGEBEE_API_PUBLISHABLE_KEY" => $_ENV["CHARGEBEE_API_PUBLISHABLE_KEY"],
        ];
        ?>
        <script type="application/json" id="env">
            <?php
            echo json_encode($environment);
            ?>
        </script>
        <script src="https://js.chargebee.com/v2/chargebee.js"></script>
        <div class="mt-4" id="join-form"></div>
        <?php
    });

    Block::make(__('Join Header'))
    ->add_fields(array(
        Field::make('text', 'heading', __('Block Heading')),
        Field::make('text', 'numbers', __('Numbers')),
        Field::make('text', 'slogan', __('Slogan')),
        Field::make('image', 'background_image', __('Background Image'))
    ))
    ->set_render_callback(function ($fields, $attributes, $inner_blocks) {
        ?>
        <div class="jumbotron jumbotron-fluid full-bleed bg-black bg-size-cover bg-position-center" style="background-image: linear-gradient(89.93deg, rgba(33, 37, 41, 0.4) 31.12%, rgba(33, 37, 41, 0) 62.75%), url(<?php echo wp_get_attachment_image_src($fields['background_image'], 'full')[0]; ?>);">
            <div class="container">
                <h1 class="text-bebas-neue text-xl text-white text-no-transform"><?php echo esc_html($fields['heading']); ?></h1>
                <div class="w-50 mt-5 text-white">
                    <div class="text-bebas-neue text-md text-no-transform"><?php echo esc_html($fields['numbers']); ?></div>
                    <div class="text-bebas-neue text-md text-no-transform"><?php echo esc_html($fields['slogan']); ?></div>
                </div>
            </div>
        </div>

        <?php
    });

    Block::make(__('Join Form'))
    ->add_fields(array(
        Field::make('text', 'ready', __('Introduction')),
        Field::make('text', 'instructions', __('Instruction')),
        Field::make('text', 'button_cta', __('Button text')),
        Field::make('association', 'join_page', __('Join page location'))
            ->set_types(array(
                array(
                    'type' => 'post',
                    'post_type' => 'page',
                ),
            ))
            ->set_max(1)
        ))
    ->set_render_callback(function ($fields, $attributes, $inner_blocks) {
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
                            <button type="submit" class="btn btn-primary"><?php echo esc_html($fields['button_cta']); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
    });

    Block::make(__('Membership Benefits'))
    ->add_fields(array(
        Field::make('text', 'title', __('Benefits title'))->set_required(true),
        Field::make('text', 'contact_email', __('Contact Email'))->set_required(true),
        Field::make('complex', 'membership_benefits', __('Benefits'))
            ->add_fields(array(
                Field::make('image', 'benefit_icon', __('Icon')),
                Field::make('text', 'benefit_title', __('Title')),
                Field::make('text', 'benefit_description', __('Description')),
            ))))
    ->set_render_callback(function ($fields, $attributes, $inner_blocks) {
        ?>
            <div class="row">
              <div class="col-lg-6">
                <div class="text-xl mb-45px"><?php echo esc_html($fields['title']); ?></div>
              </div>
              <div class="col-lg-6">
                  <div>
                        <?php foreach ($fields['membership_benefits'] as $benefit) : ?>
                        <div class="d-flex mb-45px">
                            <div class="mr-30px"><img class="membership-benefit-icon" src="<?php echo wp_get_attachment_image_src($benefit['benefit_icon'], 'full')[0]; ?>"/></div>
                            <div>
                                <div class="text-md mb-10px"><?php echo esc_html($benefit['benefit_title']); ?></div>
                                <div class="text-xs"><?php echo esc_html($benefit['benefit_description']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                      <div class="text-xs">
                        <div>Need more information?</div>
                        <div>Email us at <a class="text-decoration-none" href="mailto:<?= $fields['contact_email'] ?>"><?= $fields['contact_email'] ?></a></div>
                      </div>
                   </div>
              </div>
            </div>
        <?php
    });
});


