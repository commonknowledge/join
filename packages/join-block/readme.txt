=== Common Knowledge Join Flow ===
Donate link: https://commonknowledge.coop/
Tags: membership, subscription, join
Contributors: commonknowledgecoop
Requires at least: 5.4
Tested up to: 6.6
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A form for users to sign up to a paid membership for your organisation,
implemented as a WordPress block.

== Description ==

A form for users to sign up to a paid membership for your organisation,
implemented as a WordPress block.

Features:

1. Custom theme and custom CSS
2. Fully customizable membership tiers
3. Three payment providers: 
    1. Stripe
    2. GoCardless
    3. ChargeBee
4. Optional membership integrations:
    1. Auth0
    2. Mailchimp
    3. Action Network

== ChargeBee setup ==

To use ChargeBee, you must configure the payment plan names in the Join Flow settings
to match the price IDs in the ChargeBee back-end.

Currently, this requires logging in to the ChargeBee dashboard, creating a
subscription, and setting a price for the desired currency. The price details
for the selected currency will have an ID. Copy and paste this ID into the
name of the membership plan in the Join Flow backend.

To support an additional donation in ChargeBee, create two more products:
- an addon called "Additional Donation", with a price in the desired
  currency called "additional_donation_monthly".
- a charge called "Additional Donation", with a price in the desired
  currency called "additional_donation_single".

== Contact Us ==

Need help? Contact us at [hello@commonknowledge.coop](mailto:hello@commonknowledge.coop).

== Changelog ==

= 1.0 =
* First release
