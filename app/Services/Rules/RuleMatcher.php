<?php

namespace App\Services\Rules;

/**
 * Pure rule matching (no database): easy to unit-test with golden scenarios.
 *
 * condition = ['attribute' => 'amount', 'operator' => 'gte', 'text' => null, 'decimal' => '1000000.00', 'decimal_to' => null]
 * candidate = ['id','version','priority','effective_from' => 'Y-m-d','effective_to' => 'Y-m-d'|null,'conditions' => [...]]
 * Resolution order (Guide 3.7.4): most specific condition set, then highest priority, then latest effective version.
 */
class RuleMatcher
{
    public const OPEN_END = PHP_INT_MAX;

    public static function conditionMatches(array $c, array $attrs): bool
    {
        $key = $c['attribute'];
        if (!array_key_exists($key, $attrs) || $attrs[$key] === null || $attrs[$key] === '') {
            return false;                                   // an attribute the transaction does not carry never satisfies a condition
        }
        $actual = $attrs[$key];
        $op = $c['operator'];

        if ($c['decimal'] !== null && is_numeric($actual)) {
            $a = (string) $actual;
            $x = (string) $c['decimal'];
            return match ($op) {
                'eq' => bccomp($a, $x, 2) === 0,
                'ne' => bccomp($a, $x, 2) !== 0,
                'gte' => bccomp($a, $x, 2) >= 0,
                'gt' => bccomp($a, $x, 2) > 0,
                'lte' => bccomp($a, $x, 2) <= 0,
                'lt' => bccomp($a, $x, 2) < 0,
                'between' => $c['decimal_to'] !== null && bccomp($a, $x, 2) >= 0 && bccomp($a, (string) $c['decimal_to'], 2) <= 0,
                default => false,
            };
        }

        $text = (string) $c['text'];
        return match ($op) {
            'eq' => (string) $actual === $text,
            'ne' => (string) $actual !== $text,
            'in' => in_array((string) $actual, array_map('trim', explode(',', $text)), true),
            default => false,
        };
    }

    public static function matches(array $conditions, array $attrs): bool
    {
        foreach ($conditions as $c) {
            if (!self::conditionMatches($c, $attrs)) {
                return false;
            }
        }
        return true;
    }

    public static function inEffect(array $cand, string $on): bool
    {
        return $cand['effective_from'] <= $on && ($cand['effective_to'] === null || $cand['effective_to'] >= $on);
    }

    /** Deterministic winner among candidates for one transaction, or null when no rule applies. */
    public static function pick(array $candidates, array $attrs, string $on): ?array
    {
        $hits = array_values(array_filter($candidates, fn ($c) => self::inEffect($c, $on) && self::matches($c['conditions'], $attrs)));
        if (!$hits) {
            return null;
        }
        usort($hits, fn ($a, $b) => [count($b['conditions']), $b['priority'], $b['effective_from'], $b['version']]
            <=> [count($a['conditions']), $a['priority'], $a['effective_from'], $a['version']]);
        return $hits[0];
    }

    /** [lo, hi] in cents (inclusive) covered by the amount conditions of one rule; no amount condition = everything. */
    public static function amountInterval(array $conditions): array
    {
        $lo = 0;
        $hi = self::OPEN_END;
        $cents = fn ($v) => (int) round(((float) $v) * 100);
        foreach ($conditions as $c) {
            if ($c['attribute'] !== 'amount' || $c['decimal'] === null) {
                continue;
            }
            $x = $cents($c['decimal']);
            switch ($c['operator']) {
                case 'gte': $lo = max($lo, $x); break;
                case 'gt': $lo = max($lo, $x + 1); break;
                case 'lte': $hi = min($hi, $x); break;
                case 'lt': $hi = min($hi, $x - 1); break;
                case 'between': $lo = max($lo, $x); $hi = min($hi, $cents($c['decimal_to'] ?? $c['decimal'])); break;
                case 'eq': $lo = max($lo, $x); $hi = min($hi, $x); break;
            }
        }
        return [$lo, $hi];
    }

    /** Gaps in [0, open end) left by the intervals: returns list of [from, to] cent ranges not covered. */
    public static function coverageGaps(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $gaps = [];
        $next = 0;                                          // first cent not yet covered
        foreach ($intervals as [$lo, $hi]) {
            if ($lo > $next) {
                $gaps[] = [$next, $lo - 1];
            }
            if ($hi === self::OPEN_END) {
                return $gaps;
            }
            $next = max($next, $hi + 1);
        }
        $gaps[] = [$next, self::OPEN_END];
        return $gaps;
    }
}
