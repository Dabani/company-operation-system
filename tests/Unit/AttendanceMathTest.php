<?php

namespace Tests\Unit;

use App\Services\Hr\Attendance\AttendanceCalculator as C;
use App\Services\Hr\Attendance\Cidr;
use App\Services\Hr\Attendance\EventPairer;
use PHPUnit\Framework\TestCase;

class AttendanceMathTest extends TestCase
{
    private const TZ = 'Africa/Kigali';                         // UTC+2: 08:00 local = 06:00Z
    private const DAY = '2026-11-02';                           // a Monday

    private function shift(array $o = []): array { return $o + ['start' => '08:00', 'end' => '17:00', 'break' => 60, 'auto_deduct' => false, 'grace' => 10]; }

    private function day(array $over = []): array
    {
        return C::day($over + ['tz' => self::TZ, 'date' => self::DAY, 'today' => '2026-11-10', 'now' => '2026-11-10 09:00:00', 'shift' => $this->shift(),
            'holiday' => false, 'leave' => false, 'pairs' => [], 'overtime_min' => 15]);
    }

    public function test_expected_minutes_handle_breaks_and_night_shifts(): void
    {
        $this->assertSame(480, C::expectedMinutes('08:00', '17:00', 60));
        $this->assertSame(480, C::expectedMinutes('22:00', '06:00', 0));      // crosses midnight
        $this->assertSame(240, C::expectedMinutes('08:00', '12:00', 0));
    }

    public function test_local_time_conversion_uses_the_organization_timezone(): void
    {
        $this->assertSame('2026-11-02 06:00:00', gmdate('Y-m-d H:i:s', C::localToUtc(self::TZ, self::DAY, '08:00')));
        $this->assertSame('2026-11-03', C::localDate(self::TZ, '2026-11-02 22:30:00'));    // 00:30 local the next day
    }

    public function test_on_time_day_with_overtime(): void
    {
        $r = $this->day(['pairs' => [['2026-11-02 06:00:00', '2026-11-02 15:00:00']]]);   // 08:00-17:00, nine hours, no break punched
        $this->assertSame('present', $r['status']);
        $this->assertSame(480, $r['expected']);
        $this->assertSame(540, $r['worked']);
        $this->assertSame(0, $r['late']);
        $this->assertSame(60, $r['overtime']);
    }

    public function test_automatic_break_deduction_only_for_a_single_long_pair(): void
    {
        $one = $this->day(['shift' => $this->shift(['auto_deduct' => true]), 'pairs' => [['2026-11-02 06:00:00', '2026-11-02 15:00:00']]]);
        $this->assertSame(480, $one['worked']);
        $this->assertSame(0, $one['overtime']);
        $two = $this->day(['shift' => $this->shift(['auto_deduct' => true]), 'pairs' => [['2026-11-02 06:00:00', '2026-11-02 10:00:00'], ['2026-11-02 11:00:00', '2026-11-02 15:00:00']]]);
        $this->assertSame(480, $two['worked']);                                            // 2 x 4h: the break was punched, so nothing is deducted again
    }

    public function test_lateness_counts_from_the_start_once_the_grace_is_exceeded(): void
    {
        $inside = $this->day(['pairs' => [['2026-11-02 06:10:00', '2026-11-02 15:00:00']]]);   // exactly the 10 minute grace
        $this->assertSame(0, $inside['late']);
        $this->assertSame('present', $inside['status']);
        $late = $this->day(['pairs' => [['2026-11-02 06:25:00', '2026-11-02 15:00:00']]]);
        $this->assertSame(25, $late['late']);
        $this->assertSame('late', $late['status']);
    }

    public function test_overtime_below_the_minimum_is_ignored(): void
    {
        $r = $this->day(['pairs' => [['2026-11-02 06:00:00', '2026-11-02 14:10:00']]]);       // 490 worked, 10 over
        $this->assertSame(0, $r['overtime']);
    }

    public function test_absence_is_only_detected_once_the_day_is_over(): void
    {
        $this->assertSame('absent', $this->day()['status']);
        $this->assertNull($this->day(['date' => '2026-11-10'])['status']);                    // today: nothing yet
        $this->assertNull($this->day(['shift' => null])['status']);                             // no shift: nothing expected
    }

    public function test_holiday_and_leave_are_not_absences(): void
    {
        $h = $this->day(['holiday' => true]);
        $this->assertSame('holiday', $h['status']);
        $this->assertSame(0, $h['expected']);
        $worked = $this->day(['holiday' => true, 'pairs' => [['2026-11-02 06:00:00', '2026-11-02 10:00:00']]]);
        $this->assertSame('holiday', $worked['status']);
        $this->assertSame(240, $worked['overtime']);                                           // all of it is extra: payroll rules decide what it is worth
        $this->assertSame('leave', $this->day(['leave' => true])['status']);
    }

