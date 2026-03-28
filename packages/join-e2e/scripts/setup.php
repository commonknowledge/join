<?php
/**
 * E2E test setup script.
 *
 * Creates two test pages:
 *   - e2e-standard-join: standard £5/month membership
 *   - e2e-free-join:     free (£0/month) membership
 *
 * Run via:
 *   wp-env run tests-cli wp eval-file /var/www/html/wp-content/e2e-scripts/setup.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build the serialised Gutenberg block content for a CK Join Form block.
 * The $fields array is passed verbatim as the block's "data" attribute,
 * which is what Carbon_Fields\Container\Block_Container::render_block()
 * forwards to the render callback as its first ($fields) argument.
 *
 * @param array $plans    membership/donation tier plans
 * @param array $overrides additional block-level field overrides (e.g. donation_supporter_mode)
 */
function ck_e2e_make_block_content(array $plans, array $overrides = []): string
{
    $data = array_merge(
        [
            'custom_membership_plans'  => $plans,
            'require_address'          => true,
            'require_phone_number'     => true,
            'ask_for_additional_donation' => false,
        ],
        $overrides
    );
    $attrs = wp_json_encode(['data' => $data]);
    return "<!-- wp:carbon-fields/ck-join-form {$attrs} -->\n"
        . '<div class="ck-join-flow"><div class="ck-join-form mt-4"></div></div>' . "\n"
        . '<!-- /wp:carbon-fields/ck-join-form -->';
}

/**
 * Create or update a page by post_name slug.
 * Returns the page ID on success; exits with status 1 on failure.
 */
function ck_e2e_upsert_page(string $slug, string $title, string $content): int
{
    $existing = get_posts([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'name'        => $slug,
        'numberposts' => 1,
    ]);

    if ($existing) {
        $page_id = $existing[0]->ID;
        wp_update_post(['ID' => $page_id, 'post_content' => $content]);
        echo "Updated page '{$slug}' (ID: {$page_id}).\n";
        return $page_id;
    }

    $page_id = wp_insert_post([
        'post_name'    => $slug,
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => $content,
    ], true);

    if (is_wp_error($page_id)) {
        echo 'Failed to create page \'' . $slug . '\': ' . $page_id->get_error_message() . "\n";
        exit(1);
    }

    echo "Created page '{$slug}' (ID: {$page_id}).\n";
    return $page_id;
}

// Configure pretty permalinks so test URLs are predictable.
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(true);

// Standard membership plan (£5/month).
$standard_plans = [
    [
        '_type'              => '',
        'label'              => 'Standard',
        'id'                 => 'standard',
        'amount'             => '5',
        'allow_custom_amount' => '',
        'frequency'          => 'monthly',
        'currency'           => 'GBP',
        'description'        => '',
        'add_tags'           => '',
        'remove_tags'        => '',
    ],
];

// Free membership plan (£0/month).
$free_plans = [
    [
        '_type'              => '',
        'label'              => 'Free',
        'id'                 => 'free',
        'amount'             => '0',
        'allow_custom_amount' => '',
        'frequency'          => 'monthly',
        'currency'           => 'GBP',
        'description'        => '',
        'add_tags'           => '',
        'remove_tags'        => '',
    ],
];

// Supporter mode donation tiers (£5, £10, £20/month).
$supporter_plans = [
    [
        '_type'               => '',
        'label'               => 'Supporter',
        'id'                  => 'supporter',
        'amount'              => '5',
        'allow_custom_amount' => '',
        'frequency'           => 'monthly',
        'currency'            => 'GBP',
        'description'         => '',
        'add_tags'            => '',
        'remove_tags'         => '',
    ],
    [
        '_type'               => '',
        'label'               => 'Friend',
        'id'                  => 'friend',
        'amount'              => '10',
        'allow_custom_amount' => '',
        'frequency'           => 'monthly',
        'currency'            => 'GBP',
        'description'         => '',
        'add_tags'            => '',
        'remove_tags'         => '',
    ],
    [
        '_type'               => '',
        'label'               => 'Patron',
        'id'                  => 'patron',
        'amount'              => '20',
        'allow_custom_amount' => '',
        'frequency'           => 'monthly',
        'currency'            => 'GBP',
        'description'         => '',
        'add_tags'            => '',
        'remove_tags'         => '',
    ],
];

// Supporter mode plan with custom amount allowed.
$supporter_custom_plans = [
    [
        '_type'               => '',
        'label'               => 'Custom',
        'id'                  => 'custom',
        'amount'              => '5',
        'allow_custom_amount' => '1',
        'frequency'           => 'monthly',
        'currency'            => 'GBP',
        'description'         => '',
        'add_tags'            => '',
        'remove_tags'         => '',
    ],
];

// Donation upsell plan (standard £5/month with upsell enabled).
$standard_upsell_plans = $standard_plans;

$standard_page_id = ck_e2e_upsert_page(
    'e2e-standard-join',
    'E2E Standard Join Test',
    ck_e2e_make_block_content($standard_plans)
);

$free_page_id = ck_e2e_upsert_page(
    'e2e-free-join',
    'E2E Free Membership Test',
    ck_e2e_make_block_content($free_plans)
);

// Donation upsell page: standard join with ask_for_additional_donation enabled.
$donation_upsell_page_id = ck_e2e_upsert_page(
    'e2e-donation-upsell',
    'E2E Donation Upsell Test',
    ck_e2e_make_block_content($standard_upsell_plans, ['ask_for_additional_donation' => true])
);

// Supporter mode page: donation-first flow with preset tiers.
$supporter_page_id = ck_e2e_upsert_page(
    'e2e-supporter',
    'E2E Supporter Mode Test',
    ck_e2e_make_block_content($supporter_plans, ['donation_supporter_mode' => true])
);

// Supporter mode page with a custom-amount tier.
$supporter_custom_page_id = ck_e2e_upsert_page(
    'e2e-supporter-custom',
    'E2E Supporter Custom Amount Test',
    ck_e2e_make_block_content($supporter_custom_plans, ['donation_supporter_mode' => true])
);

// Supporter mode page with no plans (to trigger the "no amounts configured" warning).
$supporter_no_plans_page_id = ck_e2e_upsert_page(
    'e2e-supporter-no-plans',
    'E2E Supporter No Plans Test',
    ck_e2e_make_block_content([], ['donation_supporter_mode' => true])
);

// Persist URLs as options so get-page-url.sh can retrieve them.
update_option('ck_e2e_standard_page_url', get_permalink($standard_page_id));
update_option('ck_e2e_free_page_url', get_permalink($free_page_id));
update_option('ck_e2e_donation_upsell_page_url', get_permalink($donation_upsell_page_id));
update_option('ck_e2e_supporter_page_url', get_permalink($supporter_page_id));
update_option('ck_e2e_supporter_custom_page_url', get_permalink($supporter_custom_page_id));
update_option('ck_e2e_supporter_no_plans_page_url', get_permalink($supporter_no_plans_page_id));

echo 'Standard page URL: '           . get_permalink($standard_page_id)          . "\n";
echo 'Free page URL: '                . get_permalink($free_page_id)               . "\n";
echo 'Donation upsell page URL: '     . get_permalink($donation_upsell_page_id)    . "\n";
echo 'Supporter page URL: '           . get_permalink($supporter_page_id)          . "\n";
echo 'Supporter custom page URL: '    . get_permalink($supporter_custom_page_id)   . "\n";
echo 'Supporter no-plans page URL: '  . get_permalink($supporter_no_plans_page_id) . "\n";
echo "Setup complete.\n";
