<?php

namespace App\Services\Hr\Payroll;

/** Is a set of progressive bands well formed? Pure. A gap would silently leave income untaxed and an overlap would tax it twice, so both are refused. */
final class BracketValidator
{
    /** @param array<int,array{lower:string|int|float,upper:string|int|float|null,rate:string|int|float}> $brackets  @return ?string an error code, or null when valid */
    public static function error(array $brackets): ?string
    {
        if ($brackets === []) {
            return 'empty';
        }
        usort($brackets, fn ($a, $b) => bccomp((string) $a['lower'], (string) $b['lower'], 4));
        $expected = '0';
        foreach ($brackets as $i => $b) {
            $lower = (string) $b['lower'];
            $upper = ($b['upper'] ?? null) === null || $b['upper'] === '' ? null : (string) $b['upper'];
            $rate = (string) $b['rate'];
            if (!is_numeric($lower) || bccomp($lower, '0', 4) < 0 || !is_numeric($rate) || bccomp($rate, '0', 6) < 0 || bccomp($rate, '100', 6) > 0 || ($upper !== null && !is_numeric($upper))) {
                return 'bad_value';
            }
            if ($i === 0 && bccomp($lower, '0', 4) !== 0) {
                return 'first_not_zero';
            }
            if (bccomp($lower, $expected, 4) !== 0) {
                return 'gap_or_overlap';
            }
            if ($upper === null) {
                return $i === count($brackets) - 1 ? null : 'open_band_not_last';
            }
            if (bccomp($upper, $lower, 4) <= 0) {
                return 'bad_bounds';
            }
            $expected = $upper;
        }
        return 'last_not_open';
    }
}