    public function test_work_on_a_day_without_a_shift(): void
    {
        $r = $this->day(['shift' => null, 'pairs' => [['2026-11-02 06:00:00', '2026-11-02 09:00:00']]]);
        $this->assertSame('day_off', $r['status']);
        $this->assertSame(180, $r['worked']);
        $this->assertSame(180, $r['overtime']);
        $this->assertSame(0, $r['late']);
    }

    public function test_a_missing_clock_out_is_incomplete_after_the_day_and_running_today(): void
    {
        $past = $this->day(['pairs' => [['2026-11-02 06:00:00', null]]]);
        $this->assertSame('incomplete', $past['status']);
        $this->assertSame(0, $past['worked']);
        $this->assertTrue($past['open']);
        $today = $this->day(['date' => '2026-11-10', 'pairs' => [['2026-11-10 06:00:00', null]]]);   // now = 09:00Z: three hours so far
        $this->assertSame('present', $today['status']);
        $this->assertSame(180, $today['worked']);
        $this->assertTrue($today['open']);
    }

    public function test_night_shift_lateness_is_measured_against_the_evening_start(): void
    {
        $r = $this->day(['shift' => $this->shift(['start' => '22:00', 'end' => '06:00', 'break' => 0, 'grace' => 0]), 'pairs' => [['2026-11-02 20:05:00', '2026-11-03 04:00:00']]]);   // 22:05 -> 06:00 local
        $this->assertSame(5, $r['late']);
        $this->assertSame(475, $r['worked']);
        $this->assertSame(480, $r['expected']);
    }

    public function test_pairing_of_clock_events(): void
    {
        $w = 20 * 3600;
        $ev = fn (string $d, string $t) => ['dir' => $d, 'at' => "2026-11-02 $t"];
        $this->assertSame([['2026-11-02 06:00:00', '2026-11-02 10:00:00'], ['2026-11-02 11:00:00', '2026-11-02 15:00:00']],
            EventPairer::pair([$ev('in', '06:00:00'), $ev('out', '10:00:00'), $ev('in', '11:00:00'), $ev('out', '15:00:00')], $w));
        $this->assertSame([['2026-11-02 06:00:00', null], ['2026-11-02 07:00:00', '2026-11-02 12:00:00']],                      // a forgotten out: the first in stays open
            EventPairer::pair([$ev('in', '06:00:00'), $ev('in', '07:00:00'), $ev('out', '12:00:00')], $w));
        $this->assertSame([['2026-11-02 06:00:00', '2026-11-02 08:00:00']], EventPairer::pair([$ev('out', '05:00:00'), $ev('in', '06:00:00'), $ev('out', '08:00:00')], $w));   // an orphan out is ignored
        $this->assertSame([['2026-11-02 00:00:00', null]], EventPairer::pair([$ev('in', '00:00:00'), ['dir' => 'out', 'at' => '2026-11-03 04:00:00']], $w));            // out after the window
    }

    public function test_direction_of_an_unlabelled_punch(): void
    {
        $w = 20 * 3600;
        $this->assertSame('in', EventPairer::infer(null, '2026-11-02 06:00:00', $w));
        $this->assertSame('out', EventPairer::infer(['dir' => 'in', 'at' => '2026-11-02 06:00:00'], '2026-11-02 15:00:00', $w));
        $this->assertSame('in', EventPairer::infer(['dir' => 'out', 'at' => '2026-11-02 15:00:00'], '2026-11-03 06:00:00', $w));
        $this->assertSame('in', EventPairer::infer(['dir' => 'in', 'at' => '2026-11-01 06:00:00'], '2026-11-02 15:00:00', $w));   // the old in expired
    }

    public function test_network_ranges(): void
    {
        $this->assertTrue(Cidr::contains('10.0.0.0/24', '10.0.0.77'));
        $this->assertFalse(Cidr::contains('10.0.0.0/24', '10.0.1.1'));
        $this->assertTrue(Cidr::contains('10.0.0.0/22', '10.0.3.4'));
        $this->assertTrue(Cidr::contains('192.168.1.5', '192.168.1.5'));                        // a single address
        $this->assertFalse(Cidr::contains('192.168.1.5', '192.168.1.6'));
        $this->assertTrue(Cidr::contains('2001:db8::/32', '2001:db8:1::1'));
        $this->assertFalse(Cidr::contains('2001:db8::/32', '2001:db9::1'));
        $this->assertFalse(Cidr::contains('10.0.0.0/24', '2001:db8::1'));                        // different families never match
        $this->assertTrue(Cidr::valid('10.0.0.0/8'));
        $this->assertFalse(Cidr::valid('10.0.0.0/33'));
        $this->assertFalse(Cidr::valid('not an ip'));
    }
}
