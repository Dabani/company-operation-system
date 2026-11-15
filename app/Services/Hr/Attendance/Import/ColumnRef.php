<?php

namespace App\Services\Hr\Attendance\Import;

/** Finds a column by header text, by Excel letter (A, B, AA) or by position (#3). Without a reference it looks for well-known header names. */
final class ColumnRef
{
    public static function letterIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n - 1;
    }

    public static function letter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /**
     * @param array<int,string> $headers normalised header names (CellParser::norm)
     * @return ?int column index, or null when the reference (or every synonym) matches nothing
     */
    public static function find(array $headers, ?string $ref, array $synonyms = []): ?int
    {
        $ref = $ref === null ? '' : trim($ref);
        if ($ref !== '') {
            $r = CellParser::norm($ref);
            $i = array_search($r, $headers, true);
            if ($i !== false) {
                return $i;
            }
            if (preg_match('/^#(\d+)$/', $r, $m) && (int) $m[1] >= 1) {
                return (int) $m[1] - 1;
            }
            return preg_match('/^[a-z]{1,3}$/', $r) ? self::letterIndex($r) : null;
        }
        foreach ($synonyms as $s) {
            $i = array_search(CellParser::norm($s), $headers, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    }
}
