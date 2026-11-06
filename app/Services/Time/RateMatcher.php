<?php

namespace App\Services\Time;

/**
 * Pure rate-card line selection (unit-tested). A line:
 *   ['id', 'scope' => 'task|employee|role|default', 'task_id', 'user_id', 'role' (project role code), 'rate', 'from', 'to'|null]
 * Most specific scope wins: task > employee > role > default; inside a scope the latest effective_from wins.
 * No matching line => null (the caller must flag the entry as "no rate", never guess a rate).
 */
class RateMatcher
{
    private const ORDER = ['task' => 4, 'employee' => 3, 'role' => 2, 'default' => 1];

    public static function pick(array $lines, ?string $taskId, ?string $userId, ?string $role, string $date): ?array
    {
        $hits = array_values(array_filter($lines, function ($l) use ($taskId, $userId, $role, $date) {
            if ($l['from'] > $date || ($l['to'] !== null && $l['to'] < $date)) return false;
            return match ($l['scope']) {
                'task' => $taskId !== null && $l['task_id'] === $taskId,
                'employee' => $userId !== null && $l['user_id'] === $userId,
                'role' => $role !== null && $l['role'] === $role,
                'default' => true,
                default => false,
            };
        }));
        usort($hits, fn ($a, $b) => [self::ORDER[$b['scope']], $b['from']] <=> [self::ORDER[$a['scope']], $a['from']]);
        return $hits[0] ?? null;
    }
}
