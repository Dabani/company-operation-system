<?php

namespace App\Services\Projects;

/** Pure transition rules (unit-tested). Codes come from the task_status lookup. */
class TaskWorkflow
{
    /** from => [to => requires project manager] */
    private const MAP = [
        'todo' => ['in_progress' => false, 'cancelled' => false],
        'in_progress' => ['todo' => false, 'in_review' => false, 'cancelled' => false, 'done' => true],   // direct close = manager only
        'in_review' => ['in_progress' => true, 'cancelled' => true],                                      // normally decided through the approval
        'done' => ['in_progress' => true],                                                               // reopen
        'cancelled' => ['todo' => true],
    ];

    public static function allowed(string $from, string $to, bool $isManager): bool
    {
        $need = self::MAP[$from][$to] ?? null;
        return $need !== null && (!$need || $isManager);
    }

    public static function targets(string $from, bool $isManager): array
    {
        return array_keys(array_filter(self::MAP[$from] ?? [], fn ($needsManager) => !$needsManager || $isManager));
    }
}
