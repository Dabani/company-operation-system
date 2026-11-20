<?php

namespace App\Services\Hr\Payroll;

use Carbon\CarbonImmutable;

class Proration
{
    /**
     * True when the employee was not employed for the whole period [from, to]:
     * hired after the period start, or ended before the period end.
     * A null date means "no boundary" and never makes the period partial.
     */
    public static function isPartial(string $from, string $to, ?string $hiredOn, ?string $endedOn): bool
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($hiredOn !== null && CarbonImmutable::parse($hiredOn)->startOfDay()->gt($start)) {
            return true;
        }

        return $endedOn !== null && CarbonImmutable::parse($endedOn)->startOfDay()->lt($end);
    }
}
