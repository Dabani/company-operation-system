<?php

namespace App\Services\Hr\Attendance\Import;

/**
 * Turns whatever a device writes in its "direction" column into in / out. Each profile can list its own values (a device that writes 0 and 1, or "Entree" and
 * "Sortie"); without lists a set of common words is used. Numeric codes are device specific, so none are assumed by default.
 */
final class DirectionMap
{
    public const DEFAULT_IN = ['in', 'i', 'entry', 'enter', 'ci', 'c/in', 'check in', 'checkin', 'clock in', 'clockin', 'arrival', 'entree', 'entrée', 'injira'];
    public const DEFAULT_OUT = ['out', 'o', 'exit', 'co', 'c/out', 'check out', 'checkout', 'clock out', 'clockout', 'departure', 'sortie', 'sohoka'];

    /** @var array<string,string> normalised value => in|out|ignore */
    private array $map = [];

    public function __construct(?string $in = null, ?string $out = null, ?string $ignore = null)
    {
        $lists = ['in' => self::split($in) ?: self::DEFAULT_IN, 'out' => self::split($out) ?: self::DEFAULT_OUT, 'ignore' => self::split($ignore)];
        foreach ($lists as $kind => $values) {
            foreach ($values as $v) {
                $this->map[CellParser::norm($v)] = $kind;
            }
        }
    }

    /** "0, 3, 4" -> ['0','3','4'] */
    public static function split(?string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $csv)), fn ($v) => $v !== ''));
    }

    /** @return ?string null = blank cell (the direction is worked out later), in | out | ignore | invalid */
    public function classify(mixed $raw): ?string
    {
        $v = CellParser::norm($raw);
        return $v === '' ? null : ($this->map[$v] ?? 'invalid');
    }
}
