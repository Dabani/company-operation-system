<?php

namespace Tests\Unit;

use App\Services\Procurement\InvoiceRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 8c: pure supplier invoice and three-way match rules. */
class InvoiceRulesTest extends TestCase
{
    public function test_lifecycle(): void
    {
        $this->assertTrue(R::allowed('received', 'matched'));
        $this->assertTrue(R::allowed('on_hold', 'matched'));
        $this->assertTrue(R::allowed('matched', 'approved'));
        $this->assertTrue(R::allowed('approved', 'paid'));
        $this->assertTrue(R::allowed('approved', 'cancelled'));
        $this->assertFalse(R::allowed('on_hold', 'approved'));      // a hold must be cleared first
        $this->assertFalse(R::allowed('matched', 'paid'));          // approval comes before payment
        $this->assertFalse(R::allowed('paid', 'cancelled'));
        $this->assertTrue(R::counts('on_hold'));
        $this->assertFalse(R::counts('rejected'));
        $this->assertFalse(R::counts('cancelled'));
    }

    public function test_duplicate_key(): void
    {
        $this->assertSame('INV001', R::numberKey('inv-001'));
        $this->assertSame('INV001', R::numberKey(' INV 001 '));
        $this->assertSame('INV001', R::numberKey('INV/001'));
        $this->assertNotSame(R::numberKey('INV-001'), R::numberKey('INV-0010'));
    }

    public function test_clean_line_matches(): void
    {
        $this->assertSame([], R::matchLine(10000, 0, 10000, 5000, 5000, 0));
        $this->assertSame([], R::matchLine(10000, 4000, 6000, 5000, 5000, 0));          // the rest of what was accepted
        $this->assertSame([], R::matchLine(10000, 0, 10000, 5000, 5250, 5));            // 5 % tolerance
        $this->assertSame([], R::matchLine(10000, 0, 10000, 5000, 4000, 0));            // cheaper is fine
    }

    public function test_discrepancies(): void
    {
        $this->assertSame(['not_received'], array_column(R::matchLine(0, 0, 1000, 5000, 5000, 0), 'code'));
        $this->assertSame(['qty_over'], array_column(R::matchLine(10000, 4000, 6001, 5000, 5000, 0), 'code'));
        $this->assertSame(['price_over'], array_column(R::matchLine(10000, 0, 10000, 5000, 5251, 5), 'code'));
        $this->assertSame(['qty_over', 'price_over'], array_column(R::matchLine(5000, 0, 6000, 5000, 6000, 0), 'code'));
    }

    public function test_tax(): void
    {
        $this->assertSame(1800, R::expectedTaxCents(10000, 18));
        $this->assertTrue(R::taxOk(1800, 10000, 18, 1));
        $this->assertTrue(R::taxOk(1801, 10000, 18, 1));
        $this->assertFalse(R::taxOk(1900, 10000, 18, 1));
        $this->assertTrue(R::taxOk(5, 10000, 0, 1));                                     // rate 0: not checked
    }

    public function test_segregation_of_duties(): void
    {
        $this->assertFalse(R::approverAllowed('rec', 'buyer', 'rec'));
        $this->assertFalse(R::approverAllowed('rec', 'buyer', 'buyer'));
        $this->assertTrue(R::approverAllowed('rec', 'buyer', 'fin'));
        $this->assertFalse(R::exceptionDeciderAllowed('rec', 'rec'));
        $this->assertTrue(R::exceptionDeciderAllowed('rec', 'fin'));
        $this->assertFalse(R::payerAllowed('fin', 'fin'));
        $this->assertTrue(R::payerAllowed('fin', 'pay'));
        $this->assertSame('on_hold', R::outcome([['code' => 'qty_over', 'detail' => '']]));
        $this->assertSame('matched', R::outcome([]));
    }
}
