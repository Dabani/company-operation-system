<?php

namespace Tests\Unit;

use App\Services\Procurement\ProcurementRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 8a: pure supplier and requisition rules. */
class ProcurementRulesTest extends TestCase
{
    public function test_supplier_lifecycle(): void
    {
        $this->assertTrue(R::supplierAllowed('draft', 'under_review'));
        $this->assertTrue(R::supplierAllowed('under_review', 'approved'));
        $this->assertTrue(R::supplierAllowed('approved', 'suspended'));
        $this->assertTrue(R::supplierAllowed('suspended', 'approved'));
        $this->assertTrue(R::supplierAllowed('blocked', 'under_review'));
        $this->assertFalse(R::supplierAllowed('blocked', 'approved'));
        $this->assertFalse(R::supplierAllowed('draft', 'approved'));
        $this->assertFalse(R::supplierAllowed('archived', 'draft'));
    }

    public function test_only_some_supplier_moves_are_manual(): void
    {
        $this->assertTrue(R::supplierManual('approved', 'suspended'));
        $this->assertTrue(R::supplierManual('suspended', 'approved'));   // reinstating
        $this->assertTrue(R::supplierManual('approved', 'blocked'));
        $this->assertFalse(R::supplierManual('under_review', 'approved')); // only the approval can do this
        $this->assertFalse(R::supplierManual('draft', 'under_review'));    // the submit action does this
        $this->assertTrue(R::supplierReasonRequired('blocked'));
        $this->assertFalse(R::supplierReasonRequired('archived'));
    }

    public function test_requisition_lifecycle(): void
    {
        $this->assertTrue(R::requisitionAllowed('draft', 'pending_approval'));
        $this->assertTrue(R::requisitionAllowed('pending_approval', 'approved'));
        $this->assertTrue(R::requisitionAllowed('pending_approval', 'draft'));
        $this->assertTrue(R::requisitionAllowed('rejected', 'draft'));
        $this->assertTrue(R::requisitionAllowed('approved', 'cancelled'));
        $this->assertFalse(R::requisitionAllowed('approved', 'draft'));
        $this->assertFalse(R::requisitionAllowed('cancelled', 'draft'));
        $this->assertTrue(R::requisitionEditable('draft'));
        $this->assertFalse(R::requisitionEditable('pending_approval'));
    }

    public function test_money_in_cents(): void
    {
        $this->assertSame(1050, R::toCents('10.50'));
        $this->assertSame(2500, R::lineCents('2.5', '10.00'));
        $this->assertSame(3333, R::lineCents('3', '11.11'));
        $this->assertSame(3, R::lineCents('0.333', '0.10'));
        $this->assertSame(5500, R::totalCents([['quantity' => 2, 'unit_price' => '10'], ['quantity' => 1, 'unit_price' => '35.00']]));
        $this->assertSame(0, R::totalCents([]));
    }

    public function test_budget_check(): void
    {
        $this->assertFalse(R::exceedsBudget(null, 999999, 999999));
        $this->assertFalse(R::exceedsBudget(10000, 4000, 6000));
        $this->assertTrue(R::exceedsBudget(10000, 4000, 6001));
    }

    public function test_splitting_is_flagged(): void
    {
        $this->assertTrue(R::splitSuspected(100000, 40000, 70000));
        $this->assertFalse(R::splitSuspected(100000, 40000, 0));          // nothing recent
        $this->assertFalse(R::splitSuspected(100000, 40000, 30000));      // still under together
        $this->assertFalse(R::splitSuspected(100000, 120000, 70000));     // already above on its own: it gets the normal approval
        $this->assertFalse(R::splitSuspected(0, 40000, 70000));           // switched off
    }

    public function test_missing_data_blocks_submission(): void
    {
        $ok = ['title' => 'Laptops', 'purpose' => '<p>Replace old ones</p>', 'delivery_location' => 'Kigali HQ', 'department_id' => 'd', 'procurement_type_id' => 't', 'currency_code' => 'RWF', 'needed_by' => '2026-12-20'];
        $this->assertSame([], R::missing($ok, 2, 5000, '2026-11-20'));
        $this->assertSame(['purpose'], R::missing(['purpose' => '<p> </p>'] + $ok, 2, 5000, '2026-11-20'));
        $this->assertSame(['needed_by'], R::missing(['needed_by' => '2026-11-01'] + $ok, 2, 5000, '2026-11-20'));
        $this->assertSame(['lines'], R::missing($ok, 0, 0, '2026-11-20'));
        $this->assertSame(['total'], R::missing($ok, 1, 0, '2026-11-20'));
    }

    public function test_bank_change_needs_a_second_person(): void
    {
        $this->assertFalse(R::canVerifyBank('u1', 'u1'));
        $this->assertTrue(R::canVerifyBank('u1', 'u2'));
        $this->assertSame('••••••7890', R::maskAccount('123456 7890'));
        $this->assertSame('••••', R::maskAccount('1234'));
    }
}
