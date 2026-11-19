<?php

namespace App\Services\Meetings;

/** Pure rules of chat and polls in online meetings (no database). */
final class CollabRules
{
    public const OPTION_MIN = 2;
    public const OPTION_MAX = 10;

    public static function messageOk(?string $body): bool
    {
        $n = mb_strlen(trim((string) $body));
        return $n >= 1 && $n <= 1000;
    }

    public static function questionOk(?string $q): bool
    {
        $n = mb_strlen(trim((string) $q));
        return $n >= 3 && $n <= 300;
    }

    /** @param string[] $labels */
    public static function optionsOk(array $labels): bool
    {
        $n = count($labels);
        if ($n < self::OPTION_MIN || $n > self::OPTION_MAX) {
            return false;
        }
        $seen = [];
        foreach ($labels as $l) {
            $t = mb_strtolower(trim((string) $l));
            if ($t === '' || mb_strlen($t) > 150 || isset($seen[$t])) {
                return false;
            }
            $seen[$t] = true;
        }
        return true;
    }

    /** How many options a voter may tick. $maxChoices 0 = no limit (only for multiple choice). */
    public static function choicesOk(int $selected, bool $multiple, int $maxChoices, int $optionCount): bool
    {
        if ($selected < 1 || $selected > $optionCount) {
            return false;
        }
        if (!$multiple) {
            return $selected === 1;
        }
        return $maxChoices === 0 || $selected <= $maxChoices;
    }

    public static function allowedMove(string $from, string $to): bool { return $from === 'open' && $to === 'closed'; }

    /** Hosts see the running count; everyone else sees the result once the poll is closed. */
    public static function showResults(string $status, bool $moderator): bool { return $moderator || $status === 'closed'; }

    /** @param string[] $optionIds  @param string[] $votedOptionIds one entry per ticked option. @return array<string,int> */
    public static function tally(array $optionIds, array $votedOptionIds): array
    {
        $out = array_fill_keys($optionIds, 0);
        foreach ($votedOptionIds as $id) {
            if (isset($out[$id])) {
                $out[$id]++;
            }
        }
        return $out;
    }

    /** Share of voters who chose the option, one decimal. */
    public static function percent(int $count, int $voters): float { return $voters > 0 ? round($count * 100 / $voters, 1) : 0.0; }

    /** Option ids with the most votes (several on a tie); none when nobody voted. */
    public static function leaders(array $counts): array
    {
        $max = $counts ? max($counts) : 0;
        return $max > 0 ? array_keys(array_filter($counts, fn ($c) => $c === $max)) : [];
    }
}
