<?php

namespace CommonKnowledge\JoinBlock\Services;

use Carbon\Carbon;
use ChargeBee\ChargeBee\Exceptions\APIError;
use ChargeBee\ChargeBee\Exceptions\InvalidRequestException;
use ChargeBee\ChargeBee\Exceptions\PaymentException;
use ChargeBee\ChargeBee\Models\Customer;
use ChargeBee\ChargeBee\Models\Subscription;
use CommonKnowledge\JoinBlock\Exceptions\JoinBlockException;
use CommonKnowledge\JoinBlock\Settings;

class JoinService
{
    // According to error messages from Chargebee, dates should be sent as the format yyyy-MM-dd.
    // Meaning 2021-12-25 for Christmas Day, 25th of December 2021.
    private static function formatDateForChargebee($day, $month, $year)
    {
        return Carbon::createFromDate($year, $month, $day)->toDateString();
    }

    public static function handleJoin($data)
    {
        try {
            $chargebeeCustomer = self::tryHandleJoin($data);
            do_action('ck_join_flow_success', $data, $chargebeeCustomer);
        } catch (\Exception $e) {
            do_action('ck_join_flow_error', $data, $e);
            throw $e;
        }
        return $chargebeeCustomer;
    }

    /**
     * Attempts to send the user data to configured 3rd party services.
     * Returns the Chargebee customer, if Chargebee is enabled.
     */
    private static function tryHandleJoin($data)
    {
        global $joinBlockLog;

        $joinBlockLog->info('Beginning join process');

        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

        $phoneNumberDetails = $phoneUtil->parse($data['phoneNumber'], $data['addressCountry']);
        $data['phoneNumber'] = $phoneUtil->format($phoneNumberDetails, \libphonenumber\PhoneNumberFormat::E164);

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

        $customerResult = null;

        $useChargebee = Settings::get('USE_CHARGEBEE');
        if ($useChargebee) {
            $customerResult = self::handleChargebee($data, $billingAddress);
        }

        if (Settings::get('USE_GOCARDLESS')) {
            $mandate = self::handleGocardless($data);
            if ($mandate && $useChargebee) {
                $customerResult = self::createDirectDebitChargebeeCustomer($data, $billingAddress, $mandate);
            }
        }

        $subscriptionPlanId = '';
        if ($useChargebee && $customerResult) {
            $customer = $customerResult->customer();
            $subscriptionPlanId = self::handleChargebeeSubscription($data, $customer);
        }

        if (Settings::get("CREATE_AUTH0_ACCOUNT")) {
            try {
                Auth0Service::createAuth0User($data, $subscriptionPlanId, $customer->id);
            } catch (\Exception $exception) {
                $joinBlockLog->error('Auth0 user creation failed', ['exception' => $exception]);
                throw $exception;
            }
        }

        $webhookUuid = $data['webhookUuid'] ?? '';
        if ($webhookUuid) {
            $webhookUrl = Settings::getWebhookUrl($webhookUuid);
            if ($webhookUrl) {
                $webhookData = apply_filters('ck_join_flow_pre_webhook_post', [
                    "headers" => [
                        'Content-Type' => 'application/json',
                    ],
                    "body" => json_encode($data)
                ]);
                $webhookResponse = wp_remote_post($webhookUrl, $webhookData);
                if ($webhookResponse instanceof \WP_Error) {
                    $error = $webhookResponse->get_error_message();
                    $joinBlockLog->error('Webhook ' . $webhookUrl . ' failed: ' . $error);
                    throw new \Exception($error);
                }
            }
        }

        return $customerResult ? $customerResult->customer() : null;
    }

    private static function handleChargebee($data, $billingAddress)
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
        foreach ($existingCustomers as $existingCustomer) {
            $all = Subscription::all(array(
                "customerId[is]" => $existingCustomer->customer()->id,
                "status[is]" => "active",
                "planId[startsWith]" => "membership"
            ));

            if (count($all) > 0) {
                foreach ($all as $customerSubscription) {
                    $joinBlockLog->info(
                        "Customer with ID " . $existingCustomer->customer()->id .
                            " has existing active membership plan: " .
                            $customerSubscription->subscription()->planId
                    );
                }

                throw new \Error('Current customer has an existing and active membership subscription', 25);
            }
        }

