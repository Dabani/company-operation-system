<?php

namespace App\Services\Projects;

/** Dependency-cycle detection (pure, unit-tested). edges: list of [taskId, dependsOnTaskId]. */
class TaskGraph
{
    public static function wouldCycle(array $edges, string $task, string $dependsOn): bool
    {
        if ($task === $dependsOn) return true;
        $deps = [];
        foreach ($edges as [$t, $d]) $deps[$t][] = $d;
        $stack = [$dependsOn];
        $seen = [];
        while ($stack) {
            $n = array_pop($stack);
            if ($n === $task) return true;
            if (isset($seen[$n])) continue;
            $seen[$n] = true;
            foreach ($deps[$n] ?? [] as $next) $stack[] = $next;
        }
        return false;
    }
}
