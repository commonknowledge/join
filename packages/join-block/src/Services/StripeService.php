<?php

namespace CommonKnowledge\JoinBlock\Services;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

use CommonKnowledge\JoinBlock\Helpers;
use CommonKnowledge\JoinBlock\Settings;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    /**
     * How long after a subscription's first invoice is created its webhooks
     * keep deferring to the /join endpoint for Action Network state. Within
     * this window first-invoice webhooks race the /join request, and
     * concurrent Action Network writes mangle the webhook payloads Action
     * Network sends to downstream consumers (e.g. Make). Card payments
     * resolve in seconds, so anything older is a delayed-settlement payment
     * method (e.g. Bacs, which takes days) or a webhook redelivery — /join
     * finished long ago and there is nothing left to race.
     */
    public const FIRST_INVOICE_RACE_WINDOW = 3600;

    public static function initialise()
    {
        Stripe::setApiKey(Settings::get('STRIPE_SECRET_KEY'));
    }

    /**
     * Decide how to treat a webhook for a subscription_create (first) invoice.
     *
     * @param bool $hasUnprocessedJoinData The JOIN_FORM_UNPROCESSED_STRIPE_REQUEST
     *                                     option still exists, i.e. /join has not run.
     * @param int $invoiceCreated Invoice creation timestamp.
     * @param int|null $now Current timestamp, injectable for tests.
     * @return string 'recover' — the join never completed: run the saved-data
     *                            recovery path (invoice.paid only);
     *                'defer'   — /join may still be in flight: leave Action
     *                            Network state alone;
     *                'settle'  — late settlement: /join is long done, act on
     *                            the member's state directly.
     */
    public static function classifyFirstInvoiceEvent($hasUnprocessedJoinData, $invoiceCreated, $now = null)
    {
        $now = $now ?? time();
        if ($hasUnprocessedJoinData) {
            return 'recover';
        }
        if (($now - (int) $invoiceCreated) < self::FIRST_INVOICE_RACE_WINDOW) {
            return 'defer';
        }
        return 'settle';
    }

    /**
     * Validates a one-off donation amount.
     * Returns null if valid, or an error message string if invalid.
     */
    public static function validateOneOffDonationAmount(float $amount): ?string
    {
        if ($amount <= 0) {
            return 'Donation amount must be greater than zero';
        }
        if ($amount > 10000) {
            return 'Donation amount must not exceed £10,000';
        }
        return null;
    }

    public static function getCustomers($extraParams = [])
    {
        global $joinBlockLog;

        $customers = [];
        $starting_after = null;

        do {
            $params = array_merge(['limit' => 100], $extraParams);
            if ($starting_after) {
                $params['starting_after'] = $starting_after;
            }

            $response = \Stripe\Customer::all($params);
            foreach ($response->data as $cust) {
                $customers[] = $cust;
            }

            $starting_after = end($response->data)?->id;

            $joinBlockLog->info("Got " . count($response->data) . " customers from Stripe");
        } while (count($response->data) === 100);

        return $customers;
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

    /**
     * Determine how a subscription's price should be resolved.
     *
     * Returns one of:
     *   'custom_supporter' — use the generic Donation product at the given custom amount
     *   'custom_plan'      — use the plan's own product at the given custom amount
     *   'default'          — use the plan's pre-configured stripe_price_id unchanged
     *
     * NOTE: all donation amounts — supporter mode custom amounts and standard-flow
     * donation upsells alike — share a single "Supporter Donation" Stripe product
     * (see getOrCreateDonationProduct), with one price per unique amount. This
     * contrasts with membership tiers, where each tier has its own dedicated
     * Stripe product.
     */
    public static function resolveSubscriptionPriceStrategy(array $plan, float $customAmount, bool $isSupporterMode): string
    {
        if (($plan['allow_custom_amount'] || $isSupporterMode) && $customAmount > 0) {
            return $isSupporterMode ? 'custom_supporter' : 'custom_plan';
        }
        return 'default';
    }

    /**
     * Stable key for a subscription-creation request, or null when one cannot
     * be derived safely.
     *
     * Submitting the same choices twice within one form session should return
     * the subscription Stripe already created rather than making another one:
     * resubmitting after an error is how a member ends up charged twice.
     * Changing plan or amount changes the key, as does starting a new session,
     * so a deliberate second subscription is still possible.
     *
     * Without a session token the key would collapse to email + plan, and two
     * genuinely separate joins would collide — so we return null and accept the
     * old behaviour rather than risk replaying the wrong subscription.
     */
    public static function buildSubscriptionIdempotencyKey($data, $plan)
    {
        $sessionToken = $data['sessionToken'] ?? '';
        if (!$sessionToken) {
            return null;
        }

        return 'join_sub_' . sha1(implode('|', [
            $sessionToken,
            strtolower(trim((string) ($data['email'] ?? ''))),
            Settings::getMembershipPlanId($plan),
            (string) ($data['customMembershipAmount'] ?? ''),
            (string) ($data['donationAmount'] ?? ''),
            !empty($data['recurDonation']) ? '1' : '0',
            !empty($data['donationSupporterMode']) ? '1' : '0',
        ]));
    }

    public static function createSubscription($customer, $plan, $customAmount = null, $donationAmount = null, $recurDonation = false, $isSupporterMode = false, $idempotencyKey = null)
    {
        $priceId = $plan["stripe_price_id"];
        $customAmount = (float) $customAmount;
        $strategy = self::resolveSubscriptionPriceStrategy($plan, $customAmount, $isSupporterMode);
        if ($strategy === 'custom_supporter') {
            $product = self::getOrCreateDonationProduct();
            $priceId = self::getOrCreatePriceForProduct($product, $customAmount, $plan['currency'], self::convertFrequencyToStripeInterval($plan['frequency']));
        } elseif ($strategy === 'custom_plan') {
            $product = self::getOrCreateProductForMembershipTier($plan, false);
            $priceId = self::getOrCreatePriceForProduct($product, $customAmount, $plan['currency'], self::convertFrequencyToStripeInterval($plan['frequency']));
        }

        $items = [['price' => $priceId]];
        $addInvoiceItems = [];

        $donationAmount = (float) $donationAmount;
        if ($donationAmount > 0) {
            $donationProduct = self::getOrCreateDonationProduct();
            if ($recurDonation) {
                $interval = self::convertFrequencyToStripeInterval($plan['frequency']);
                $donationPriceId = self::getOrCreatePriceForProduct(
                    $donationProduct, $donationAmount, $plan['currency'], $interval
                );
                $items[] = ['price' => $donationPriceId];
            } else {
                $donationPrice = self::getOrCreateOneTimePriceForProduct($donationProduct, $donationAmount, $plan['currency']);
                $addInvoiceItems[] = ['price' => $donationPrice->id];
            }
        }

        $subscriptionPayload = [
            'customer'         => $customer->id,
            'items'            => $items,
            'payment_behavior' => 'default_incomplete',
            'collection_method' => 'charge_automatically',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription', 'payment_method_types' => strtolower($plan['currency']) === 'gbp' ? ['card', 'bacs_debit'] : ['card']],
            'expand'           => ['latest_invoice.payment_intent'],
        ];

        if (!empty($addInvoiceItems)) {
            $subscriptionPayload['add_invoice_items'] = $addInvoiceItems;
        }

        $options = $idempotencyKey ? ['idempotency_key' => $idempotencyKey] : [];
        $subscription = Subscription::create($subscriptionPayload, $options);

        return $subscription;
    }

    public static function createPaymentIntent($customer, $amount, $currency)
    {
        global $joinBlockLog;

        $currency = strtolower($currency);

        $joinBlockLog->info("Creating one-off invoice-based payment for customer {$customer->id}: {$currency} {$amount}");

        $product = self::getOrCreateDonationProduct();
        $price   = self::getOrCreateOneTimePriceForProduct($product, (float) $amount, $currency);

        $invoice = \Stripe\Invoice::create([
            'customer'          => $customer->id,
            'collection_method' => 'charge_automatically',
        ]);

        \Stripe\InvoiceItem::create([
            'customer' => $customer->id,
            'invoice'  => $invoice->id,
            'price'    => $price->id,
        ]);

        $finalizedInvoice = $invoice->finalizeInvoice();
        $paymentIntent    = \Stripe\PaymentIntent::retrieve($finalizedInvoice->payment_intent);

        return [
            'id'            => $paymentIntent->id,
            'client_secret' => $paymentIntent->client_secret,
            'customer'      => $customer->id,
        ];
    }

    public static function getOrCreateOneTimePriceForProduct($product, float $amount, string $currency)
    {
        global $joinBlockLog;

        $stripeAmount = (int) round($amount * 100);
        $currency     = strtolower($currency);

        $existingPrices = \Stripe\Price::search([
            'query' => "active:'true' AND product:'{$product->id}' AND type:'one_time' AND currency:'{$currency}'",
        ]);

        foreach ($existingPrices->data as $price) {
            if ($price->unit_amount === $stripeAmount) {
                $joinBlockLog->info("One-time price for product '{$product->id}' amount {$stripeAmount} already exists.");
                return $price;
            }
        }

        $joinBlockLog->info("Creating one-time price for product '{$product->id}' amount {$stripeAmount}");

        return \Stripe\Price::create([
            'product'     => $product->id,
            'unit_amount' => $stripeAmount,
            'currency'    => $currency,
        ]);
    }

    public static function getOrCreateDonationProduct()
    {
        global $joinBlockLog;

        try {
            $existingProducts = \Stripe\Product::search([
                'query' => "active:'true' AND metadata['type']:'supporter_donation'",
            ]);

            if (count($existingProducts->data) > 0) {
                return $existingProducts->data[0];
            }

            $joinBlockLog->info("Creating Stripe product for supporter donations");

            return \Stripe\Product::create([
                'name'     => 'Supporter Donation',
                'type'     => 'service',
                'metadata' => ['type' => 'supporter_donation'],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $joinBlockLog->error("Error creating/retrieving donation product: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getSubscriptionsForCSVOutput()
    {
        global $joinBlockLog;

        $subs = [];
        $starting_after = null;
        $priceCache = [];

        // 1. Get subscriptions
        do {
            $params = ['limit' => 100];
            if ($starting_after) {
                $params['starting_after'] = $starting_after;
            }

            $response = \Stripe\Subscription::all($params);
            $data = $response->data;
            $subs = array_merge($subs, $data);

            $starting_after = end($data)?->id;

            $joinBlockLog->info("Got " . count($data) . " subs from Stripe");
        } while (count($data) === 100);

        $customerIds = array_unique(array_map(fn($sub) => $sub->customer, $subs));

        // 2. Get customers in bulk
        $customers = [];
        $starting_after = null;

        do {
            $params = ['limit' => 100];
            if ($starting_after) {
                $params['starting_after'] = $starting_after;
            }

            $response = \Stripe\Customer::all($params);
            foreach ($response->data as $cust) {
                if (in_array($cust->id, $customerIds)) {
                    $customers[$cust->id] = $cust;
                }
            }

            $starting_after = end($response->data)?->id;

            $joinBlockLog->info("Got " . count($response->data) . " customers from Stripe");
        } while (count($response->data) === 100 && count($customers) < count($customerIds));

        if (count($customers) < count($customerIds)) {
            $joinBlockLog->warning("Some customers were not found during bulk fetch.");
        }

        // 3. Get invoices in bulk (covering all subs)
        $invoices = [];
        $starting_after = null;
        do {
            $params = ['limit' => 100];
            if ($starting_after) {
                $params['starting_after'] = $starting_after;
            }

            $response = \Stripe\Invoice::all($params);
            foreach ($response->data as $inv) {
                if (!empty($inv->subscription) && $inv->status === 'paid') {
                    $invoices[$inv->subscription][] = $inv;
                }
            }

            $starting_after = end($response->data)?->id;
        } while (count($response->data) === 100);

        // 4. Build output
        $output = [];
        foreach ($subs as $sub) {
            $customer = $customers[$sub->customer] ?? null;
            if (!$customer) {
                continue;
            }

            $price_id = $sub->items->data[0]->price->id ?? null;

            if ($price_id) {
                if (!isset($priceCache[$price_id])) {
                    try {
                        $price = \Stripe\Price::retrieve($price_id);
                        $nickname = $price->nickname;
                        if (!$nickname && $price->product) {
                            $product = \Stripe\Product::retrieve($price->product);
                            $nickname = $product->name . ' - ' . strtoupper($price->currency) . ' ' . number_format($price->unit_amount / 100, 2);
                        }
                        $priceCache[$price_id] = $nickname ?: 'Unknown';
                    } catch (\Exception $e) {
                        $priceCache[$price_id] = 'Error loading price';
                    }
                }
            }

            // Get first & last payment from invoices
            $firstPayment = null;
            $lastPayment = null;
            if (!empty($invoices[$sub->id])) {
                usort($invoices[$sub->id], fn($a, $b) => $a->created <=> $b->created);
                $firstPayment = $invoices[$sub->id][0]->created;
                $lastPayment  = end($invoices[$sub->id])->created;
            }

            $row = [
                "email" => $customer->email,
                "customer_id" => $customer->id,
                "subscription_id" => $sub->id,
                "subscription_status" => $sub->status,
                "subscription_created" => $sub->created,
                "subscription_end" => $sub->current_period_end,
                "first_payment" => $firstPayment,
                "last_payment"  => $lastPayment,
                "price_id" => $price_id,
                "price_label" => $priceCache[$price_id] ?? "Unknown",
            ];
            $output[] = $row;
        }

        return $output;
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

    /**
     * Returns the expected Stripe product name for a membership plan.
     * Standard mode uses "Membership: X"; supporter mode uses "Donation: X".
     */
    public static function getExpectedProductName(array $plan, bool $isSupporterMode): string
    {
        $prefix = $isSupporterMode ? 'Donation' : 'Membership';
        return "{$prefix}: {$plan['label']}";
    }

    public static function createMembershipPlanIfItDoesNotExist($membershipPlan, $isSupporterMode = false)
    {
        global $joinBlockLog;

        $newOrExistingProduct = self::getOrCreateProductForMembershipTier($membershipPlan, $isSupporterMode);
        $newOrExistingPrice = self::getOrCreatePriceForProduct($newOrExistingProduct, $membershipPlan['amount'], $membershipPlan['currency'], self::convertFrequencyToStripeInterval($membershipPlan['frequency']));

        return [$newOrExistingProduct, $newOrExistingPrice];
    }

    public static function getOrCreateProductForMembershipTier($membershipPlan, $isSupporterMode = false)
    {
        global $joinBlockLog;

        $tierID = Settings::getMembershipPlanId($membershipPlan);

        $tierDescription = $membershipPlan['description'];
        $expectedName = self::getExpectedProductName($membershipPlan, $isSupporterMode);

        try {
            $joinBlockLog->info("Searching for existing Stripe product for membership tier '{$tierID}'");

            $existingProducts = \Stripe\Product::search([
                'query' => "active:'true' AND metadata['membership_plan']:'{$tierID}'",
            ]);

            if (count($existingProducts->data) > 0) {
                $existingProduct = $existingProducts->data[0];
                $joinBlockLog->info("Product for membership tier '{$tierID}' already exists, with Stripe ID {$existingProduct->id}");

                if ($existingProduct->name !== $expectedName) {
                    $joinBlockLog->warning("Stripe product name mismatch for tier '{$tierID}': expected '{$expectedName}', found '{$existingProduct->name}'. The block's supporter mode setting may not match how this plan was originally created.");
                }

                return $existingProduct;
            }

            $joinBlockLog->info("No existing product found for membership tier '{$tierID}', creating new product");

            $stripeProduct = [
                'name' => $expectedName,
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
            $joinBlockLog->info("Searching for existing Stripe price for recurring product '{$product->id}' with currency '{$currency}' and amount {$stripePrice}");

            $existingPrices = \Stripe\Price::search([
                'query' => "active:'true' AND product:'{$product->id}' AND type:'recurring' AND currency:'{$currency}'",
            ]);

            foreach ($existingPrices->data as $price) {
                if ($price->unit_amount === $stripePrice) {
                    $joinBlockLog->info("Recurring price for product '{$product->id}' with currency '{$currency}' and amount {$stripePrice} already exists.");
                    return $price;
                }
            }

            $joinBlockLog->info("No existing price found for product '{$product->id}' with currency '{$currency}' and amount {$stripePrice}, creating new price");

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

    /**
     * The recurring amount of one specific subscription, in major units
     * (e.g. 3.30 for £3.30), or null when it cannot be determined.
     *
     * Fetches by ID rather than scanning the customer's subscription list, so
     * there is no "wasn't in the page we looked at" failure mode. Callers MUST
     * treat null as *unknown* and never as zero: a null that gets coerced to
     * 0.0 looks exactly like a genuine amount mismatch, which is how a paying
     * member can be rejected at the amount check.
     *
     * API errors propagate rather than being swallowed, so a transport failure
     * (retryable) stays distinguishable from a subscription that really has no
     * priced item (not retryable).
     *
     * @throws ApiErrorException
     */
    public static function getSubscriptionAmount($subscriptionId)
    {
        global $joinBlockLog;

        if (!$subscriptionId) {
            $joinBlockLog->warning("getSubscriptionAmount: called without a subscription ID");
            return null;
        }

        $subscription = Subscription::retrieve($subscriptionId);
        $item = $subscription->items->first();

        if (!$item || !isset($item->price->unit_amount)) {
            $joinBlockLog->warning("getSubscriptionAmount: $subscriptionId has no priced item");
            return null;
        }

        return round($item->price->unit_amount / 100, 2);
    }

    /**
     * Whether a subscription represents a payment that actually went through.
     *
     * Strictly read-only — used by the CRM retry worker to confirm a member
     * really paid before writing them to a CRM, long after the join. Nothing
     * here may mutate the subscription: by now it may be a live membership that
     * has moved on from the data the retry record was written with.
     *
     * @return bool|null true = paid, false = not paid, null = could not tell
     *                   (transient failure — the caller should try again later
     *                   rather than conclude anything).
     */
    public static function subscriptionWasPaid($subscriptionId)
    {
        global $joinBlockLog;

        try {
            $subscription = Subscription::retrieve($subscriptionId);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // The subscription does not exist; a definite answer, not an outage.
            $joinBlockLog->warning("subscriptionWasPaid($subscriptionId): not found — " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $joinBlockLog->warning("subscriptionWasPaid($subscriptionId): could not check — " . $e->getMessage());
            return null;
        }

        if (in_array($subscription->status, ['active', 'trialing'], true)) {
            return true;
        }

        // A cancelled subscription still counts if money changed hands: the
        // member paid, so they belong in the CRM regardless of its state now.
        if (empty($subscription->latest_invoice)) {
            return false;
        }

        try {
            $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);
        } catch (\Exception $e) {
            $joinBlockLog->warning(
                "subscriptionWasPaid($subscriptionId): could not inspect invoice — " . $e->getMessage()
            );
            return null;
        }

        return $invoice->status === 'paid';
    }

    /**
     * Resolve the member's Stripe customer, creating one if we do not have it.
     */
    public static function resolveCustomerId($email, $customerId)
    {
        if ($customerId) {
            return $customerId;
        }
        [$customer,] = self::upsertCustomer($email);
        return $customer->id;
    }

    /**
     * Read-only: the member's earliest subscription date, and their first and
     * last payment dates, for the CRM custom fields.
     *
     * Best-effort — a transient Stripe error must not fail an otherwise good
     * join, so on failure the caller gets today's date and nulls rather than an
     * exception.
     */
    public static function getSubscriptionDates($email, $customerId)
    {
        global $joinBlockLog;

        $firstSubscriptionDate = date('Y-m-d');
        $firstPayment = null;
        $lastPayment = null;

        try {
            $subscriptions = \Stripe\Subscription::all([
                'customer' => $customerId,
                'status'   => 'all',
                'limit'    => 100,
            ]);

            foreach ($subscriptions->autoPagingIterator() as $sub) {
                $createdDate = date('Y-m-d', $sub->created);
                if ($createdDate < $firstSubscriptionDate) {
                    $firstSubscriptionDate = $createdDate;
                }
            }

            $paidInvoices = \Stripe\Invoice::all([
                'customer' => $customerId,
                'status'   => 'paid',
                'limit'    => 100,
            ]);

            foreach ($paidInvoices->autoPagingIterator() as $invoice) {
                $paymentDate = date('Y-m-d', $invoice->status_transitions->paid_at);
                if (is_null($firstPayment) || $paymentDate < $firstPayment) {
                    $firstPayment = $paymentDate;
                }
                if (is_null($lastPayment) || $paymentDate > $lastPayment) {
                    $lastPayment = $paymentDate;
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->error("Error reading subscription dates for " . $email . ": " . $e->getMessage());
        }

        return [
            "firstSubscription" => $firstSubscriptionDate,
            "firstPayment"      => $firstPayment,
            "lastPayment"       => $lastPayment,
        ];
    }

    /**
     * Cancel every *other* active-ish subscription for the customer, removing
     * the duplicates a member creates by submitting the payment step twice.
     * $subscriptionId is the one to keep: the subscription the browser Stripe
     * client just created.
     *
     * Only safe to call while $subscriptionId is genuinely the member's current
     * subscription. Calling it from stale data — a recovery replay hours later,
     * say — would cancel a live subscription the member has since created. See
     * ensureStripeSubscriptionsCreated(), which deliberately does not.
     */
    public static function cancelPreviousSubscriptions($email, $customerId, $subscriptionId)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Removing previous subscriptions for user " . $email . ", customer: " . $customerId);

        try {
            $subscriptions = \Stripe\Subscription::all([
                'customer' => $customerId,
                'status'   => 'all',
                'limit'    => 100,
            ]);

            foreach ($subscriptions->autoPagingIterator() as $sub) {
                if ($sub->id === $subscriptionId) {
                    continue;
                }
                if (!in_array($sub->status, ['active', 'trialing', 'past_due'])) {
                    continue;
                }

                $joinBlockLog->info("Canceling subscription " . $sub->id . " for user " . $email);
                $sub->cancel();

                // Find and void open invoices for this subscription
                $invoices = \Stripe\Invoice::all([
                    'customer'     => $customerId,
                    'subscription' => $sub->id,
                    'status'       => 'open',
                    'limit'        => 100,
                ]);

                foreach ($invoices->autoPagingIterator() as $invoice) {
                    $joinBlockLog->info(
                        "Voiding invoice " . $invoice->id . " for canceled subscription " . $sub->id
                    );
                    $invoice->voidInvoice();
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->error("Error removing subscriptions for user " . $email . ": " . $e->getMessage());
        }
    }

    public static function getSubscriptionHistory($customerId)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Getting subscription history for customer: " . $customerId);

        $firstSubscriptionDate = date('Y-m-d');
        $firstPayment = null;
        $lastPayment = null;

        try {
            // Fetch all subscriptions for date calculation
            $subscriptions = \Stripe\Subscription::all([
                'customer' => $customerId,
                'status'   => 'all',
                'limit'    => 100,
            ]);

            foreach ($subscriptions->autoPagingIterator() as $sub) {
                // Track earliest subscription date
                $createdDate = date('Y-m-d', $sub->created);
                if (is_null($firstSubscriptionDate) || $createdDate < $firstSubscriptionDate) {
                    $firstSubscriptionDate = $createdDate;
                }
            }

            // Fetch all paid invoices for first/last payment dates
            $paidInvoices = \Stripe\Invoice::all([
                'customer' => $customerId,
                'status'   => 'paid',
                'limit'    => 100,
            ]);

            foreach ($paidInvoices->autoPagingIterator() as $invoice) {
                $paymentDate = date('Y-m-d', $invoice->status_transitions->paid_at);
                if (is_null($firstPayment) || $paymentDate < $firstPayment) {
                    $firstPayment = $paymentDate;
                }
                if (is_null($lastPayment) || $paymentDate > $lastPayment) {
                    $lastPayment = $paymentDate;
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->error("Error Getting subscription history for customer " . $customerId . ": " . $e->getMessage());
        }

        return [
            "firstSubscription" => $firstSubscriptionDate,
            "firstPayment"      => $firstPayment,
            "lastPayment"       => $lastPayment,
        ];
    }

    /**
     * Extract Zetkin person fields from a Stripe Customer object.
     *
     * @param array $customer Stripe Customer array
     * @return array Person data fields (only non-empty values)
     */
    private static function splitFullName($name)
    {
        if (empty($name)) {
            return [];
        }
        return explode(' ', trim($name), 2);
    }

    public static function extractPersonDataFromStripeCustomer($customer)
    {
        $nameParts = self::splitFullName($customer['name'] ?? null);
        $address = $customer['address'] ?? [];

        return Helpers::removeNullOrEmpty([
            'first_name'     => $nameParts[0] ?? null,
            'last_name'      => $nameParts[1] ?? null,
            'phone'          => $customer['phone'] ?? null,
            'street_address' => $address['line1'] ?? null,
            'co_address'     => $address['line2'] ?? null,
            'city'           => $address['city'] ?? null,
            'zip_code'       => $address['postal_code'] ?? null,
            'country'        => $address['country'] ?? null,
        ]);
    }

    /**
     * Extract Mailchimp merge fields from a Stripe Customer object.
     *
     * @param array $customer Stripe Customer array
     * @return array Mailchimp merge fields (only non-empty values)
     */
    public static function extractMailchimpMergeFieldsFromStripeCustomer($customer)
    {
        $nameParts = self::splitFullName($customer['name'] ?? null);
        $address = $customer['address'] ?? [];

        $mergeFields = Helpers::removeNullOrEmpty([
            'FNAME' => $nameParts[0] ?? null,
            'LNAME' => $nameParts[1] ?? null,
            'PHONE' => $customer['phone'] ?? null,
        ]);

        if (!empty($address['line1'])) {
            $mergeFields['ADDRESS'] = [
                'addr1'   => $address['line1'] ?? '',
                'addr2'   => $address['line2'] ?? '',
                'city'    => $address['city'] ?? '',
                'state'   => $address['state'] ?? '',
                'zip'     => $address['postal_code'] ?? '',
                'country' => $address['country'] ?? '',
            ];
        }

        return $mergeFields;
    }

    public static function handleWebhook($event)
    {
        global $joinBlockLog;

        $customerId = null;
        $customerLapsed = false;
        $lapseTrigger = null;
        // Per-email lock acquired lazily when we resolve the customer's
        // email. Serialises CRM mutations against the /join endpoint and
        // other concurrent webhook deliveries for the same person.
        // See JoinService::acquireLock().
        $lockFile = null;

        try {
            switch ($event['type']) {
                case 'mandate.updated':
                    $mandate = $event['data']['object'] ?? null;
                    $paymentType = $mandate['payment_method_details']['type'] ?? null;

                    if (!$mandate || $mandate['status'] !== 'active' || $paymentType !== 'bacs_debit') {
                        return;
                    }

                    $paymentMethodId = $mandate['payment_method'];
                    $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
                    $customerId = $paymentMethod->customer;

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
                    break;

                case 'customer.subscription.deleted':
                    $subscription = $event['data']['object'] ?? null;
                    $customerId = $subscription['customer'] ?? '(unknown)';

                    $joinBlockLog->info("Subscription cancelled for Stripe customer $customerId");
                    if (empty($subscription['customer'])) {
                        break;
                    }

                    // Cancelling a subscription does not stop a first payment
                    // that is still settling (delayed methods such as Bacs):
                    // it can land days later against the dead subscription.
                    $latestInvoiceId = $subscription['latest_invoice'] ?? null;
                    if ($latestInvoiceId) {
                        try {
                            $latestInvoice = \Stripe\Invoice::retrieve($latestInvoiceId);
                            if ($latestInvoice->status === 'open') {
                                $joinBlockLog->error(
                                    "Subscription {$subscription['id']} for Stripe customer $customerId was cancelled"
                                    . " with open invoice {$latestInvoice->id}. If its payment is still processing it"
                                    . " will settle against the dead subscription — void the invoice or refund the"
                                    . " payment."
                                );
                            }
                        } catch (\Exception $e) {
                            $joinBlockLog->warning(
                                "Could not inspect latest invoice for cancelled subscription {$subscription['id']}: "
                                . $e->getMessage()
                            );
                        }
                    }

                    // Cancelling one subscription must not lapse a member who
                    // still has another live one — e.g. /join cancels the
                    // previous subscription moments after creating its
                    // replacement during a re-join or tier change.
                    if (self::customerHasActiveSubscription($customerId, $subscription['id'] ?? null)) {
                        $joinBlockLog->info(
                            "Skipping lapsing for Stripe customer $customerId: another active subscription exists."
                        );
                        break;
                    }

                    $customerLapsed = true;
                    $lapseTrigger = 'subscription_deleted';
                    break;

                case 'invoice.payment_failed':
                    $invoice = $event['data']['object'] ?? null;
                    $customerId = $invoice['customer'] ?? '(unknown)';

                    if (($invoice['billing_reason'] ?? null) === 'subscription_create') {
                        $subscriptionId = $invoice['subscription']
                            ?? $invoice['parent']['subscription_details']['subscription']
                            ?? null;
                        $hasUnprocessedJoinData = $subscriptionId
                            && get_option("JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_{$subscriptionId}");

                        if (self::classifyFirstInvoiceEvent($hasUnprocessedJoinData, $invoice['created'] ?? 0) !== 'settle') {
                            $joinBlockLog->info("Skipping invoice.payment_failed lapsing for Stripe customer $customerId: subscription_create invoice, /join endpoint will handle Action Network state.");
                            break;
                        }

                        // A first invoice failing this long after signup means a
                        // delayed-settlement payment (e.g. Bacs) bounced after the
                        // join completed: the member is tagged but never paid.
                        // Fall through to the normal failed-payment handling.
                        $joinBlockLog->warning(
                            "Late first-invoice payment failure for Stripe customer $customerId"
                            . " (invoice {$invoice['id']}): the join completed but the payment has now failed."
                        );
                    }

                    if (empty($invoice['next_payment_attempt'])) {
                        $joinBlockLog->warning("Final payment attempt failed for Stripe customer $customerId. No retries will be attempted.");
                        if (!empty($invoice['customer'])) {
                            $customerLapsed = true;
                            $lapseTrigger = 'invoice_payment_failed';
                        }
                    } else {
                        $joinBlockLog->info("Payment failed for Stripe customer $customerId, retry scheduled.");
                        if (!empty($invoice['customer'])) {
                            $email = self::getEmailForCustomer($customerId);
                            if ($email) {
                                $lockFile = JoinService::acquireLock($email);
                                $context = ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed_retry_scheduled', 'event' => $event];
                                if (JoinService::shouldMarkMemberLapsing($email, $context)) {
                                    JoinService::toggleMemberLapsing($email, true, $context);
                                }
                            }
                        }
                    }
                    break;

                case 'invoice.paid':
                    $invoice = $event['data']['object'] ?? null;
                    $customerId = $invoice['customer'] ?? '(unknown)';
                    $joinBlockLog->info("Invoice paid for Stripe customer $customerId");
                    if (($invoice['billing_reason'] ?? null) === 'subscription_create') {
                        $subscriptionId = $invoice['subscription']
                            ?? $invoice['parent']['subscription_details']['subscription']
                            ?? null;
                        $hasUnprocessedJoinData = $subscriptionId
                            && get_option("JOIN_FORM_UNPROCESSED_STRIPE_REQUEST_{$subscriptionId}");
                        $classification = self::classifyFirstInvoiceEvent($hasUnprocessedJoinData, $invoice['created'] ?? 0);

                        if ($classification === 'recover') {
                            // The member paid but never returned to the site, so the
                            // /join endpoint was never hit: complete the join from the
                            // saved form data instead. NOTE: this must run without
                            // acquiring the per-email lock here — handleJoin() acquires
                            // it itself, and flock blocks on a second handle even
                            // within one process.
                            $joinBlockLog->info("Skipping invoice.paid un-lapsing for Stripe customer $customerId: subscription_create invoice, /join endpoint will handle Action Network state.");
                            JoinService::ensureStripeSubscriptionsCreated($subscriptionId);
                            break;
                        }

                        if ($classification === 'defer') {
                            $joinBlockLog->info("Skipping invoice.paid un-lapsing for Stripe customer $customerId: subscription_create invoice, /join endpoint will handle Action Network state.");
                            break;
                        }

                        self::handleLateFirstInvoicePaid($invoice, $subscriptionId, $event);
                        break;
                    }
                    if (!empty($invoice['customer'])) {
                        $email = self::getEmailForCustomer($customerId);
                        if ($email) {
                            $lockFile = JoinService::acquireLock($email);
                            $context = ['provider' => 'stripe', 'trigger' => 'invoice_paid', 'event' => $event];
                            if (JoinService::shouldUnlapseMember($email, $context)) {
                                JoinService::toggleMemberLapsed($email, false, null, $context);
                            }
                        }
                    }
                    break;

                case 'customer.updated':
                    $customer = $event['data']['object'] ?? null;
                    $previousAttributes = $event['data']['previous_attributes'] ?? [];

                    if (!$customer || empty($customer['email'])) {
                        $joinBlockLog->warning("customer.updated event received with no email, skipping");
                        return;
                    }

                    $email = $customer['email'];
                    $previousEmail = $previousAttributes['email'] ?? null;

                    $joinBlockLog->info("Syncing updated customer details for Stripe customer {$customer['id']} ($email)");

                    $lockFile = JoinService::acquireLock($email);

                    $personData = self::extractPersonDataFromStripeCustomer($customer);
                    $mergeFields = self::extractMailchimpMergeFieldsFromStripeCustomer($customer);

                    if (Settings::get("USE_ZETKIN") && (!empty($personData) || $previousEmail)) {
                        try {
                            ZetkinService::updatePerson($email, $personData, $previousEmail);
                        } catch (\Exception $e) {
                            $joinBlockLog->error("Zetkin error syncing customer.updated for $email: " . $e->getMessage());
                        }
                    }

                    if (Settings::get("USE_MAILCHIMP") && (!empty($mergeFields) || $previousEmail)) {
                        try {
                            MailchimpService::updateMember($email, $mergeFields, $previousEmail);
                        } catch (\Exception $e) {
                            $joinBlockLog->error("Mailchimp error syncing customer.updated for $email: " . $e->getMessage());
                        }
                    }
                    break;

                case 'customer.subscription.updated':
                    $subscription = $event['data']['object'] ?? null;
                    $previousAttributes = $event['data']['previous_attributes'] ?? [];

                    if (!$subscription) {
                        break;
                    }

                    $previousStatus = $previousAttributes['status'] ?? null;
                    $currentStatus = $subscription['status'] ?? null;
                    $email = null;

                    // A new subscription's incomplete -> active flip happens
                    // seconds before /join writes to Action Network: acting on
                    // it here recreates exactly the concurrent-write problem
                    // the subscription_create invoice guard exists to prevent.
                    $subscriptionAge = time() - (int) ($subscription['created'] ?? 0);
                    if (
                        $previousStatus && $previousStatus !== $currentStatus
                        && $subscriptionAge < self::FIRST_INVOICE_RACE_WINDOW
                    ) {
                        $joinBlockLog->info(
                            "Skipping status change handling for new subscription {$subscription['id']}"
                            . " ($previousStatus -> $currentStatus): /join endpoint will handle Action Network state."
                        );
                    } elseif ($previousStatus && $previousStatus !== $currentStatus) {
                        $cid = $subscription['customer'];
                        $email = self::getEmailForCustomer($cid);

                        if ($email) {
                            $lockFile = JoinService::acquireLock($email);
                            $activeStatuses = ['active', 'trialing'];
                            $lapsedStatuses = ['unpaid', 'incomplete_expired'];
                            $lapsingStatuses = ['past_due'];

                            $wasActive = in_array($previousStatus, $activeStatuses);
                            $isNowActive = in_array($currentStatus, $activeStatuses);
                            $isNowLapsed = in_array($currentStatus, $lapsedStatuses);
                            $isNowLapsing = in_array($currentStatus, $lapsingStatuses);

                            if (!$wasActive && $isNowActive) {
                                $joinBlockLog->info("Subscription reactivated for $email ($previousStatus -> $currentStatus)");
                                $context = ['provider' => 'stripe', 'trigger' => 'subscription_status_changed', 'event' => $event];
                                if (JoinService::shouldUnlapseMember($email, $context)) {
                                    JoinService::toggleMemberLapsed($email, false, null, $context);
                                }
                            } elseif ($isNowLapsed) {
                                $joinBlockLog->info("Subscription lapsed for $email ($previousStatus -> $currentStatus)");
                                $context = ['provider' => 'stripe', 'trigger' => 'subscription_status_changed', 'event' => $event];
                                if (JoinService::shouldLapseMember($email, $context)) {
                                    JoinService::toggleMemberLapsed($email, true, null, $context);
                                }
                            } elseif ($isNowLapsing) {
                                $joinBlockLog->info("Subscription lapsing for $email ($previousStatus -> $currentStatus)");
                                $context = ['provider' => 'stripe', 'trigger' => 'subscription_status_changed', 'event' => $event];
                                if (JoinService::shouldMarkMemberLapsing($email, $context)) {
                                    JoinService::toggleMemberLapsing($email, true, $context);
                                }
                            }
                        }
                    }

                    $priceChange = self::extractPriceChange($event);
                    if ($priceChange) {
                        $email = $email ?? self::getEmailForCustomer($subscription['customer']);
                        if (!$email) {
                            $joinBlockLog->warning("Tier change detected but could not resolve email for customer {$subscription['customer']}");
                            break;
                        }
                        $lockFile = $lockFile ?: JoinService::acquireLock($email);

                        ['previousPriceId' => $previousPriceId, 'currentPriceId' => $currentPriceId] = $priceChange;
                        $newPlan = Settings::getMembershipPlanByPriceId($currentPriceId);
                        $oldPlan = Settings::getMembershipPlanByPriceId($previousPriceId);

                        if (!$newPlan) {
                            $joinBlockLog->warning("Tier change for $email: new price $currentPriceId does not match any known membership plan. Tag changes skipped.");
                            break;
                        }

                        if (!$oldPlan) {
                            $joinBlockLog->warning("Tier change for $email: old price $previousPriceId not found — old tier tags will not be removed.");
                        }

                        ['addTags' => $addTags, 'removeTags' => $removeTags] = self::resolveTierTagChanges($newPlan, $oldPlan);

                        $joinBlockLog->info("Tier change for $email ({$newPlan['label']}): add=[" . implode(',', $addTags) . "] remove=[" . implode(',', $removeTags) . "]");

                        if (Settings::get('USE_ZETKIN')) {
                            foreach ($addTags as $tag) {
                                try {
                                    ZetkinService::addTag($email, $tag);
                                } catch (\Exception $e) {
                                    $joinBlockLog->error("Zetkin addTag($tag) for $email: " . $e->getMessage());
                                }
                            }
                            foreach ($removeTags as $tag) {
                                try {
                                    ZetkinService::removeTag($email, $tag);
                                } catch (\Exception $e) {
                                    $joinBlockLog->error("Zetkin removeTag($tag) for $email: " . $e->getMessage());
                                }
                            }
                        }

                        if (Settings::get('USE_MAILCHIMP')) {
                            foreach ($addTags as $tag) {
                                try {
                                    MailchimpService::addTag($email, $tag);
                                } catch (\Exception $e) {
                                    $joinBlockLog->error("Mailchimp addTag($tag) for $email: " . $e->getMessage());
                                }
                            }
                            foreach ($removeTags as $tag) {
                                try {
                                    MailchimpService::removeTag($email, $tag);
                                } catch (\Exception $e) {
                                    $joinBlockLog->error("Mailchimp removeTag($tag) for $email: " . $e->getMessage());
                                }
                            }
                        }
                    }
                    break;

                default:
                    // Ignore unrelated events
                    return;
            }

            if ($customerLapsed) {
                $email = self::getEmailForCustomer($customerId);
                if ($email) {
                    $lockFile = $lockFile ?: JoinService::acquireLock($email);
                    $context = ['provider' => 'stripe', 'trigger' => $lapseTrigger, 'event' => $event];
                    if (JoinService::shouldLapseMember($email, $context)) {
                        JoinService::toggleMemberLapsed($email, true, null, $context);
                    }
                }
            }
        } catch (\Exception $e) {
            $c = $customerId ?: "(unknown)";
            $joinBlockLog->error("Error handling Stripe webhook for customer $c: " . $e->getMessage());
        } finally {
            JoinService::releaseLock($lockFile);
        }
    }

    private static function getEmailForCustomer($customerId)
    {
        $customer = Customer::retrieve($customerId);
        if (!$customer) {
            return null;
        }
        return $customer->email;
    }

    private static function customerHasActiveSubscription($customerId, $excludeSubscriptionId = null)
    {
        global $joinBlockLog;

        try {
            foreach (['active', 'trialing'] as $status) {
                $subscriptions = Subscription::all([
                    'customer' => $customerId,
                    'status' => $status,
                    'limit' => 10,
                ]);
                foreach ($subscriptions->data as $subscription) {
                    if ($subscription->id !== $excludeSubscriptionId) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            $joinBlockLog->warning(
                "Could not list subscriptions for Stripe customer $customerId: " . $e->getMessage()
            );
        }
        return false;
    }

    /**
     * A subscription_create invoice was paid long after it was created — a
     * delayed-settlement payment method (e.g. Bacs direct debit, ~6 working
     * days) or a webhook redelivery. Unlike fresh first invoices this cannot
     * race the /join endpoint, so act on the member's state directly: un-lapse
     * them if their subscription is live, or raise an alert if money has been
     * collected for a subscription that no longer exists.
     */
    private static function handleLateFirstInvoicePaid($invoice, $subscriptionId, $event)
    {
        global $joinBlockLog;

        $customerId = $invoice['customer'];

        $subscription = null;
        if ($subscriptionId) {
            try {
                $subscription = Subscription::retrieve($subscriptionId);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $joinBlockLog->warning(
                    "Late first-invoice settlement: could not retrieve subscription $subscriptionId: "
                    . $e->getMessage()
                );
            }
        }
        $status = $subscription->status ?? 'missing';

        if (!in_array($status, ['active', 'trialing'])) {
            $joinBlockLog->error(
                "Payment collected for dead subscription: first invoice {$invoice['id']} of Stripe customer"
                . " $customerId settled late, but subscription " . ($subscriptionId ?: '(unknown)') . " is $status."
                . " Refund the payment or reinstate the membership manually."
            );
            return;
        }

        $email = self::getEmailForCustomer($customerId);
        if (!$email) {
            $joinBlockLog->error(
                "Late first-invoice settlement for Stripe customer $customerId: could not resolve an email"
                . " address, cannot reconcile Action Network state."
            );
            return;
        }

        $joinBlockLog->info(
            "Late first-invoice settlement for $email: subscription $subscriptionId is $status,"
            . " ensuring the member is not marked lapsed."
        );

        $lockFile = JoinService::acquireLock($email);
        try {
            $context = ['provider' => 'stripe', 'trigger' => 'late_first_invoice_paid', 'event' => $event];
            if (JoinService::shouldUnlapseMember($email, $context)) {
                JoinService::toggleMemberLapsed($email, false, null, $context);
            }
        } finally {
            JoinService::releaseLock($lockFile);
        }
    }

    /**
     * Level-triggered safety net, run daily by cron: converge Action Network
     * state with Stripe for customers with recent subscription activity,
     * catching webhooks that were missed, suppressed or arrived out of order.
     *
     * Only the unambiguous divergence is fixed automatically (a lapsed tag on
     * a paying member with a live subscription, at most one Action Network
     * write per person, under the per-email lock). Everything else — money
     * collected with no CRM record, money collected with no live subscription,
     * a member whose email is bouncing — is alerted via error/warning-level
     * logs, which reach Sentry, because fixing those requires either full join
     * data or a human decision (refund vs reinstate).
     */
    public static function reconcileRecentMemberships($sinceDays = 7)
    {
        global $joinBlockLog;

        $joinBlockLog->info("Running reconcileRecentMemberships");

        if (!Settings::get("USE_ACTION_NETWORK")) {
            $joinBlockLog->info("reconcileRecentMemberships: Action Network integration disabled, nothing to do");
            return;
        }

        $subscriptions = Subscription::all([
            'created' => ['gte' => time() - $sinceDays * 86400],
            'status' => 'all',
            'limit' => 100,
        ]);

        // Group by customer so a re-join sequence (cancelled subscription
        // followed by its live replacement) is judged as one membership.
        $customerIds = [];
        foreach ($subscriptions->autoPagingIterator() as $subscription) {
            $customerIds[$subscription->customer] = true;
        }

        foreach (array_keys($customerIds) as $customerId) {
            try {
                self::reconcileCustomerMembership($customerId);
            } catch (\Exception $e) {
                $joinBlockLog->error(
                    "reconcileRecentMemberships: failed for Stripe customer $customerId: " . $e->getMessage()
                );
            }
        }
    }

    private static function reconcileCustomerMembership($customerId)
    {
        global $joinBlockLog;

        $paidInvoices = \Stripe\Invoice::all([
            'customer' => $customerId,
            'status' => 'paid',
            'limit' => 1,
        ]);
        if (count($paidInvoices->data) === 0) {
            // Abandoned signup (incomplete payment, no charge): correctly has
            // no membership, nothing to reconcile.
            return;
        }

        $email = self::getEmailForCustomer($customerId);
        if (!$email) {
            $joinBlockLog->warning(
                "reconcileRecentMemberships: could not resolve email for Stripe customer $customerId"
            );
            return;
        }

        $hasLiveSubscription = self::customerHasActiveSubscription($customerId);
        $lapsedTag = Settings::get("LAPSED_TAG");

        $person = ActionNetworkService::getPersonSnapshot($email);
        $isLapsed = $person !== null
            && $lapsedTag
            && is_array($person['tags'])
            && in_array($lapsedTag, $person['tags'], true);

        if ($hasLiveSubscription) {
            if ($person === null) {
                $joinBlockLog->error(
                    "reconcileRecentMemberships: $email has a paid, active Stripe subscription but no Action"
                    . " Network record — the join never completed. Investigate and backfill."
                );
                return;
            }

            // A person record on its own is not evidence the join completed: a
            // newsletter or petition signup creates one from an email alone,
            // and that record then masks the missing join. The join flow always
            // collects a name, so its absence means the demographic push never
            // ran. (Update-flow joins deliberately send no name, but those are
            // existing members who already have one.)
            if (empty($person['has_name'])) {
                $joinBlockLog->error(
                    "reconcileRecentMemberships: $email has a paid, active Stripe subscription but no name in"
                    . " Action Network — the join's demographic push never completed. Investigate and backfill."
                );
            }

            if ($isLapsed) {
                $joinBlockLog->error(
                    "reconcileRecentMemberships: $email has an active Stripe subscription but carries the"
                    . " '$lapsedTag' tag — removing it."
                );
                $lockFile = JoinService::acquireLock($email);
                try {
                    $context = ['provider' => 'stripe', 'trigger' => 'reconciliation', 'event' => null];
                    if (JoinService::shouldUnlapseMember($email, $context)) {
                        JoinService::toggleMemberLapsed($email, false, null, $context);
                    }
                } finally {
                    JoinService::releaseLock($lockFile);
                }
            }

            if (($person['email_status'] ?? null) === 'bouncing') {
                $joinBlockLog->warning(
                    "reconcileRecentMemberships: paying member $email has a bouncing email address in Action"
                    . " Network — they are not receiving membership emails. Possible typo at signup."
                );
            }
        } elseif ($person !== null && !$isLapsed && is_array($person['tags'])) {
            $joinBlockLog->error(
                "reconcileRecentMemberships: $email has paid Stripe invoices but no live subscription, and is"
                . " not marked lapsed in Action Network. Refund or reinstate manually."
            );
        }
    }

    private static function resolveTierTagChanges(array $newPlan, ?array $oldPlan): array
    {
        $parseTags = fn($str) => array_filter(array_map('trim', explode(',', $str ?? '')), fn($t) => $t !== '');

        $addTags    = array_values($parseTags($newPlan['add_tags'] ?? ''));
        $removeTags = [];

        if ($oldPlan) {
            $removeTags = array_unique(array_merge($removeTags, $parseTags($oldPlan['add_tags'] ?? '')));
            $removeTags = array_values(array_diff($removeTags, $addTags));
        }

        return ['addTags' => $addTags, 'removeTags' => $removeTags];
    }

    private static function extractPriceChange(array $event): ?array
    {
        $previousAttributes = $event['data']['previous_attributes'] ?? [];
        $previousPriceId = $previousAttributes['items']['data'][0]['price']['id'] ?? null;
        $currentPriceId  = $event['data']['object']['items']['data'][0]['price']['id'] ?? null;

        if (!$previousPriceId || !$currentPriceId || $previousPriceId === $currentPriceId) {
            return null;
        }

        return ['previousPriceId' => $previousPriceId, 'currentPriceId' => $currentPriceId];
    }
}
