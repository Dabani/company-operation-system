<?php

namespace App\Services\Hr\Payroll;

use DateTimeImmutable;

/**
 * Part-period pay (hire or termination inside the period). The rule is the ACCOUNTANT's decision, so it is a setting, never built in:
 *   0 = none (the full amount, the preparer is warned), 1 = calendar days, 2 = working days (Monday-Friday minus public holidays).
 * Pure: dates and holidays come in as arguments.
 */
final class Proration
{
    public const NONE = 0;
    public const CALENDAR = 1;
    public const WORKING = 2;

    /**
     * @param array<string,bool> $holidays date => true
     * @return array{factor:string,worked:int,total:int,employed:bool}  factor is a decimal string with 6 places ("1.000000" = the whole period)
     */
    public static function factor(string $from, string $to, ?string $hiredOn, ?string $endedOn, int $mode, array $holidays = []): array
    {
        $start = $hiredOn !== null && $hiredOn > $from ? $hiredOn : $from;
        $end = $endedOn !== null && $endedOn < $to ? $endedOn : $to;
        $total = self::count($from, $to, $mode === self::WORKING ? self::WORKING : self::CALENDAR, $holidays);
        if ($end < $start) {
            return ['factor' => '0.000000', 'worked' => 0, 'total' => $total, 'employed' => false];
        }
        $worked = self::count($start, $end, $mode === self::WORKING ? self::WORKING : self::CALENDAR, $holidays);
        if ($mode === self::NONE || $total === 0) {
            return ['factor' => '1.000000', 'worked' => $worked, 'total' => $total, 'employed' => true];
        }
        return ['factor' => bcdiv((string) $worked, (string) $total, 6), 'worked' => $worked, 'total' => $total, 'employed' => true];
    }

    /** Is the employee paid for only part of the period? (used to warn when the rule is "none"). */
    public static function isPartial(string $from, string $to, ?string $hiredOn, ?string $endedOn): bool
    {
        return ($hiredOn !== null && $hiredOn > $from) || ($endedOn !== null && $endedOn < $to);
    }

    private static function count(string $from, string $to, int $mode, array $holidays): int
    {
        $n = 0;
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            if ($mode === self::CALENDAR || ((int) $d->format('N') <= 5 && empty($holidays[$d->format('Y-m-d')]))) {
                $n++;
            }
        }
        return $n;
    }
}
