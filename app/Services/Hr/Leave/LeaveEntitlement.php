<?php

namespace App\Services\Hr\Leave;

use App\Services\MoneyService;
use DateTimeImmutable;

/**
 * Annual entitlement and monthly accrual (Guide 5.4). Pure; every number comes in as an argument, nothing is a built-in legal value.
 * Money-style decimal strings and bcmath are used so that the totals are exact and repeatable.
 */
final class LeaveEntitlement
{
    public static function completedYears(string $from, string $on): int
    {
        return $on < $from ? 0 : (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($on))->y;
    }

    public static function completedMonths(string $from, string $on): int
    {
        if ($on < $from) {
            return 0;
        }
        $d = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($on));
        return $d->y * 12 + $d->m;
    }

    public static function ageYears(string $birthDate, string $on): int
    {
        return self::completedYears($birthDate, $on);
    }

    /**
     * Days per leave year for one employee.
     * base + increment x (whole service periods), never above the cap (but a cap below the base never cuts the base), then pro-rated
     * when the contractual weekly hours are below the full-time hours. Returns "0.00" when there is no base.
     *
     * @param array{base:?string,increment_days:?string,increment_years:?int,cap:?string,service_years:int,weekly_hours:?string,full_time_hours:?string} $p
     */
    public static function annualDays(array $p): string
    {
        $base = $p['base'] ?? null;
        if ($base === null || $base === '') {
            return '0.00';
        }
        $total = $base;
        $every = (int) ($p['increment_years'] ?? 0);
        $inc = $p['increment_days'] ?? null;
        if ($every > 0 && $inc !== null && $inc !== '' && (int) $p['service_years'] >= $every) {
            $total = bcadd($total, bcmul($inc, (string) intdiv((int) $p['service_years'], $every), 4), 4);
        }
        $cap = $p['cap'] ?? null;
        if ($cap !== null && $cap !== '' && bccomp($total, $cap, 4) > 0) {
            $total = bccomp($cap, $base, 4) >= 0 ? $cap : $base;
        }
        $w = $p['weekly_hours'] ?? null;
        $f = $p['full_time_hours'] ?? null;
        if ($w !== null && $w !== '' && $f !== null && $f !== '' && bccomp($f, '0', 2) > 0 && bccomp($w, $f, 2) < 0) {
            $total = bcdiv(bcmul($total, $w, 6), $f, 6);
        }
        return MoneyService::round($total, 2);
    }

    /**
     * What to post for month number $monthIndex (1-12) of the leave year, so that the running total equals round(annual x month / 12) exactly
     * (1.5 a month for 18 days, and the odd cent is corrected inside the year instead of drifting). Never negative: a lower entitlement never claws back.
     */
    public static function monthlyAccrual(string $annual, int $monthIndex, string $accruedThisYear): string
    {
        $target = MoneyService::round(bcdiv(bcmul($annual, (string) max(0, min(12, $monthIndex)), 6), '12', 6), 2);
        $amount = bcsub($target, $accruedThisYear, 2);
        return bccomp($amount, '0', 2) > 0 ? $amount : '0.00';
    }
    /**
     * Months of employment inside this leave year up to and including month $monthIndex. A person hired in month 5 has 1 eligible month in month 5,
     * so the accrual of the first month is a twelfth, not five twelfths of catch-up. $hireIndex = position of the hire month in this leave year (null = employed since before).
     */
    public static function eligibleMonths(int $monthIndex, ?int $hireIndex): int
    {
        return max(0, $monthIndex - max(1, $hireIndex ?? 1) + 1);
    }
}
