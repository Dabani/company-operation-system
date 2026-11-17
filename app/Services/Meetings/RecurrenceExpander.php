<?php

namespace App\Services\Meetings;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns "weekly, every 2 weeks, 6 times" into the list of start instants. Pure and deterministic (unit-tested).
 * The wall-clock time is kept in the organization timezone, so a 09:00 meeting stays at 09:00 across a daylight-saving change.
 * Monthly repeats keep the day of the month and use the last day of shorter months (the 31st becomes the 28th/29th/30th).
 */
final class RecurrenceExpander
{
    public const MAX_OCCURRENCES = 52;

    /** @return CarbonImmutable[] UTC instants, the first one is $first itself */
    public static function expand(CarbonImmutable $first, string $frequency, int $interval, ?string $endsOn, ?int $count, string $timezone): array
    {
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('frequency');
        }
        if ($interval < 1 || $interval > 52) {
            throw new InvalidArgumentException('interval');
        }
        if ($endsOn === null && $count === null) {
            throw new InvalidArgumentException('end');
        }
        $limit = min($count ?? self::MAX_OCCURRENCES, self::MAX_OCCURRENCES);
        $start = $first->setTimezone($timezone);
        $until = $endsOn ? CarbonImmutable::parse($endsOn, $timezone)->endOfDay() : null;

        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $at = match ($frequency) {
                'daily' => $start->addDays($i * $interval),
                'weekly' => $start->addWeeks($i * $interval),
                default => $start->addMonthsNoOverflow($i * $interval),
            };
            if ($until && $at->greaterThan($until)) {
                break;
            }
            $out[] = $at->utc();
        }
        return $out;
    }
}
