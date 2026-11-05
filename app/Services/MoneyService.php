<?php

namespace App\Services;

/**
 * The only place money is added, multiplied or rounded (Guide 3.5). Amounts travel as decimal strings, never floats.
 * Rounding is applied once, at document generation (e.g. invoice), never in intermediate layers.
 */
class MoneyService
{
    public const HALF_UP = 'half_up';
    public const DOWN = 'down';
    public const UP = 'up';

    public static function add(string $a, string $b, int $scale = 2): string { return bcadd($a, $b, $scale); }
    public static function sub(string $a, string $b, int $scale = 2): string { return bcsub($a, $b, $scale); }
    public static function mul(string $a, string $b, int $scale = 8): string { return bcmul($a, $b, $scale); }
    public static function compare(string $a, string $b, int $scale = 2): int { return bccomp($a, $b, $scale); }

    /** Percentage of an amount, e.g. VAT: percent('1000.00', '18') = '180.00' (unrounded to 8 places). */
    public static function percent(string $amount, string $rate): string { return bcdiv(bcmul($amount, $rate, 10), '100', 8); }

    public static function round(string $value, int $decimals = 2, string $mode = self::HALF_UP): string
    {
        $neg = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        $factor = bcpow('10', (string) $decimals);
        $scaled = bcmul($abs, $factor, 8);
        $int = bcadd($scaled, '0', 0);                       // truncated
        $frac = bcsub($scaled, $int, 8);
        $bump = match ($mode) {
            self::UP => bccomp($frac, '0', 8) > 0,
            self::DOWN => false,
            default => bccomp($frac, '0.5', 8) >= 0,
        };
        if ($bump) {
            $int = bcadd($int, '1', 0);
        }
        $out = bcdiv($int, $factor, $decimals);
        return ($neg && bccomp($out, '0', $decimals) !== 0 ? '-' : '') . $out;
    }

    /** Round to an increment, e.g. minutes to 15: roundToIncrement('37', '15') = '30' (half up). */
    public static function roundToIncrement(string $value, string $increment, string $mode = self::HALF_UP): string
    {
        $q = self::round(bcdiv($value, $increment, 8), 0, $mode);
        return bcmul($q, $increment, 8);
    }
}
