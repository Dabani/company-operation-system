<?php

namespace Tests\Unit;

use App\Services\Events\ExpenseRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7e: pure event expense rules. */
class ExpenseRulesTest extends TestCase
{
    public function test_lifecycle(): void
    {
        $this->assertTrue(R::allowed('draft', 'pending_approval'));
        $this->assertTrue(R::allowed('pending_approval', 'approved'));
        $this->assertTrue(R::allowed('pending_approval', 'draft'));
        $this->assertTrue(R::allowed('rejected', 'draft'));
        $this->assertFalse(R::allowed('draft', 'approved'));
        $this->assertFalse(R::allowed('approved', 'draft'));
        $this->assertTrue(R::editable('draft'));
        $this->assertFalse(R::editable('pending_approval'));
    }

    public function test_money_is_counted_in_cents(): void
    {
        $this->assertSame(1999, R::toCents('19.99'));
        $this->assertSame(1010, R::toCents(10.1));
        $this->assertSame(5, R::toCents('0.05'));
        $this->assertTrue(R::amountValid(1));
        $this->assertFalse(R::amountValid(0));
    }

    public function test_budget(): void
    {
        $this->assertSame(2500, R::remainingCents(10000, 7500));
        $this->assertSame(-500, R::remainingCents(10000, 10500));
        $this->assertNull(R::remainingCents(null, 7500));
        $this->assertFalse(R::exceedsBudget(10000, 7500, 2500), 'exactly the budget is fine');
        $this->assertTrue(R::exceedsBudget(10000, 7500, 2501));
        $this->assertFalse(R::exceedsBudget(null, 999999, 999999), 'no budget, no limit');
    }

    public function test_receipts(): void
    {
        $this->assertFalse(R::receiptRequired(0, 999999), 'threshold 0 switches the rule off');
        $this->assertTrue(R::receiptRequired(5000, 5000));
        $this->assertFalse(R::receiptRequired(5000, 4999));
    }
}
