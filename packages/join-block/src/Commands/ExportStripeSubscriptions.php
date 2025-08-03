<?php

namespace CommonKnowledge\JoinBlock\Commands;

use CommonKnowledge\JoinBlock\Services\StripeService;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

class ExportStripeSubscriptions
{

    public static function run()
    {
        StripeService::initialise();
        $subs = StripeService::getSubscriptionsForCSVOutput();
        echo join(",", ['"Email"', '"Customer ID"', '"Subscription ID"', '"Subscription Status"', '"Subscription Created"', '"Subscription End"', '"Price ID"', '"Price Name"']);
        echo "\n";
        foreach ($subs as $sub) {
            $row = [
                $sub["email"],
                $sub["customer_id"],
                $sub["subscription_id"],
                $sub["subscription_status"],
                date('Y-m-d H:i:s', $sub["subscription_created"]),
                date('Y-m-d H:i:s', $sub["subscription_end"]),
                $sub["price_id"],
                $sub["price_label"]
            ];
            echo join(",", array_map(function ($c) {
                return '"' . $c . '"';
            }, $row));
            echo "\n";
        }
    }
}
