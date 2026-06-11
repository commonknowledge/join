<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\StripeService;
use PHPUnit\Framework\TestCase;

class FirstInvoiceClassifierTest extends TestCase
{
    private const NOW = 1700000000;

    public function testRecoverWhenUnprocessedJoinDataExists(): void
    {
        // The saved join data wins regardless of invoice age: the join never
        // completed, so the recovery path must run.
        $this->assertSame(
            'recover',
            StripeService::classifyFirstInvoiceEvent(true, self::NOW - 5, self::NOW)
        );
        $this->assertSame(
            'recover',
            StripeService::classifyFirstInvoiceEvent(true, self::NOW - 10 * 86400, self::NOW)
        );
    }

    public function testDeferWhenInvoiceIsFresh(): void
    {
        // A card-paid first invoice settles seconds after creation, while
        // /join is still in flight: leave Action Network state alone.
        $this->assertSame(
            'defer',
            StripeService::classifyFirstInvoiceEvent(false, self::NOW - 5, self::NOW)
        );
        $this->assertSame(
            'defer',
            StripeService::classifyFirstInvoiceEvent(
                false,
                self::NOW - (StripeService::FIRST_INVOICE_RACE_WINDOW - 1),
                self::NOW
            )
        );
    }

    public function testSettleWhenInvoiceIsOld(): void
    {
        // A first invoice settling days later (e.g. Bacs) cannot race /join.
        $this->assertSame(
            'settle',
            StripeService::classifyFirstInvoiceEvent(
                false,
                self::NOW - StripeService::FIRST_INVOICE_RACE_WINDOW,
                self::NOW
            )
        );
        $this->assertSame(
            'settle',
            StripeService::classifyFirstInvoiceEvent(false, self::NOW - 8 * 86400, self::NOW)
        );
    }

    public function testMissingInvoiceCreatedTimestampSettles(): void
    {
        // A zero/absent created timestamp must not be mistaken for a fresh
        // invoice, or the event would be dropped forever.
        $this->assertSame(
            'settle',
            StripeService::classifyFirstInvoiceEvent(false, 0, self::NOW)
        );
    }
}
