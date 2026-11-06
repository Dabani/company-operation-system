<?php

namespace App\Providers;

use App\Services\Projects\ProjectReviewerResolver;
use App\Services\Projects\TaskReviewApprovalHandler;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 1 wiring: registers the task_review approval handler and the project_reviewer step resolver. */
class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['task_review' => TaskReviewApprovalHandler::class]),
            'cops.step_resolvers' => array_merge(config('cops.step_resolvers', []), ['project_reviewer' => ProjectReviewerResolver::class]),
        ]);
    }
}
