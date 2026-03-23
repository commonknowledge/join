<?php

/**
 * Stripe price ID → membership plan mapping for the backfill CLI command.
 *
 * Copy this file to backfill-plans.php and replace the example price IDs
 * with the real Stripe live price IDs for your organisation before running:
 *
 *   wp join backfill_stripe_to_zetkin_mailchimp [--dry-run]
 *
 * Keys are Stripe price IDs. Values must include:
 *   slug        — matches the membership plan slug in WP Admin > CK Join Flow > Membership Plans
 *   label       — human-readable plan name
 *   add_tags    — comma-separated tags to add in Zetkin / Mailchimp
 *   remove_tags — comma-separated tags to remove
 *
 * Find price IDs in the Stripe Dashboard under Products > [plan] > Pricing.
 */
return [
    'price_REPLACE_ME_1' => [
        'slug'        => 'example-plan-slug',
        'label'       => 'Example Plan',
        'add_tags'    => 'member, example-tag',
        'remove_tags' => 'other-tag',
    ],
];
