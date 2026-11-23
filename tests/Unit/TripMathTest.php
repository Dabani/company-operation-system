<?php

namespace Tests\Unit;

use App\Services\Hr\Trips\TripMath as T;
use PHPUnit\Framework\TestCase;

/** Pure trip logic. Rates and amounts are TEST INPUTS: real rate tables are entered by finance/HR inside COPS. */
class TripMathTest extends TestCase
{
    public function test_days_include_both_ends(): void
    {
        $this->assertSame(1, T::days('2026-12-01', '2026-12-01'));
        $this->assertSame(5, T::days('2026-12-01', '2026-12-05'));
        $this->assertSame(0, T::days('2026-12-05', '2026-12-01'));
        $this->assertSame(32, T::days('2026-12-01', '2027-01-01'));       // 31 days of December + 1 January
    }

    public function test_per_diem_is_rate_times_days_rounded_to_cents(): void
    {
        $this->assertSame('150000.00', T::perDiem('30000.00', '2026-12-01', '2026-12-05'));
        $this->assertSame('0.00', T::perDiem('30000.00', '2026-12-05', '2026-12-01'));
        $this->assertSame('100.00', T::perDiem('33.3333', '2026-12-01', '2026-12-03'));
    }

    private function rates(): array
    {
        $r = fn (array $o) => $o + ['kind' => 'per_diem', 'zone' => 'domestic', 'grade' => null, 'amount' => '20000', 'currency' => 'RWF', 'valid_from' => '2026-01-01', 'valid_to' => null];
        return [
            $r([]),
            $r(['amount' => '30000', 'grade' => 'G5']),
            $r(['amount' => '25000', 'valid_from' => '2026-07-01']),
            $r(['amount' => '90', 'currency' => 'USD', 'zone' => 'international']),
            $r(['amount' => '10000', 'valid_from' => '2024-01-01', 'valid_to' => '2025-12-31']),
        ];
    }

    public function test_the_latest_valid_general_rate_applies(): void
    {
        $this->assertSame('25000', T::pickRate($this->rates(), 'per_diem', 'domestic', null, '2026-11-15', 'RWF')['amount']);
        $this->assertSame('20000', T::pickRate($this->rates(), 'per_diem', 'domestic', null, '2026-03-01', 'RWF')['amount']);   // before the July revision
        $this->assertSame('10000', T::pickRate($this->rates(), 'per_diem', 'domestic', null, '2025-06-01', 'RWF')['amount']);   // an older rate for an older trip
    }

    public function test_a_grade_rate_beats_a_general_rate(): void
    {
        $this->assertSame('30000', T::pickRate($this->rates(), 'per_diem', 'domestic', 'G5', '2026-11-15', 'RWF')['amount']);
        $this->assertSame('25000', T::pickRate($this->rates(), 'per_diem', 'domestic', 'G3', '2026-11-15', 'RWF')['amount']);   // another grade: general rate
    }

    public function test_no_matching_rate_is_null_never_guessed(): void
    {
        $this->assertNull(T::pickRate($this->rates(), 'per_diem', 'regional', null, '2026-11-15', 'RWF'));
        $this->assertNull(T::pickRate($this->rates(), 'accommodation', 'domestic', null, '2026-11-15', 'RWF'));
        $this->assertNull(T::pickRate($this->rates(), 'per_diem', 'international', null, '2026-11-15', 'RWF'));      // only a USD rate: no silent conversion
        $this->assertSame('90', T::pickRate($this->rates(), 'per_diem', 'international', null, '2026-11-15', 'USD')['amount']);
        $this->assertNull(T::pickRate($this->rates(), 'per_diem', 'domestic', null, '2023-01-01', 'RWF'));          // before any rate existed
    }

    public function test_settlement_direction(): void
    {
        $this->assertSame(['balance' => '20000.00', 'direction' => 'pay_employee'], T::settlement('120000.00', '100000.00'));
        $this->assertSame(['balance' => '-30000.00', 'direction' => 'employee_owes'], T::settlement('70000.00', '100000.00'));
        $this->assertSame(['balance' => '0.00', 'direction' => 'even'], T::settlement('100000.00', '100000.00'));
        $this->assertSame(['balance' => '50000.00', 'direction' => 'pay_employee'], T::settlement('50000.00', '0.00'));   // no advance
    }

    public function test_advance_due_date_and_lateness(): void
    {
        $this->assertSame('2026-12-19', T::dueOn('2026-12-05', 14));
        $this->assertFalse(T::overdue('2026-12-19', false, '2026-12-19'));       // due today is not late yet
        $this->assertTrue(T::overdue('2026-12-19', false, '2026-12-20'));
        $this->assertFalse(T::overdue('2026-12-19', true, '2027-03-01'));        // retired
        $this->assertFalse(T::overdue(null, false, '2027-03-01'));               // not paid out yet: no due date
        $this->assertSame(0, T::daysLate('2026-12-19', '2026-12-19'));
        $this->assertSame(3, T::daysLate('2026-12-19', '2026-12-22'));
    }

    public function test_expenses_must_fall_inside_the_trip_with_a_day_of_grace(): void
    {
        $this->assertTrue(T::withinTrip('2026-12-03', '2026-12-02', '2026-12-05'));
        $this->assertTrue(T::withinTrip('2026-12-01', '2026-12-02', '2026-12-05'));      // the day before (travel, ticket)
        $this->assertTrue(T::withinTrip('2026-12-06', '2026-12-02', '2026-12-05'));
        $this->assertFalse(T::withinTrip('2026-11-30', '2026-12-02', '2026-12-05'));
        $this->assertFalse(T::withinTrip('2026-12-07', '2026-12-02', '2026-12-05'));
        $this->assertFalse(T::withinTrip('2026-12-07', '2026-12-02', '2026-12-05', 1));
        $this->assertTrue(T::withinTrip('2026-12-07', '2026-12-02', '2026-12-05', 2));
    }
}
