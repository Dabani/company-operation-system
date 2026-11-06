<?php

namespace App\Services\Time;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\RateCardLine;

/** Hourly rate for one person on one task at a date, from the project's rate card. null = no rate (never guessed). Used by invoicing (Bundle 11). */
class RateResolver
{
    public function rateFor(Project $project, string $userId, ?string $taskId, string $date): ?array
    {
        if (!$project->rate_card_id) return null;
        $role = $project->owner_id === $userId ? 'manager' : ProjectMember::where('project_id', $project->id)->where('user_id', $userId)->first()?->role?->code;
        $lines = RateCardLine::where('rate_card_id', $project->rate_card_id)->with(['scope', 'projectRole'])->get()->map(fn ($l) => [
            'id' => $l->id, 'scope' => $l->scope->code, 'task_id' => $l->task_id, 'user_id' => $l->user_id, 'role' => $l->projectRole?->code,
            'rate' => (string) $l->hourly_rate, 'from' => $l->effective_from->toDateString(), 'to' => $l->effective_to?->toDateString(),
        ])->all();
        $hit = RateMatcher::pick($lines, $taskId, $userId, $role, $date);
        return $hit ? ['line_id' => $hit['id'], 'rate' => $hit['rate']] : null;
    }
}
