<?php

namespace App\Services\Portal;

use App\Models\Project;
use App\Models\Task;

/** Portal visibility rules in one place: what the client may see, and the progress figure shown instead of task details. */
class PortalAccess
{
    /** Pure (unit-tested): percent complete of the non-cancelled tasks, rounded to the nearest whole number. */
    public static function percent(int $done, int $total): int
    {
        return $total <= 0 ? 0 : (int) round($done * 100 / $total);
    }

    public static function progress(string $projectId, ?string $milestoneId = null): array
    {
        $q = Task::where('project_id', $projectId)->when($milestoneId, fn ($q) => $q->where('milestone_id', $milestoneId))->whereHas('status', fn ($s) => $s->where('code', '!=', 'cancelled'));
        $total = (clone $q)->count();
        $done = (clone $q)->whereHas('status', fn ($s) => $s->where('code', 'done'))->count();
        return ['done' => $done, 'total' => $total, 'percent' => self::percent($done, $total)];
    }

    /** Invoices / uploads are off until enabled for the client OR for this project. */
    public static function enabled(Project $p, string $flag): bool
    {
        return (bool) ($p->{$flag} || $p->client?->{$flag});
    }
}
