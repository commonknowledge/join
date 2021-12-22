<?php

require_once('auth0_service.php');
require_once('gocardless_service.php');

use Carbon\Carbon;


function handle_join($data)
{
    global $joinBlockLog;

    $joinBlockLog->info('Beginning join process');
    
    $billingAddress = [
        "firstName" => $data['firstName'],
        "lastName" => $data['lastName'],
        "line1" => $data['addressLine1'],
        "line2" => $data['addressLine2'],
        "city" => $data['addressCity'],
        "state" => $data['addressCounty'],
        "zip" => $data['addressPostcode'],
        "country" => $data['addressCountry']
    ];
 
    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

    $phoneNumberDetails = $phoneUtil->parse($data['phoneNumber'], $data['addressCountry']);
    $data['phoneNumber'] = $phoneUtil->format($phoneNumberDetails, \libphonenumber\PhoneNumberFormat::E164);
    
    // According to error messages from Chargebee, dates should be sent as the format yyyy-MM-dd.
    // Meaning 2021-12-25 for Christmas Day, 25th of December 2021.
    $dateOfBirth = Carbon::createFromDate($data['dobYear'], $data['dobMonth'], $data['dobDay']);
    $formattedDateOfBirth = $dateOfBirth->toDateString();

    if ($data["paymentMethod"] === 'creditCard') {
        $joinBlockLog->info('Charging credit or debit card via Chargebee');
        $customerResult = ChargeBee_Customer::create([
          "firstName" => $data['firstName'],
          "lastName" => $data['lastName'],
          "email" => $data['email'],
          "allow_direct_debit" => true,
          "locale" => "en-GB",
          "tokenId" => $data['paymentToken'],
          "billingAddress" => $billingAddress,
          "phone" => $data['phoneNumber']
        ]);

        $joinBlockLog->info('Credit or debit card charge via Chargebee successful');
    } elseif ($data['paymentMethod'] === 'directDebit') {
        $joinBlockLog->info('Creating Direct Debit mandate via GoCardless');

        try {
            $mandate = gocardless_create_customer_mandate($data);
        } catch (Exception $expection) {
            $joinBlockLog->error('GoCardless Direct Debit mandate creation failed', ['exception' => $expection]);
            throw new Error('GoCardless Direct Debit mandate creation failed');
        }

        $joinBlockLog->info('Direct Debit mandate via GoCardless successful, creating Chargebee customer');

        try {
            $customerResult = ChargeBee_Customer::create([
                "firstName" => $data['firstName'],
                "lastName" => $data['lastName'],
                "email" => $data['email'],
                "allow_direct_debit" => true,
                "locale" => "en-GB",
                "phone" => $data['phoneNumber'],
                "payment_method" => [
                    "type" => "direct_debit",
                    "reference_id" => $mandate->id,
                ],
                "billingAddress" => $billingAddress,
                "cf_birthdate" => $formattedDateOfBirth
            ]);
        } catch (Exception $expection) {
            $joinBlockLog->error('Chargebee customer creation failed', ['exception' => $expection]);
            throw new Error('Chargebee customer creation failed');
        }
    }

    $customer = $customerResult->customer();

    $chargebeeSubscriptionPayload = [];
    $chargebeeSubscriptionPayload['addons'] = [];

    // "Suggested Member Contribution" has two components in Chargebee and therefore a special treatment.
    // - A monthly recurring donation of £3 a month, the standard plan called "membership_monthly_individual"
    // - An additional donation, in Chargebee an add-on callled "suggested_contribution_month"
    if ($data['planId'] === 'suggested') {
        $joinBlockLog->info('Setting up Suggested Membership Contribution');
        $chargebeeSubscriptionPayload['planId'] = "membership_monthly_individual";

        $chargebeeSubscriptionPayload['addons'][] = [
            "id" => "suggested_contribution_month"
        ];
    } else {
        $chargebeeSubscriptionPayload['planId'] =  $data['planId'];
    }

    // Handle donation amount, which is sent to us in GBP but Chargebee requires in pence
    $joinBlockLog->info('Handling donation');

    // Non-recurring donation
    if ($data['donationAmount'] !== '0' && $data['recurDonation'] === false) {
        $joinBlockLog->info('Setting up non-recurring donation');
        $chargebeeSubscriptionPayload['addons'][] = [
            "id" => "additional_donation_single",
            "unitPrice" => (int)$data['donationAmount'] * 100
        ];
    }

    // Recurring donation
    if ($data['donationAmount'] !== '0' && $data['recurDonation'] === true) {
        $joinBlockLog->info('Setting up recurring donation');
        $chargebeeSubscriptionPayload['addons'][] = [
            "id" => "additional_donation_month",
            "unitPrice" => (int)$data['donationAmount'] * 100
        ];
    }

    $joinBlockLog->info('Creating subscription in Chargebee');
    $joinBlockLog->info("Chargebee subcription payload is:\n" . json_encode($chargebeeSubscriptionPayload));
    
    try {
        $subscriptionResult = ChargeBee_Subscription::createForCustomer($customer->id, $chargebeeSubscriptionPayload);
    } catch (Exception $expection) {
        $joinBlockLog->error('Chargebee subscription failed', ['exception' => $expection]);
        throw new Error('Chargebee subscription failed');
    }

    $joinBlockLog->info('Chargebee subscription successful');

    try {
        createAuth0User($data, $chargebeeSubscriptionPayload['planId'], $customer->id);
    } catch (Exception $expection) {
        $joinBlockLog->error('Auth0 user creation failed', ['exception' => $expection]);
        throw $expection;
    }

    return $customerResult;
}
