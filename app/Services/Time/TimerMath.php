<?php

namespace App\Services\Time;

/**
 * Pure time arithmetic (unit-tested). A segment is ['start' => 'Y-m-d H:i:s' UTC, 'end' => 'Y-m-d H:i:s'|null].
 * Elapsed time is always derived from server timestamps, never from the browser clock.
 */
class TimerMath
{
    public static function ts(string $utc): int { return (int) \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'))->format('U'); }
    public static function fmt(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }

    /** Seconds covered by the segments; an open segment counts up to $now. */
    public static function seconds(array $segments, string $now): int
    {
        $sum = 0;
        foreach ($segments as $s) {
            $sum += max(0, self::ts($s['end'] ?? $now) - self::ts($s['start']));
        }
        return $sum;
    }

    /** Do two half-open intervals overlap? Open ends use $now. */
    public static function overlaps(array $a, array $b, string $now): bool
    {
        $aS = self::ts($a['start']); $aE = self::ts($a['end'] ?? $now);
        $bS = self::ts($b['start']); $bE = self::ts($b['end'] ?? $now);
        return $aS < $bE && $bS < $aE;
    }

    /**
     * When must a running timer be cut off? Returns the UTC end for the open segment so that total time equals $maxSeconds,
     * or null while the timer is still within its allowance.
     */
    public static function autoStopAt(array $segments, int $maxSeconds, string $now): ?string
    {
        $closed = self::seconds(array_filter($segments, fn ($s) => $s['end'] !== null), $now);
        $open = array_values(array_filter($segments, fn ($s) => $s['end'] === null))[0] ?? null;
        if (!$open) return null;
        $total = $closed + max(0, self::ts($now) - self::ts($open['start']));
        if ($total <= $maxSeconds) return null;
        return self::fmt(self::ts($open['start']) + max(0, $maxSeconds - $closed));
    }

    /** Monday of the ISO week containing $date ('Y-m-d'). */
    public static function weekStart(string $date): string
    {
        $d = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }
}
