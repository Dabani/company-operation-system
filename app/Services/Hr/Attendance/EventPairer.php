<?php

namespace App\Services\Hr\Attendance;

use DateTimeImmutable;
use DateTimeZone;

/** Pure: turns a chronological list of clock events into in/out pairs (unit-tested). */
final class EventPairer
{
    private static function ts(string $utc): int { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp(); }

    /**
     * @param array<int,array{dir:string,at:string}> $events oldest first
     * @return array<int,array{0:string,1:?string}> [in, out|null]. An 'in' without an 'out' stays open; an 'out' with no matching 'in' (or longer than the window after it) is ignored.
     */
    public static function pair(array $events, int $windowSeconds): array
    {
        $pairs = [];
        $open = null;
        foreach ($events as $e) {
            if ($e['dir'] === 'in') {
                if ($open !== null) {
                    $pairs[] = [$open, null];
                }
                $open = $e['at'];
            } elseif ($open !== null) {
                if (self::ts($e['at']) - self::ts($open) <= $windowSeconds) {
                    $pairs[] = [$open, $e['at']];
                } else {
                    $pairs[] = [$open, null];
                }
                $open = null;
            }
        }
        if ($open !== null) {
            $pairs[] = [$open, null];
        }
        return $pairs;
    }

    /** Direction an unlabeled punch (biometric device) should get: the opposite of the previous one, or 'in' when there is no recent open 'in'. */
    public static function infer(?array $previous, string $at, int $windowSeconds): string
    {
        if ($previous && $previous['dir'] === 'in' && self::ts($at) - self::ts($previous['at']) <= $windowSeconds) {
            return 'out';
        }
        return 'in';
    }
}
