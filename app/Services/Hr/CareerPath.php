<?php

namespace App\Services\Hr;

use DateTimeImmutable;

/** Pure career-progression logic (unit-tested, no database). */
final class CareerPath
{
    /** Whole months from $from to $to (never negative). */
    public static function months(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        if ($b <= $a) {
            return 0;
        }
        $d = $a->diff($b);
        return $d->y * 12 + $d->m;
    }

    /**
     * Date the employee has continuously held the grade in force at $asOf.
     * @param array<int,array{from:string,grade:?string}> $records effective records, oldest first
     */
    public static function gradeSince(array $records, string $asOf): ?string
    {
        $inForce = array_values(array_filter($records, fn ($r) => $r['from'] <= $asOf));
        if (!$inForce) {
            return null;
        }
        $current = $inForce[count($inForce) - 1]['grade'];
        if ($current === null) {
            return null;
        }
        $since = $inForce[count($inForce) - 1]['from'];
        for ($i = count($inForce) - 2; $i >= 0 && $inForce[$i]['grade'] === $current; $i--) {
            $since = $inForce[$i]['from'];
        }
        return $since;
    }

    /**
     * @param array{min_months_in_grade:int,min_rating_level:?int} $criterion
     * @return array{eligible:bool,missing:string[]}
     */
    public static function evaluate(array $criterion, int $monthsInGrade, ?int $ratingLevel): array
    {
        $missing = [];
        if ($monthsInGrade < (int) $criterion['min_months_in_grade']) {
            $missing[] = 'months_in_grade';
        }
        if ($criterion['min_rating_level'] !== null && ($ratingLevel === null || $ratingLevel < (int) $criterion['min_rating_level'])) {
            $missing[] = 'rating';
        }
        return ['eligible' => $missing === [], 'missing' => $missing];
    }
}
