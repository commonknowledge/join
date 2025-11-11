=== Common Knowledge Join Flow ===
Donate link: https://commonknowledge.coop/
Tags: membership, subscription, join
Contributors: commonknowledgecoop
Requires at least: 5.4
Tested up to: 6.8
Stable tag: 1.3.8
Requires PHP: 8.1
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

== External Services ==

The following is a list of all the external services used by the plugin.
Each service is marked as optional or required.

- getAddress()
Domain: [getaddress.io](https://getaddress.io)
Used for: converting a UK postcode to a full address.
Data received: the postcode provided by the user.

- Ideal Postcodes
Domain: [ideal-postcodes.co.uk](https://ideal-postcodes.co.uk/)
Used for: converting a UK postcode to a full address.
Data received: the postcode provided by the user.

- postcodes.io
Domain: [postcodes.io](https://postcodes.io)
Used for: fetching useful data about a postcode that is then sent to your organisation's membership list.
Data received: the postcode provided by the user.

- ChargeBee
Domain: [chargebee.com](https://www.chargebee.com/)
Used for: managing user payments and subscriptions.
Data received: user payment information, i.e. credit/debit card details or bank account details.

- Stripe
Domain: [stripe.com](https://stripe.com/)
Used for: managing user payments and subscriptions.
Data received: user payment information, i.e. credit/debit card details or bank account details.

- GoCardless
Domain: [gocardless.com](https://gocardless.com/)
Used for: managing user direct debit subscriptions.
Data received: user bank account details.

- Action Network
Domain: [actionnetwork.org](https://actionnetwork.org/)
Used for: storing user membership details.
Data received: all data provided by the user.

- Mailchimp
Domain: [mailchimp.com](https://mailchimp.com/)
Used for: storing user membership details.
Data received: all data provided by the user.

- Auth0
Domain: [auth0.com](https://auth0.com/)
Used for: giving users the ability to modify their data.
Data received: the user's name, email, password and payment subscription ID.

- Zetkin
Domain: [zetkin.org](https://zetkin.org/)
Used for: storing user membership details.
Data received: all data provided by the user.

== Contact Us ==

Need help? Contact us at [hello@commonknowledge.coop](mailto:hello@commonknowledge.coop).

== Changelog ==

= 1.3.8 =
* Added support for hooks that run on lookup of email addresses.
= 1.3.7 =
* Bug: further logical bug fixes.
= 1.3.6 =
* Bug: Fix problem with displaying GoCardless logo, even when payment provider is Stripe.
= 1.3.5 =
* Everything added in Git up to this date.
* Added support for enabling only Direct Debit on Stripe.
= 1.3 =
* Added ChargeBee hosted pages support.

= 1.2 =
* Added Zetkin join form integration.

= 1.1 =
* Added GoCardless webhook endpoint to handle users who did not allow the redirect back
  to the WordPress site to complete.

= 1.0 =
* First release
