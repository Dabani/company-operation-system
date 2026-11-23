<?php

namespace App\Services\Hr\Trips;

use App\Services\MoneyService;
use DateTimeImmutable;

/** Business-trip arithmetic (Guide 5.5). Pure: rates, dates and amounts come in as arguments; no rate is built in. Money is a decimal string, never a float. */
final class TripMath
{
    /** Calendar days of travel, both ends included. A date range that ends before it starts is 0. */
    public static function days(string $from, string $to): int
    {
        return $to < $from ? 0 : (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    }

    public static function perDiem(string $rate, string $from, string $to): string
    {
        return MoneyService::round(bcmul($rate, (string) self::days($from, $to), 4), 2);
    }

    /**
     * The rate that applies: same kind, zone and currency, valid on the date. A rate for the employee's grade beats a general one (no grade);
     * then the one that started latest wins. No match = null (the screen says "no rate", nothing is guessed).
     * rate = ['kind','zone','grade' => ?id,'amount','currency','valid_from','valid_to' => ?date]
     */
    public static function pickRate(array $rates, string $kind, string $zone, ?string $grade, string $on, string $currency): ?array
    {
        $ok = array_filter($rates, fn ($r) => $r['kind'] === $kind && $r['zone'] === $zone && $r['currency'] === $currency
            && $r['valid_from'] <= $on && (empty($r['valid_to']) || $r['valid_to'] >= $on) && (empty($r['grade']) || $r['grade'] === $grade));
        usort($ok, fn ($a, $b) => [(int) !empty($b['grade']), $b['valid_from']] <=> [(int) !empty($a['grade']), $a['valid_from']]);
        return $ok[0] ?? null;
    }

    /** What is owed after the trip. balance = accepted expenses - advance paid: positive = the organization owes the traveller, negative = the traveller owes it. */
    public static function settlement(string $accepted, string $advance): array
    {
        $b = bcsub($accepted, $advance, 2);
        return ['balance' => $b, 'direction' => bccomp($b, '0', 2) > 0 ? 'pay_employee' : (bccomp($b, '0', 2) < 0 ? 'employee_owes' : 'even')];
    }

    public static function dueOn(string $tripEnd, int $days): string
    {
        return (new DateTimeImmutable($tripEnd))->modify("+$days days")->format('Y-m-d');
    }

    /** An advance is unretired and late when it is not retired and its due date has passed. */
    public static function overdue(?string $dueOn, bool $retired, string $today): bool
    {
        return !$retired && $dueOn !== null && $dueOn < $today;
    }

    public static function daysLate(string $dueOn, string $today): int
    {
        return $today <= $dueOn ? 0 : (int) (new DateTimeImmutable($dueOn))->diff(new DateTimeImmutable($today))->days;
    }

    /** An expense dated outside the trip (more than $grace days either side) is refused: it is not a trip expense. */
    public static function withinTrip(string $date, string $from, string $to, int $grace = 1): bool
    {
        $lo = (new DateTimeImmutable($from))->modify("-$grace days")->format('Y-m-d');
        $hi = (new DateTimeImmutable($to))->modify("+$grace days")->format('Y-m-d');
        return $date >= $lo && $date <= $hi;
    }
}
