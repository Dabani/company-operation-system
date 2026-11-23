<?php

namespace App\Services\Hr\Leave;

/** Two-decimal normalisation of day counts ("18" -> "18.00"). */
final class MoneyHelper
{
    public static function two(string|int|float $v): string { return bcadd((string) $v, '0', 2); }
}