        $customerResult = null;
        if ($data["paymentMethod"] === 'creditCard') {
            $joinBlockLog->info('Creating customer with credit or debit card via Chargebee');

            $chargebeeCreditCardPayload = [
                "firstName" => $data['firstName'],
                "lastName" => $data['lastName'],
                "email" => $data['email'],
                "allow_direct_debit" => true,
                "locale" => "en-GB",
                "tokenId" => $data['paymentToken'],
                "billingAddress" => $billingAddress,
                "phone" => $data['phoneNumber'],
            ];

            if ($data['howDidYouHearAboutUs'] !== "Choose an option") {
                $joinBlockLog->info(
                    'Customer has given how did you hear about us details: ' . $data['howDidYouHearAboutUs']
                );
                $chargebeeCreditCardPayload['cf_how_did_you_hear_about_us'] = $data['howDidYouHearAboutUs'];
            } else {
                $joinBlockLog->info('Customer has not given how did you hear about us details');
            }

            try {
                $chargebeeCreditCardPayload = apply_filters('ck_join_flow_pre_chargebee_customer_create', $data);
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

                throw new \Error('Chargebee token expired', 1);
            } catch (\Exception $exception) {
                $joinBlockLog->error(
                    'Chargebee customer creation failed with unknown exception. ' .
                        'Customer attempting charge with credit or debit card',
                    ['exception' => $exception]
                );

                throw new \Error('Unknown exception', 11);
            }

            $joinBlockLog->info('Credit or debit card customer creation via Chargebee successful');
        }
        return $customerResult;
    }

    private static function handleGocardless($data)
    {
        global $joinBlockLog;

        $mandate = null;

        if ($data['paymentMethod'] === 'directDebit') {
            $joinBlockLog->info('Creating Direct Debit mandate via GoCardless');

            /*
                Handle different GoCardless errors.

                For a complete list of errors see https://developer.gocardless.com/api-reference/#errors-error-types

                By far the most common are validation errors. These are often the result of user error
                or problems with their provided account.

                The other exceptions caught here are the result of misuse of the GoCardless API.
                They should rarely occur if at all.
            */
            try {
                $data = apply_filters('ck_join_flow_pre_gocardless_mandate_create', $data);
                $mandate = GocardlessService::createCustomerMandate($data);
            } catch (\GoCardlessPro\Core\Exception\ValidationFailedException $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit mandate creation failed as account details were invalid: ' .
                        $exception->getMessage()
                );

                throw new JoinBlockException(
                    'GoCardless Direct Debit mandate creation failed due to validation',
                    3,
                    $exception->getErrors()
                );
            } catch (\GoCardlessPro\Core\Exception\InvalidApiUsageException $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit mandate creation failed due to invalid usage of the API ' .
                        $exception->getMessage()
                );

                throw new JoinBlockException(
                    'GoCardless Direct Debit mandate creation failed due to invalid API usage',
                    5
                );
            } catch (\GoCardlessPro\Core\Exception\InvalidStateException $exception) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit mandate creation failed due to invalid state ' . $exception->getMessage()
                );

                throw new JoinBlockException(
                    'GoCardless Direct Debit mandate creation failed due to invalid state - ' .
                        'this usually means a request in flight is in a unclear state',
                    6
                );
            } catch (\Exception $expection) {
                $joinBlockLog->error(
                    'GoCardless Direct Debit mandate creation failed with unknown exception: ' .
                        get_class($expection),
                    ['exception' => $expection]
                );
                throw new \Error('GoCardless Direct Debit mandate creation failed');
            }

            $joinBlockLog->info('Direct Debit mandate via GoCardless successful');
        }

        return $mandate;
    }

    private static function createDirectDebitChargebeeCustomer($data, $billingAddress, $mandate)
    {
        global $joinBlockLog;
        $joinBlockLog->info('Creating Direct Debit Chargebee Customer');

        $formattedDateOfBirth = self::formatDateForChargebee($data['dobDay'], $data['dobMonth'], $data['dobYear']);
        $directDebitChargebeeCustomer = [
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
            "cf_birthdate" => $formattedDateOfBirth,
        ];

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
            throw new \Error('Chargebee customer creation failed');
        }

        return $customerResult;
    }

    /**
     * Creates the Chargebee subscription and returns the Plan ID so it can
     * be stored in Auth0
     */
    private static function handleChargebeeSubscription($data, $customer)
    {
        global $joinBlockLog;

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
        $joinBlockLog->info(
            "Chargebee subcription payload is:\n" .
                json_encode($chargebeeSubscriptionPayload)
        );

        try {
            $subscriptionResult = Subscription::createForCustomer(
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
                throw new \Error('Chargebee subscription failed. Insufficient funds on charging account', 2);
            }

            if (strpos($exception->getMessage(), 'Error message: (3005) Expired Card.') !== false) {
                throw new \Error('Chargebee subscription failed. Card has expired', 4);
            }

            $joinBlockLog->error(
                'Chargebee subscription has unknown failure. ' .
                    'Please note this failure so it can be added to error handling',
                ['data' => $exception->getJsonObject()]
            );

            throw new \Error('Chargebee subscription failed');
        } catch (\Exception $exception) {
            if ($exception instanceof APIError) {
                $joinBlockLog->error('Chargebee subscription failed', ['data' => $exception->getJsonObject()]);
            } else {
                $joinBlockLog->error('Chargebee subscription failed', ['data' => $exception->getMessage()]);
            }

            $joinBlockLog->error('Chargebee reports back unknown exception of type ' . get_class($exception));

            throw new \Error('Chargebee subscription failed');
        }

        $joinBlockLog->info('Chargebee subscription successful');
        return $chargebeeSubscriptionPayload['planId'] ?? '';
    }
}
