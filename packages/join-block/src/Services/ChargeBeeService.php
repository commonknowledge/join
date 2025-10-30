<?php

namespace CommonKnowledge\JoinBlock\Services;

use ChargeBee\ChargeBee\Exceptions\APIError;
use ChargeBee\ChargeBee\Exceptions\InvalidRequestException;
use ChargeBee\ChargeBee\Exceptions\PaymentException;
use ChargeBee\ChargeBee\Models\Customer;
use ChargeBee\ChargeBee\Models\HostedPage;
use ChargeBee\ChargeBee\Models\Subscription;
use CommonKnowledge\JoinBlock\Exceptions\JoinBlockException;
use CommonKnowledge\JoinBlock\Settings;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

class ChargeBeeService
{

    public static function upsertCustomer($data, $billingAddress)
    {
        global $joinBlockLog;

        // Before we create a customer, we check if they exist in Chargebee
        $joinBlockLog->info("Checking Chargebee for existing customers with email address " . $data['email']);
        $existingCustomers = Customer::all(["email[is]" => $data['email']]);

        if (count($existingCustomers) > 0) {
            $joinBlockLog->info(
                "There is more than one customer with this email address (" .
                    $data['email'] . ") - " . count($existingCustomers) .
                    ' in total have this email address'
            );
        }

        // Does this customer have an active subscription? If so return an error informing them of this.
        // Note: only possible for ChargeBee API mode, not Hosted Pages
        if (empty($data["cbHostedPageId"])) {
            foreach ($existingCustomers as $existingCustomer) {
                $all = Subscription::all(array(
                    "customerId[is]" => $existingCustomer->customer()->id,
                    "status[is]" => "active"
                ));

                if (count($all) > 0) {
                    foreach ($all as $customerSubscription) {
                        $joinBlockLog->info(
                            "Customer with ID " . $existingCustomer->customer()->id .
                                " has existing active membership plan: " .
                                $customerSubscription->subscription()->planId
                        );
                    }

                    throw new JoinBlockException('Current customer has an existing and active membership subscription', 25);
                }
            }
        }

        $customerResult = null;
        if ($data["paymentMethod"] === 'creditCard') {
            $joinBlockLog->info('Creating customer with credit or debit card via Chargebee');

            $chargebeeCreditCardPayload = [
                "first_name" => $data['firstName'],
                "last_name" => $data['lastName'],
                "email" => $data['email'],
                "allow_direct_debit" => true,
                "locale" => "en-GB",
                "token_id" => $data['paymentToken'],
                "billing_address" => $billingAddress,
                "phone" => $data['phoneNumber'],
            ];

            if (!empty($data['dob'])) {
                $chargebeeCreditCardPayload["cf_birthdate"] = $data['dob'];
            }

            if ($data['howDidYouHearAboutUs'] !== "Choose an option") {
                $joinBlockLog->info(
                    'Customer has given how did you hear about us details: ' . $data['howDidYouHearAboutUs']
                );
                $chargebeeCreditCardPayload['cf_how_did_you_hear_about_us'] = $data['howDidYouHearAboutUs'];
            } else {
                $joinBlockLog->info('Customer has not given how did you hear about us details');
            }

            try {
                $chargebeeCreditCardPayload = apply_filters('ck_join_flow_pre_chargebee_customer_create', $chargebeeCreditCardPayload);
                $customerResult = Customer::create($chargebeeCreditCardPayload);
            } catch (InvalidRequestException $exception) {
                /*
                This exception is typically caused by the session token issued by Chargebee expiring.

                This token in provided at the beginning of the user's use of the form, but has a very short timespan.

                The instruction to the user in these situations should be to try again.
                As they pass back through the form their session will be restored.
                */
                $joinBlockLog->error(
                    'Chargebee customer creation failed. Customer attempting charge with credit or debit card',
                    ['json' => $exception->getJsonObject()]
                );

                throw new JoinBlockException('Chargebee token expired', 1);
            } catch (\Exception $exception) {
                $joinBlockLog->error(
                    'Chargebee customer creation failed with unknown exception. ' .
                        'Customer attempting charge with credit or debit card',
                    ['exception' => $exception]
                );

                throw new JoinBlockException('Unknown exception', 11);
            }

            $joinBlockLog->info('Credit or debit card customer creation via Chargebee successful');
        }
        return $customerResult;
    }

    public static function createDirectDebitChargebeeCustomer($data, $billingAddress, $subscription)
    {
        global $joinBlockLog;
        $joinBlockLog->info('Creating Direct Debit Chargebee Customer');

        $directDebitChargebeeCustomer = [
            "firstName" => $data['firstName'],
            "lastName" => $data['lastName'],
            "email" => $data['email'],
            "allow_direct_debit" => true,
            "locale" => "en-GB",
            "phone" => $data['phoneNumber'],
            "payment_method" => [
                "type" => "direct_debit",
                "reference_id" => $subscription->id,
            ],
            "billingAddress" => $billingAddress,
        ];

        if (!empty($data['dob'])) {
            $directDebitChargebeeCustomer["cf_birthdate"] = $data['dob'];
        }

        if ($data['howDidYouHearAboutUs'] !== "Choose an option") {
            $directDebitChargebeeCustomer['cf_how_did_you_hear_about_us'] = $data['howDidYouHearAboutUs'];
            $joinBlockLog->info(
                'Customer has given how did you hear about us details: ' .
                    $data['howDidYouHearAboutUs']
            );
        } else {
            $joinBlockLog->info('Customer has not given how did you hear about us details');
        }

        try {
            $directDebitChargebeeCustomer = apply_filters('ck_join_flow_pre_chargebee_customer_create', $data);
            $customerResult = Customer::create($directDebitChargebeeCustomer);
        } catch (\Exception $exception) {
            $joinBlockLog->error('Chargebee customer creation failed. Customer attempting charge with direct debit', ['exception' => $exception]);
            throw new \Exception('Chargebee customer creation failed');
        }

        return $customerResult;
    }

