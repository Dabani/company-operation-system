<?php

namespace App\Services\Hr\Leave;

use DateTimeImmutable;

/**
 * How many leave days does a request use? Pure: the caller supplies the employee's working dates and the public holidays.
 * Basis "working_days": only the employee's working dates that are not public holidays count. Basis "calendar_days": every date counts.
 * A half day can be taken at the start and/or the end of the range (0.5 each); a single date with a half flag is 0.5.
 */
final class LeaveDays
{
    public const WORKING = 'working_days';
    public const CALENDAR = 'calendar_days';

    /**
     * @param array<string,bool> $working  date => is a working date for this employee
     * @param array<string,bool> $holidays date => public holiday
     * @return array{days:string,dates:array<string,string>}  dates: counted date => fraction ("1.00" or "0.50")
     */
    public static function count(string $from, string $to, array $working, array $holidays, string $basis = self::WORKING, bool $startHalf = false, bool $endHalf = false): array
    {
        $dates = [];
        $total = '0.00';
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $counts = $basis === self::CALENDAR ? true : (!empty($working[$k]) && empty($holidays[$k]));
            if (!$counts) {
                continue;
            }
            $half = ($k === $from && $startHalf) || ($k === $to && $endHalf);
            $dates[$k] = $half ? '0.50' : '1.00';
            $total = bcadd($total, $dates[$k], 2);
        }
        return ['days' => $total, 'dates' => $dates];
    }

    /** Monday-Friday as working dates: used when an employee has no schedule at all, so leave still works before schedules are set up. */
    public static function weekdays(string $from, string $to): array
    {
        $out = [];
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = (int) $d->format('N') <= 5;
        }
        return $out;
    }

    /** Do two inclusive date ranges overlap? */
    public static function overlaps(string $aFrom, string $aTo, string $bFrom, string $bTo): bool
    {
        return $aFrom <= $bTo && $bFrom <= $aTo;
    }
}
