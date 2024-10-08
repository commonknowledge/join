<?php

namespace CommonKnowledge\JoinBlock\Services;

use CommonKnowledge\JoinBlock\Settings;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    public static function upsertCustomer($email)
    {
        global $joinBlockLog;

        Stripe::setApiKey(Settings::get('STRIPE_SECRET_KEY'));

        $customers = Customer::all([
            'email' => $email,
            'limit' => 1 // We just need the first match
        ]);

        $newCustomer = false;

        if (count($customers->data) > 0) {
            $customer = $customers->data[0];
        } else {
            $newCustomer = true;

            $customer = Customer::create([
                'email' => $email
            ]);

            $joinBlockLog->info('Customer created successfully! Customer ID: ' . $customer->id);
        }

        return [$customer, $newCustomer];
    }

    public static function createSubscription($customer)
    {
        $subscription = Subscription::create([
            'customer' => $customer->id,
            'items' => [
                [
                    'price' => 'price_1PyB84ISmeoaI3mwaI1At8af',
                ],
            ],
            'payment_behavior'=> 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        return $subscription;
    }

    public static function confirmSubscription($subscription, $confirmationTokenId)
    {
        $paymentIntentId = $subscription->latest_invoice->payment_intent->id;
        $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

        $confirmedPaymentIntent = $paymentIntent->confirm([
            'confirmation_token' => $confirmationTokenId,
        ]);

        return $confirmedPaymentIntent;
    }
}