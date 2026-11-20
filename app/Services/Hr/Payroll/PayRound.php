<?php

namespace App\Services\Hr\Payroll;

/**
 * Rounding of a pay amount to a number of decimals (0 for a currency without minor units, as the Rwandan franc). Pure, exact (bcmath), never floats.
 * The mode is configuration, because "to the franc" depends on it: nearest (half away from zero), down (toward zero), up (away from zero).
 */
final class PayRound
{
    public const NEAREST = 'nearest';
    public const DOWN = 'down';
    public const UP = 'up';

    public static function apply(string $value, string $mode, int $decimals): string
    {
        $d = max(0, min(6, $decimals));
        $neg = bccomp($value, '0', 8) < 0;
        $abs = ltrim($value, '-');
        $unit = $d === 0 ? '1' : '0.' . str_repeat('0', $d - 1) . '1';                 // 10^-d
        $trunc = bcadd($abs, '0', $d);
        $r = match ($mode) {
            self::DOWN => $trunc,
            self::UP => bccomp($abs, $trunc, 8) > 0 ? bcadd($trunc, $unit, $d) : $trunc,
            default => bcadd(bcadd($abs, '0.' . str_repeat('0', $d) . '5', $d + 1), '0', $d),   // half away from zero
        };
        return ($neg && bccomp($r, '0', $d) !== 0 ? '-' : '') . $r;
    }
}
