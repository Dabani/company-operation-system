<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class BusinessDays
{
    /** Adds N working days (Mon-Fri). Public holidays are not modelled yet. */
    public static function add(Carbon $from, int $days): Carbon
    {
        $d = $from->copy();
        while ($days > 0) {
            $d->addDay();
            if (!$d->isWeekend()) $days--;
        }
        return $d;
    }
}
