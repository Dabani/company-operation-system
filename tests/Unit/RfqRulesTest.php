<?php

namespace Tests\Unit;

use App\Services\Procurement\RfqRules;
use PHPUnit\Framework\TestCase;

class RfqRulesTest extends TestCase
{
    public function test_moves(): void
    {
        $this->assertTrue(RfqRules::allowed('draft', 'open'));
        $this->assertTrue(RfqRules::allowed('closed', 'open'));
        $this->assertTrue(RfqRules::allowed('open', 'awarded'));
        $this->assertFalse(RfqRules::allowed('draft', 'awarded'));
        $this->assertFalse(RfqRules::allowed('awarded', 'cancelled'));
        $this->assertFalse(RfqRules::allowed('cancelled', 'open'));
    }

    public function test_editable_and_quotable(): void
    {
        $this->assertTrue(RfqRules::editable('draft'));
        $this->assertFalse(RfqRules::editable('open'));
        $this->assertTrue(RfqRules::canQuote('open'));
        $this->assertFalse(RfqRules::canQuote('closed'));
    }

    public function test_invitees(): void
    {
        $this->assertTrue(RfqRules::inviteesOk(3, 3, null));
        $this->assertFalse(RfqRules::inviteesOk(2, 3, null));
        $this->assertFalse(RfqRules::inviteesOk(2, 3, 'too short'));
        $this->assertTrue(RfqRules::inviteesOk(1, 3, 'only licensed distributor in the region'));
        $this->assertFalse(RfqRules::inviteesOk(0, 0, 'a perfectly good long reason'));
    }

    public function test_complete_and_valid(): void
    {
        $this->assertTrue(RfqRules::complete(3, 3));
        $this->assertFalse(RfqRules::complete(3, 2));
        $this->assertFalse(RfqRules::complete(0, 0));
        $this->assertTrue(RfqRules::stillValid(null, '2026-11-18'));
        $this->assertTrue(RfqRules::stillValid('2026-11-18', '2026-11-18'));
        $this->assertFalse(RfqRules::stillValid('2026-11-17', '2026-11-18'));
    }

    public function test_lowest_and_award_reason(): void
    {
        $this->assertSame([], RfqRules::lowest([]));
        $this->assertSame(['b'], RfqRules::lowest(['a' => 500, 'b' => 400, 'c' => 450]));
        $this->assertSame(['a', 'c'], RfqRules::lowest(['a' => 400, 'b' => 500, 'c' => 400]));
        $this->assertFalse(RfqRules::awardNeedsReason('c', ['a', 'c']));
        $this->assertTrue(RfqRules::awardNeedsReason('b', ['a', 'c']));
        $this->assertTrue(RfqRules::reasonOk('better delivery record'));
        $this->assertFalse(RfqRules::reasonOk('cheaper'));
    }
}
