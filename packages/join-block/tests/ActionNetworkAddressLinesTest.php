<?php

namespace CommonKnowledge\JoinBlock\Tests;

use CommonKnowledge\JoinBlock\Services\ActionNetworkService;
use PHPUnit\Framework\TestCase;

/**
 * Action Network returns a 500 Internal Server Error when an address line is
 * longer than 50 characters, so a member with a long address paid but never
 * reached the CRM. fitAddressLines() must keep every line within the limit
 * without losing the address.
 */
class ActionNetworkAddressLinesTest extends TestCase
{
    public function testShortLinesAreUnchanged(): void
    {
        $this->assertSame(
            ['54 Abingdon Road', ''],
            ActionNetworkService::fitAddressLines('54 Abingdon Road', '')
        );
    }

    public function testLineAtTheLimitIsUnchanged(): void
    {
        $line = str_repeat('a', 50);
        $this->assertSame([$line, ''], ActionNetworkService::fitAddressLines($line, ''));
    }

    public function testLongLineOneWrapsIntoLineTwo(): void
    {
        $this->assertSame(
            ['T-34 Omkar Sadan Room no.305 Pratiksha Nagar Sion', 'Koliwada'],
            ActionNetworkService::fitAddressLines('T-34 Omkar Sadan Room no.305 Pratiksha Nagar Sion Koliwada', '')
        );
    }

    public function testOverflowIsPrependedToExistingLineTwo(): void
    {
        $this->assertSame(
            ['T-34 Omkar Sadan Room no.305 Pratiksha Nagar Sion', 'Koliwada Mumbai'],
            ActionNetworkService::fitAddressLines('T-34 Omkar Sadan Room no.305 Pratiksha Nagar Sion Koliwada', 'Mumbai')
        );
    }

    public function testLongLineTwoIsTruncated(): void
    {
        [$line1, $line2] = ActionNetworkService::fitAddressLines('Flat 1', str_repeat('word ', 20));
        $this->assertSame('Flat 1', $line1);
        $this->assertLessThanOrEqual(50, mb_strlen($line2));
    }

    public function testUnbrokenLongWordIsHardSplit(): void
    {
        [$line1, $line2] = ActionNetworkService::fitAddressLines(str_repeat('a', 60), '');
        $this->assertSame(str_repeat('a', 50), $line1);
        $this->assertSame(str_repeat('a', 10), $line2);
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $line = str_repeat('é', 50);
        $this->assertSame([$line, ''], ActionNetworkService::fitAddressLines($line, ''));
    }
}
