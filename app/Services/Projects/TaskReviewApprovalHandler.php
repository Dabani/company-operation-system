<?php

namespace App\Services\Projects;

use App\Models\ApprovalRequest;
use App\Models\Task;
use App\Services\Approvals\ApprovalOutcomeHandler;

/** Applies the reviewers' decision to the task: approved -> done; anything else sends it back to in_progress. */
class TaskReviewApprovalHandler implements ApprovalOutcomeHandler
{
    public function __construct(private TaskService $tasks) {}

    private function apply(ApprovalRequest $r, string $to, string $note): void
    {
        $task = Task::with('status')->find($r->subject_id);
        if ($task && $task->status->code === 'in_review') $this->tasks->applyReviewOutcome($task, $to, $note);
    }

    public function approved(ApprovalRequest $r): void { $this->apply($r, 'done', 'review approved'); }
    public function rejected(ApprovalRequest $r): void { $this->apply($r, 'in_progress', 'review rejected'); }
    public function returned(ApprovalRequest $r): void { $this->apply($r, 'in_progress', 'returned for changes'); }
    public function cancelled(ApprovalRequest $r): void { $this->apply($r, 'in_progress', 'review cancelled'); }
}
