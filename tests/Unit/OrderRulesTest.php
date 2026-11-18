<?php

namespace Tests\Unit;

use App\Services\Procurement\OrderRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 8b: pure purchase order and receipt rules. */
class OrderRulesTest extends TestCase
{
    public function test_lifecycle(): void
    {
        $this->assertTrue(R::allowed('draft', 'pending_approval'));
        $this->assertTrue(R::allowed('pending_approval', 'draft'));
        $this->assertTrue(R::allowed('approved', 'issued'));
        $this->assertTrue(R::allowed('issued', 'partially_received'));
        $this->assertTrue(R::allowed('partially_received', 'closed'));
        $this->assertTrue(R::allowed('received', 'closed'));
        $this->assertFalse(R::allowed('draft', 'issued'));
        $this->assertFalse(R::allowed('partially_received', 'cancelled'));
        $this->assertFalse(R::allowed('closed', 'draft'));
        $this->assertTrue(R::editable('draft'));
        $this->assertFalse(R::editable('approved'));
        $this->assertTrue(R::canReceive('issued'));
        $this->assertTrue(R::canReceive('partially_received'));
        $this->assertFalse(R::canReceive('approved'));
    }

    public function test_remaining_and_receipt_line(): void
    {
        $this->assertSame(4000, R::remaining('10', '6'));
        $this->assertSame(0, R::remaining('10', '12'));
        $this->assertTrue(R::receiptLineValid('4', '4', 4000));
        $this->assertTrue(R::receiptLineValid('4', '3', 4000));      // one rejected
        $this->assertTrue(R::receiptLineValid('1.5', '0', 4000));    // all rejected
        $this->assertFalse(R::receiptLineValid('5', '5', 4000));     // more than still to come
        $this->assertFalse(R::receiptLineValid('4', '5', 4000));     // accepted more than received
        $this->assertFalse(R::receiptLineValid('0', '0', 4000));
        $this->assertFalse(R::receiptLineValid('2', '-1', 4000));
    }

    public function test_status_after_receipt(): void
    {
        $this->assertSame('received', R::statusAfterReceipt([['quantity' => 10, 'accepted' => 10], ['quantity' => '2.5', 'accepted' => '2.5']]));
        $this->assertSame('partially_received', R::statusAfterReceipt([['quantity' => 10, 'accepted' => 10], ['quantity' => 2, 'accepted' => 1]]));
        $this->assertSame('issued', R::statusAfterReceipt([['quantity' => 10, 'accepted' => 0]]));
        $this->assertTrue(R::hasReceipts([['quantity' => 1, 'accepted' => '0.001']]));
        $this->assertFalse(R::hasReceipts([['quantity' => 1, 'accepted' => 0]]));
    }

    public function test_tolerance(): void
    {
        $this->assertTrue(R::withinTolerance(100000, 100000, 0));
        $this->assertFalse(R::withinTolerance(100001, 100000, 0));
        $this->assertTrue(R::withinTolerance(105000, 100000, 5));
        $this->assertFalse(R::withinTolerance(105001, 100000, 5));
        $this->assertTrue(R::withinTolerance(50000, 100000, 0));     // cheaper is always fine
    }

    public function test_receiver_is_independent_of_the_buyer(): void
    {
        $this->assertFalse(R::receiverAllowed('u1', 'u1'));
        $this->assertTrue(R::receiverAllowed('u1', 'u2'));
        $this->assertTrue(R::receiverAllowed(null, 'u2'));
    }
}