     /**
     * Creates the Chargebee subscription and returns the Plan ID so it can
     * be stored in Auth0
     */
    public static function createChargebeeSubscription($data, $customer)
    {
        global $joinBlockLog;

        $chargebeeSubscriptionPayload = [
            "subscription_items" => []
        ];

        $planId = Settings::getMembershipPlanId($data["membershipPlan"]);
        $chargebeeSubscriptionPayload["subscription_items"] = [
            [
                "item_price_id" => $planId,
                "unit_price" => (float) $data['membershipPlan']['amount'] * 100
            ]
        ];

        // Handle donation amount, which is sent to us in GBP but Chargebee requires in pence
        $joinBlockLog->info('Handling donation');

        // Non-recurring donation
        $hasDonation = ((float) $data['donationAmount']) > 0;
        if ($hasDonation && $data['recurDonation'] === false) {
            $joinBlockLog->info('Setting up non-recurring donation');
            $chargebeeSubscriptionPayload["subscription_items"][] = [
                "item_price_id" => "additional_donation_single",
                "unit_price" => (float) $data['donationAmount'] * 100
            ];
        }

        // Recurring donation
        if ($hasDonation && $data['recurDonation'] === true) {
            $joinBlockLog->info('Setting up recurring donation');
            $chargebeeSubscriptionPayload["subscription_items"][] = [
                "item_price_id" => "additional_donation_monthly",
                "unit_price" => (float) $data['donationAmount'] * 100
            ];
        }

        $joinBlockLog->info('Creating subscription in Chargebee');
        $joinBlockLog->info(
            "Chargebee subcription payload is:\n" .
                wp_json_encode($chargebeeSubscriptionPayload)
        );

        try {
            $subscriptionResult = Subscription::createWithItems(
                $customer->id,
                $chargebeeSubscriptionPayload
            );
        } catch (PaymentException $exception) {
            $joinBlockLog->error('Chargebee subscription failed on payment', ['data' => $exception->getJsonObject()]);

            /*
                Unfortunately, Chargebee errors lack clear error codes, so we have to use pattern matching to
                establish something unambigious.

                See https://apidocs.chargebee.com/docs/api?lang=php#error_codes_list for further details.
            */
            if (strpos($exception->getMessage(), 'Error message: (3001) Insufficient funds.') !== false) {
                throw new \Exception('Chargebee subscription failed. Insufficient funds on charging account', 2);
            }

            if (strpos($exception->getMessage(), 'Error message: (3005) Expired Card.') !== false) {
                throw new \Exception('Chargebee subscription failed. Card has expired', 4);
            }

            $joinBlockLog->error(
                'Chargebee subscription has unknown failure. ' .
                    'Please note this failure so it can be added to error handling',
                ['data' => $exception->getJsonObject()]
            );

            throw new \Exception('Chargebee subscription failed');
        } catch (\Exception $exception) {
            if ($exception instanceof APIError) {
                $joinBlockLog->error('Chargebee subscription failed', ['data' => $exception->getJsonObject()]);
            } else {
                $joinBlockLog->error('Chargebee subscription failed', ['data' => $exception->getMessage()]);
            }

            $joinBlockLog->error('Chargebee reports back unknown exception of type ' . get_class($exception));

            throw new \Exception('Chargebee subscription failed');
        }

        $joinBlockLog->info('Chargebee subscription successful');
        return $chargebeeSubscriptionPayload['planId'] ?? '';
    }

    /**
     * Verifies the Chargebee hosted page completed subscription and returns the Plan ID so it can
     * be stored in Auth0
     */
    public static function getChargebeeHostedPageSubscription($data, $hostedPageId)
    {
        global $joinBlockLog;

        try {
            $hostedPage = HostedPage::retrieve($hostedPageId);
            /** @var HostedPage $page */
            $page = $hostedPage->hostedPage();

            if ($page->state === "succeeded") {
                $joinBlockLog->info("ChargeBee hosted page completed successfully for customer {$data['email']}");
                $content = $page->content();
                return $content->subscription()->id ?? null;
            } else {
                $joinBlockLog->info("ChargeBee hosted page completed successfully for customer {$data['email']}, state: {$page->state}");
            }
        } catch (\Exception $e) {
            $joinBlockLog->info("ChargeBee hosted page error for customer {$data['email']}, message: {$e->getMessage()}");
        }

        throw new JoinBlockException(
            'ChargeBee Hosted Pages payment failed',
            9
        );
    }
}
