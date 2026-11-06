<?php

namespace App\Services\Projects;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/** Approval step type "project_reviewer": the reviewers and managers of the task's project, plus its owner. */
class ProjectReviewerResolver
{
    public function users(ApprovalRequest $r, ApprovalStep $step): Collection
    {
        $task = Task::with('project')->find($r->subject_id);
        if (!$task) return collect();
        $ids = ProjectMember::where('project_id', $task->project_id)->whereHas('role', fn ($q) => $q->whereIn('code', ['reviewer', 'manager']))->pluck('user_id')
            ->push($task->project->owner_id)->unique();
        return User::query()->whereKey($ids)->whereHas('status', fn ($s) => $s->where('code', 'active'))->get();
    }
}
