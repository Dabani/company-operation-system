<?php

namespace Tests\Unit;

use App\Services\Billing\InvoiceMath;
use PHPUnit\Framework\TestCase;

/** Golden scenarios for invoice arithmetic: rounding once, tax once, settlement status. */
class InvoiceMathTest extends TestCase
{
    public function test_time_rounding_modes_and_increments(): void
    {
        $this->assertSame(['120.00', '2.0000', '50000.00'], array_values(array_intersect_key(InvoiceMath::timeLine(7620, '25000.00', 15, 'half_up'), array_flip(['rounded_minutes', 'quantity', 'amount']))));
        $this->assertSame('135.00', InvoiceMath::timeLine(7680, '25000.00', 15, 'half_up')['rounded_minutes']);
        $this->assertSame('75.00', InvoiceMath::timeLine(3660, '25000.00', 15, 'up')['rounded_minutes']);
        $this->assertSame('60.00', InvoiceMath::timeLine(4440, '25000.00', 15, 'down')['rounded_minutes']);
        $this->assertSame('3500.00', InvoiceMath::timeLine(1230, '10000.00', 1, 'half_up')['amount']);
        $this->assertSame('3333.33', InvoiceMath::timeLine(1200, '10000.00', 5, 'half_up')['amount']);     // 20 minutes: no 0.3333 h drift
    }

    public function test_totals_round_tax_once_on_the_document(): void
    {
        $this->assertSame(['subtotal' => '106250.01', 'tax_total' => '19125.00', 'total' => '125375.01'], InvoiceMath::totals(['50000.00', '56250.00', '0.01'], '18'));
        $this->assertSame('100.00', InvoiceMath::totals(['100.00'], null)['total']);
    }

    public function test_settlement_status(): void
    {
        $s = fn ($t, $p, $c, $d) => InvoiceMath::settlement($t, $p, $c, $d, '2026-11-03');
        $this->assertSame('issued', $s('118.00', '0.00', '0.00', '2026-12-01'));
        $this->assertSame('partially_paid', $s('118.00', '50.00', '0.00', '2026-12-01'));
        $this->assertSame('overdue', $s('118.00', '0.00', '0.00', '2026-11-01'));
        $this->assertSame('paid', $s('118.00', '118.00', '0.00', '2026-11-01'));
        $this->assertSame('cancelled', $s('118.00', '0.00', '118.00', '2026-11-01'));
        $this->assertSame('paid', $s('118.00', '50.00', '68.00', '2026-11-01'));
        $this->assertSame('50.00', InvoiceMath::balance('118.00', '50.00', '18.00'));
    }
}
