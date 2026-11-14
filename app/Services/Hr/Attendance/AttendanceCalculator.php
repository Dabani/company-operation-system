<?php

namespace App\Services\Hr\Attendance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure attendance arithmetic (unit-tested, no database). Times are UTC strings 'Y-m-d H:i:s'; shift times are local wall-clock 'H:i' in the organization timezone.
 * Lateness counts from the scheduled start once the grace period is exceeded. Overtime is worked time beyond the expected minutes (or all worked time on a day
 * without a shift) when it reaches the configured minimum: it is a measurement for payroll, never a pay decision.
 */
final class AttendanceCalculator
{
    public const WORKED = ['present', 'late', 'incomplete', 'day_off'];

    private static function ts(string $utc): int { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp(); }
    private static function fmt(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }

    /** Local wall-clock time on a local date -> UTC timestamp. */
    public static function localToUtc(string $tz, string $date, string $time): int
    {
        return (new DateTimeImmutable("$date $time", new DateTimeZone($tz)))->getTimestamp();
    }

    /** Local calendar date (Y-m-d) of a UTC time. */
    public static function localDate(string $tz, string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    }

    /** Paid minutes a shift expects: (end - start) minus the unpaid break; an end at or before the start means the shift crosses midnight. */
    public static function expectedMinutes(string $start, string $end, int $break): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        $span = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($span <= 0) {
            $span += 1440;
        }
        return max(0, $span - $break);
    }

    /**
     * @param array{tz:string,date:string,today:string,now:string,shift:?array{start:string,end:string,break:int,auto_deduct:bool,grace:int},holiday:bool,leave:bool,pairs:array<int,array{0:string,1:?string}>,overtime_min:int} $in
     * @return array{status:?string,expected:int,worked:int,late:int,overtime:int,first_in:?string,last_out:?string,open:bool}
     */
    public static function day(array $in): array
    {
        $shift = $in['shift'];
        $off = $in['holiday'] || $in['leave'];
        $working = $shift !== null && !$off;
        $expected = $working ? self::expectedMinutes($shift['start'], $shift['end'], $shift['break']) : 0;
        $past = $in['date'] < $in['today'];

        $seconds = 0; $complete = 0; $open = false; $firstIn = null; $lastOut = null;
        foreach ($in['pairs'] as [$i, $o]) {
            $firstIn = $firstIn === null || $i < $firstIn ? $i : $firstIn;
            if ($o !== null) {
                $seconds += max(0, self::ts($o) - self::ts($i));
                $complete++;
                $lastOut = $lastOut === null || $o > $lastOut ? $o : $lastOut;
            } else {
                $open = true;
                if (!$past) {
                    $seconds += max(0, self::ts($in['now']) - self::ts($i));      // today's running pair counts up to now
                }
            }
        }
        $worked = intdiv($seconds, 60);
        if ($working && $shift['auto_deduct'] && $complete === 1 && !$open && $worked > $shift['break']) {
            $worked -= $shift['break'];
        }

        $late = 0;
        if ($working && $firstIn !== null) {
            $diff = intdiv(self::ts($firstIn) - self::localToUtc($in['tz'], $in['date'], $shift['start']), 60);
            $late = $diff > $shift['grace'] ? $diff : 0;
        }

        $overtime = 0;
        if ($firstIn !== null) {
            $extra = $working ? $worked - $expected : $worked;
            $overtime = $extra > 0 && $extra >= $in['overtime_min'] ? $extra : 0;
        }

        $incomplete = $open && $past;
        if ($in['holiday']) {
            $status = 'holiday';
        } elseif ($in['leave']) {
            $status = 'leave';
        } elseif ($firstIn !== null) {
            $status = !$working ? 'day_off' : ($incomplete ? 'incomplete' : ($late > 0 ? 'late' : 'present'));
        } else {
            $status = $working && $past ? 'absent' : null;                  // nothing is recorded for today until something happens
        }

        return ['status' => $status, 'expected' => $expected, 'worked' => $worked, 'late' => $late, 'overtime' => $overtime,
            'first_in' => $firstIn, 'last_out' => $lastOut, 'open' => $open];
    }
}
