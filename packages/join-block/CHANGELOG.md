# Changelog

## Unreleased

### Stripe webhook sync to Zetkin and Mailchimp

- When a member's Stripe subscription status transitions to lapsed (`unpaid`, `incomplete_expired`) or reactivates (`active`, `trialing`), their membership status is updated in Zetkin and Mailchimp via the existing `toggleMemberLapsed` flow.
- When a member upgrades or downgrades their subscription tier (e.g. low-wage to solidarity), the appropriate tags are added and removed in both Zetkin and Mailchimp. Tags shared across tiers (e.g. `member`) are never removed during a tier change.
- When a member's contact details change in Stripe (`customer.updated`), their profile is synced to Zetkin and Mailchimp.
- Added `Settings::getMembershipPlanByPriceId()` to look up a membership plan by its Stripe price ID.
- Added `JoinService::shouldLapseMember()` and `shouldUnlapseMember()` as filterable decision hooks, with `do_action` hooks on `toggleMemberLapsed` for third-party extensibility.
- Added WP-CLI backfill command (`wp join backfill_stripe_to_zetkin_mailchimp`) to retroactively sync existing Stripe subscribers into Zetkin and Mailchimp.
- Improved `ZetkinService::addTag()` and `removeTag()` logging: success and failure are now logged consistently, 404 responses on tag removal are logged as informational rather than errors.
- Extracted `ZetkinService::getZetkinContext()` and `MailchimpService::getClient()` helpers to eliminate repeated setup code.
