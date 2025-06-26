<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Settings;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    public static function initialise()
    {
        Stripe::setApiKey(Settings::get('STRIPE_SECRET_KEY'));
    }

    public static function upsertCustomer($email)
    {
        global $joinBlockLog;

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

    public static function createSubscription($customer, $plan, $subscriptionDayOfMonth = 1)
    {
        $subscription = Subscription::create([
            'customer' => $customer->id,
            'items' => [
                [
                    'price' => $plan['stripe_price_id'],
                ],
            ],
            'billing_cycle_anchor_config' => [
                'day_of_month' => $subscriptionDayOfMonth,
            ],
            'payment_behavior' => 'default_incomplete',
            'collection_method' => 'charge_automatically',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        return $subscription;
    }

    public static function confirmSubscriptionPaymentIntent($subscription, $confirmationTokenId)
    {
        global $joinBlockLog;

        $joinBlockLog->info('Confirming payment intent for subscription', $subscription->toArray());

        if (!$subscription->latest_invoice || !$subscription->latest_invoice->payment_intent) {
            $joinBlockLog->info('No payment intent found for this subscription. It might be a free trial or zero-amount invoice');
            return null;
        }

        $paymentIntentId = $subscription->latest_invoice->payment_intent->id;
        $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

        $confirmedPaymentIntent = $paymentIntent->confirm([
            'confirmation_token' => $confirmationTokenId,
        ]);

        return $confirmedPaymentIntent;
    }

    public static function updateCustomerDefaultPaymentMethod($customerId, $paymentMethodId)
    {
        Customer::update(
            $customerId,
            [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]
        );
    }

    public static function convertFrequencyToStripeInterval($frequency)
    {
        switch ($frequency) {
            case 'monthly':
                return 'month';
            case 'yearly':
                return 'year';
            case 'weekly':
                return 'week';
            case 'daily':
                return 'day';
        }
    }

    public static function createMembershipPlanIfItDoesNotExist($membershipPlan)
    {
        global $joinBlockLog;

        $newOrExistingProduct = self::getOrCreateProductForMembershipTier($membershipPlan);
        $newOrExistingPrice = self::getOrCreatePriceForProduct($newOrExistingProduct, $membershipPlan['amount'], $membershipPlan['currency'], self::convertFrequencyToStripeInterval($membershipPlan['frequency']));

        return [$newOrExistingProduct, $newOrExistingPrice];
    }

    public static function getOrCreateProductForMembershipTier($membershipPlan)
    {
        global $joinBlockLog;

        $tierID = sanitize_title($membershipPlan['label']);

        $tierDescription = $membershipPlan['description'];

        try {
            $joinBlockLog->info("Searching for existing Stripe product for membership tier '{$tierID}'");

            $existingProducts = \Stripe\Product::search([
                'query' => "active:'true' AND metadata['membership_plan']:'{$tierID}'",
            ]);

            if (count($existingProducts->data) > 0) {
                $existingProduct = $existingProducts->data[0];
                $joinBlockLog->info("Product for membership tier '{$tierID}' already exists, with Stripe ID {$existingProduct->id}");

                // Check if the product needs to be updated
                $needsUpdate = false;
                $updateData = [];

                if ($existingProduct->name !== "Membership: {$membershipPlan['label']}") {
                    $joinBlockLog->info("Name changed, updating existing product for membership tier '{$tierID}'");

                    $updateData['name'] = "Membership: {$membershipPlan['label']}";
                    $needsUpdate = true;
                }

                if ($existingProduct->description !== $tierDescription) {
                    $joinBlockLog->info("Description changed, updating existing product for membership tier '{$tierID}'");

                    $updateData['description'] = $tierDescription;
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $updatedProduct = \Stripe\Product::update($existingProduct->id, $updateData);
                    $joinBlockLog->info("Product updated for membership tier '{$tierID}', with Stripe ID {$updatedProduct->id}");
                    return $updatedProduct;
                }

                return $existingProduct;
            }

            $joinBlockLog->info("No existing product found for membership tier '{$tierID}', creating new product");

            $stripeProduct = [
                'name' => "Membership: {$membershipPlan['label']}",
                'type' => 'service',
                'metadata' => ['membership_plan' => $tierID],
            ];

            if ($tierDescription) {
                $stripeProduct['description'] = $tierDescription;
            }

            $newProduct = \Stripe\Product::create($stripeProduct);

            $joinBlockLog->info("New Stripe product created for membership tier '{$tierID}'. Stripe Product ID {$newProduct->id}");

            return $newProduct;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $joinBlockLog->error("Error creating/retrieving product: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getOrCreatePriceForProduct($product, $amount, $currency, $interval)
    {
        global $joinBlockLog;

        // Stripe requires the price in lowest denomination of the currency. E.G. cents for USD, pence for GBP.
        // So we multiply the amount by 100 to get the price in this format.
        // We store the amount in whole units of the currency, e.g. dollars for USD, pounds for GBP.
        $stripePrice = $amount * 100;

        try {
            $joinBlockLog->info("Searching for existing Stripe price for recurring product '{$product->id}' with currency '{$currency}'");

            $existingPrices = \Stripe\Price::search([
                'query' => "active:'true' AND product:'{$product->id}' AND type:'recurring' AND currency:'{$currency}'",
            ]);

            if (count($existingPrices->data) > 0) {
                $joinBlockLog->info("Recurring price for product '{$product->id}' with currency '{$currency}' already exists.");
                return $existingPrices->data[0];
            }

            $joinBlockLog->info("No existing price found for product '{$product->id}' with currency '{$currency}', creating new price");

            $newPrice = \Stripe\Price::create([
                'product' => $product->id,
                'unit_amount' => $stripePrice,
                'currency' => $currency,
                'recurring' => ['interval' => $interval],
            ]);

            $joinBlockLog->info("New Stripe price created for product '{$product->id}'. Stripe Price ID {$newPrice->id}");

            return $newPrice;
        } catch (ApiErrorException $e) {
            $joinBlockLog->error("Error creating/retrieving price: " . $e->getMessage());
            throw $e;
        }
    }

    public static function handleWebhook($event)
    {
        global $joinBlockLog;

        $customerId = null;
        try {
            if ($event['type'] !== 'mandate.updated') {
                return;
            }
            $mandate = $event['data']['object'] ?? null;
            $paymentType = $mandate['payment_method_details']['type'] ?? null;

            if (!$mandate || $mandate['status'] !== 'active' || $paymentType !== 'bacs_debit') {
                return;
            }

            $paymentMethodId = $mandate['payment_method'];
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $customerId = $paymentMethod->customer;

            // Lookup latest draft invoice and finalize
            $invoices = \Stripe\Invoice::all([
                'customer' => $customerId,
                'status' => 'draft',
                'limit' => 1
            ]);

            $joinBlockLog->info("Finalizing direct debit subscription for Stripe customer $customerId");

            if (count($invoices->data) > 0) {
                $invoice = $invoices->data[0];
                $invoice->finalizeInvoice();
            }
        } catch (\Exception $e) {
            $c = $customerId ? $customerId : "(unknown)";
            $joinBlockLog->error("Error finalizing direct debit subscription for customer $c: " . $e->getMessage());
        }
    }
}
