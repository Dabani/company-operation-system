<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(private ApprovalEngine $approvals) {}

    public function create(Project $project, array $d, User $by): Task
    {
        return DB::transaction(function () use ($project, $d, $by) {
            $locked = Project::lockForUpdate()->findOrFail($project->id);
            $locked->increment('task_counter');
            $statusId = Lookup::id('task_status', 'todo');
            $task = Task::create([
                'project_id' => $project->id, 'number' => $locked->task_counter, 'title' => $d['title'], 'description' => $d['description'] ?? null,
                'milestone_id' => $d['milestone_id'] ?? null, 'parent_task_id' => $d['parent_task_id'] ?? null,
                'status_id' => $statusId, 'priority_id' => $d['priority_id'] ?? Lookup::id('task_priority', 'normal'),
                'assignee_id' => $d['assignee_id'] ?? null, 'reporter_id' => $by->id, 'department_id' => $d['department_id'] ?? null,
                'due_date' => $d['due_date'] ?? null, 'estimate_minutes' => $d['estimate_minutes'] ?? null,
                'position' => (int) Task::where('project_id', $project->id)->where('status_id', $statusId)->max('position') + 1,
            ]);
            $this->history($task, null, $statusId, $by, 'created');
            AuditLogger::log('task.created', $task, [], ['project' => $project->code, 'number' => $task->number]);
            $this->notifyAssigned($task, $project, $by);
            return $task;
        });
    }

    public function update(Task $task, array $d, User $by): Task
    {
        $old = $task->only(array_keys($d));
        $assigneeChanged = array_key_exists('assignee_id', $d) && $d['assignee_id'] !== $task->assignee_id;
        $task->update($d);
        AuditLogger::log('task.updated', $task, $old, $d);
        if ($assigneeChanged) $this->notifyAssigned($task, $task->project, $by);
        return $task;
    }

    /** Move between workflow states. Entering "in_review" raises a task_review approval; the decision moves it on. */
    public function move(Task $task, string $to, User $by, bool $isManager, ?int $position = null): Task
    {
        $task->loadMissing('status');
        $from = $task->status->code;
        if ($from !== $to && !TaskWorkflow::allowed($from, $to, $isManager)) {
            throw ValidationException::withMessages(['status' => [__('projects.task.transition_not_allowed')]]);
        }
        if ($from !== $to && in_array($to, ['in_review', 'done'], true) && $this->unfinishedBlockers($task) > 0) {
            throw ValidationException::withMessages(['status' => [__('projects.task.blocked')]]);
        }
        return DB::transaction(function () use ($task, $from, $to, $by, $position) {
            if ($from !== $to) {
                if ($to === 'in_review') {
                    $this->approvals->submit('task_review', $task->id, $by, "{$task->project->code}-{$task->number} {$task->title}", ['department_id' => $task->department_id]);
                }
                $this->setStatus($task, $to, $by, null);
            }
            if ($position !== null) {
                $task->update(['position' => $position]);
            } elseif ($from !== $to) {
                $task->update(['position' => (int) Task::where('project_id', $task->project_id)->where('status_id', $task->status_id)->max('position') + 1]);
            }
            return $task->refresh();
        });
    }

    /** Applied by the approval handler (no human actor, no workflow check: the decision is the authority). */
    public function applyReviewOutcome(Task $task, string $to, string $note): void
    {
        DB::transaction(fn () => $this->setStatus($task, $to, null, $note));
    }

    public function addDependency(Task $task, Task $dependsOn): void
    {
        if ($task->project_id !== $dependsOn->project_id) {
            throw ValidationException::withMessages(['depends_on_task_id' => [__('projects.task.dependency_same_project')]]);
        }
        $edges = DB::table('task_dependencies')->where('organization_id', $task->organization_id)->get(['task_id', 'depends_on_task_id'])
            ->map(fn ($r) => [$r->task_id, $r->depends_on_task_id])->all();
        if (TaskGraph::wouldCycle($edges, $task->id, $dependsOn->id)) {
            throw ValidationException::withMessages(['depends_on_task_id' => [__('projects.task.dependency_cycle')]]);
        }
        DB::table('task_dependencies')->insertOrIgnore(['id' => (string) \Illuminate\Support\Str::ulid(), 'organization_id' => $task->organization_id, 'task_id' => $task->id, 'depends_on_task_id' => $dependsOn->id]);
        AuditLogger::log('task.dependency_added', $task, [], ['depends_on' => $dependsOn->number]);
    }

    public function unfinishedBlockers(Task $task): int
    {
        return $task->blockedBy()->whereHas('status', fn ($q) => $q->whereNotIn('code', ['done', 'cancelled']))->count();
    }

    private function setStatus(Task $task, string $to, ?User $by, ?string $note): void
    {
        $fromId = $task->status_id;
        $toId = Lookup::id('task_status', $to);
        $task->update(['status_id' => $toId, 'completed_at' => $to === 'done' ? now() : null]);
        $task->setRelation('status', null);
        $this->history($task, $fromId, $toId, $by, $note);
        AuditLogger::log('task.moved', $task, [], ['to' => $to, 'note' => $note]);
    }

    private function history(Task $task, ?string $from, string $to, ?User $by, ?string $note): void
    {
        TaskStatusHistory::create(['task_id' => $task->id, 'from_status_id' => $from, 'to_status_id' => $to, 'actor_id' => $by?->id, 'note' => $note]);
    }

    private function notifyAssigned(Task $task, Project $project, User $by): void
    {
        if ($task->assignee_id && $task->assignee_id !== $by->id && ($u = User::find($task->assignee_id))) {
            Notifier::send($u, 'task_assigned', ['title' => "{$project->code}-{$task->number} {$task->title}", 'actor' => $by->name], null, ['in_app', 'email'], "/projects/{$project->id}", $task->organization_id);
        }
    }
}
