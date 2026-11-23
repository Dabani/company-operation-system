<?php

namespace App\Services\Hr\Leave;

use DateTimeImmutable;

/** The leave year a date falls in. The year is identified by the calendar year in which it starts (start month is an HR setting, default January). Pure: no database. */
final class LeaveYear
{
    /** @return array{id:int,start:string,end:string} */
    public static function of(string $date, int $startMonth = 1): array
    {
        $startMonth = max(1, min(12, $startMonth));
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        $id = $m >= $startMonth ? $y : $y - 1;
        $start = sprintf('%04d-%02d-01', $id, $startMonth);
        return ['id' => $id, 'start' => $start, 'end' => (new DateTimeImmutable($start))->modify('+1 year')->modify('-1 day')->format('Y-m-d')];
    }

    /** Position (1-12) of the date's month inside its leave year. */
    public static function monthIndex(string $date, int $startMonth = 1): int
    {
        return ((((int) substr($date, 5, 2)) - max(1, min(12, $startMonth)) + 12) % 12) + 1;
    }

    /** Last day of the month containing the date. */
    public static function monthEnd(string $date): string
    {
        return (new DateTimeImmutable(substr($date, 0, 7) . '-01'))->modify('last day of this month')->format('Y-m-d');
    }
}
