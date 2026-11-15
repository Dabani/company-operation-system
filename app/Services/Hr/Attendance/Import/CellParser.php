<?php

namespace App\Services\Hr\Attendance\Import;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure cell parsing for attendance files (unit-tested). Spreadsheet cells arrive as raw values: a date is a day number (46335 = 2026-11-09), a time of day is a
 * fraction of a day (0.3354 = 08:03), text cells are strings. Everything is read in the device's local timezone.
 */
final class CellParser
{
    /** Formats tried for date-time text when the profile does not name its own. Day-first (d/m/Y) because that is how Rwanda and most of Africa write dates. */
    public const DEFAULT_DATETIME = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i'];
    public const DEFAULT_DATE = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'];

    /** A readable text for any cell (whole numbers lose their ".0": badge 101.0 is "101"). */
    public static function text(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return trim((string) $v);
    }

    public static function isNumber(mixed $v): bool { return is_int($v) || is_float($v); }

    /** Lower case, single spaces, "_" and "-" read as spaces: "Employee_Number" and "employee number" are the same name. */
    public static function norm(mixed $v): string
    {
        return trim((string) preg_replace('/[\s_\-]+/', ' ', mb_strtolower(self::text($v))));
    }

    /** Day number -> 'Y-m-d' (null for a pure time of day). Excel counts 1900 as a leap year, which shifts every date before 1 March 1900 only. */
    public static function serialDate(float $serial): ?string
    {
        $days = (int) floor($serial + 1e-9);
        if ($days < 1 || $days > 2958465) {
            return null;
        }
        $base = $days >= 61 ? '1899-12-30' : '1899-12-31';
        return (new DateTimeImmutable($base, new DateTimeZone('UTC')))->modify("+$days days")->format('Y-m-d');
    }

    /** Seconds since midnight from the fractional part of a serial, rounded to the nearest second (08:03:00 is stored as 0.33541666...). */
    public static function serialSeconds(float $serial): int
    {
        $s = (int) round(($serial - floor($serial + 1e-9)) * 86400);
        return $s >= 86400 ? 0 : max(0, $s);
    }

    /** @return ?string 'Y-m-d' */
    public static function date(mixed $v, array $formats = []): ?string
    {
        if (self::isNumber($v)) {
            return self::serialDate((float) $v);
        }
        $s = self::text($v);
        if ($s === '') {
            return null;
        }
        foreach ($formats ?: array_merge(self::DEFAULT_DATE, self::DEFAULT_DATETIME) as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $s, new DateTimeZone('UTC'));
            if ($d && $d->format($f) === $s) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    /** @return ?int seconds since midnight */
    public static function timeOfDay(mixed $v): ?int
    {
        if (self::isNumber($v)) {
            return self::serialSeconds((float) $v);
        }
        if (preg_match('/^(\d{1,2})\s*[:.hH]\s*(\d{2})(?:\s*[:.]\s*(\d{2}))?\s*([AaPp][Mm])?$/', self::text($v), $m)) {
            [$h, $min, $sec] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
            if (!empty($m[4])) {
                $pm = strtolower($m[4]) === 'pm';
                if ($h < 1 || $h > 12) {
                    return null;
                }
                $h = ($h % 12) + ($pm ? 12 : 0);
            }
            return $h < 24 && $min < 60 && $sec < 60 ? $h * 3600 + $min * 60 + $sec : null;
        }
        return null;
    }

    /** Local calendar date + seconds since midnight -> UTC timestamp. */
    public static function timestamp(string $date, int $seconds, string $tz): int
    {
        return (new DateTimeImmutable("$date 00:00:00", new DateTimeZone($tz)))->modify("+$seconds seconds")->getTimestamp();
    }

    /** A date-and-time cell (one column) -> UTC timestamp. */
    public static function dateTime(mixed $v, string $tz, array $formats = []): ?int
    {
        if (self::isNumber($v)) {
            $date = self::serialDate((float) $v);
            return $date === null ? null : self::timestamp($date, self::serialSeconds((float) $v), $tz);
        }
        $s = self::text($v);
        if ($s === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s)) {
            try { return (new DateTimeImmutable($s))->getTimestamp(); } catch (\Throwable) { return null; }
        }
        foreach ($formats ?: self::DEFAULT_DATETIME as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $s, new DateTimeZone($tz));
            if ($d && $d->format($f) === $s) {
                return $d->getTimestamp();
            }
        }
        if (!$formats && preg_match('/^(\S+)[ T]+(\S.*)$/', $s, $m)) {                    // "9/11/2026 8:03 AM" and other shapes: date part + time part
            $date = self::date($m[1]);
            $sec = self::timeOfDay($m[2]);
            return $date !== null && $sec !== null ? self::timestamp($date, $sec, $tz) : null;
        }
        return null;
    }
}
