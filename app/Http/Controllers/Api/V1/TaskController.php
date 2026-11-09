<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\TaskService;
use App\Services\Projects\TaskWorkflow;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private ProjectAccess $access, private TaskService $tasks) {}

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }
    private function with(): array { return ['status', 'priority', 'assignee:id,name', 'reporter:id,name', 'milestone:id,name', 'project:id,code,name']; }

    private function row(Task $t): array
    {
        return [
            'id' => $t->id, 'key' => "{$t->project->code}-{$t->number}", 'number' => $t->number, 'project_id' => $t->project_id, 'project' => $t->project?->name,
            'title' => $t->title, 'status' => $t->status->code, 'status_id' => $t->status_id, 'priority' => $t->priority->code, 'priority_id' => $t->priority_id,
            'assignee_id' => $t->assignee_id, 'assignee' => $t->assignee?->name, 'reporter' => $t->reporter?->name, 'milestone_id' => $t->milestone_id, 'milestone' => $t->milestone?->name,
            'department_id' => $t->department_id, 'due_date' => $t->due_date?->toDateString(), 'estimate_minutes' => $t->estimate_minutes, 'position' => $t->position,
            'overdue' => $t->due_date && $t->due_date->isPast() && !in_array($t->status->code, ['done', 'cancelled'], true),
        ];
    }

    /** Task + the viewer's level in its project (404 when the project is not visible). */
    private function find(Request $r, string $id, array $with = []): array
    {
        $t = Task::with(array_merge($this->with(), $with))->findOrFail($id);
        [$p, $level] = $this->access->find($r->user(), $t->project_id);
        return [$t, $p, $level];
    }

    private function mayEdit(Request $r, Task $t, string $level): bool
    {
        return ProjectAccess::isManager($level) || (ProjectAccess::canEdit($level) && in_array($r->user()->id, [$t->assignee_id, $t->reporter_id], true));
    }

    private function rules(Project $p, bool $partial): array
    {
        $org = $this->org();
        $people = ProjectMember::where('project_id', $p->id)->pluck('user_id')->push($p->owner_id)->all();
        $req = $partial ? 'sometimes' : 'required';
        return [
            'title' => [$req, 'string', 'max:255'], 'description' => 'nullable|string|max:20000',
            'milestone_id' => ['nullable', Rule::exists('milestones', 'id')->where('project_id', $p->id)],
            'parent_task_id' => ['nullable', Rule::exists('tasks', 'id')->where('project_id', $p->id)],
            'priority_id' => ['sometimes', Rule::exists('lookup_values', 'id')],
            'assignee_id' => ['nullable', Rule::in($people)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'due_date' => 'nullable|date', 'estimate_minutes' => 'nullable|integer|min:0|max:100000',
        ];
    }

    // ---- board / list ----------------------------------------------------------------------------------------------------
    public function board(Request $request, string $projectId)
    {
        [$p, $level] = $this->access->find($request->user(), $projectId);
        $q = Task::with($this->with())->where('project_id', $p->id)->orderBy('position')->orderBy('id');
        foreach (['assignee_id', 'milestone_id', 'priority_id'] as $f) if ($request->filled($f)) $q->where($f, $request->query($f));
        if ($request->filled('q')) $q->where('title', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $request->query('q')) . '%');
        $tasks = $q->limit(1000)->get();
        $blocked = \DB::table('task_dependencies as d')->join('tasks as b', 'b.id', '=', 'd.depends_on_task_id')->join('lookup_values as s', 's.id', '=', 'b.status_id')
            ->whereIn('d.task_id', $tasks->pluck('id'))->whereNotIn('s.code', ['done', 'cancelled'])->selectRaw('d.task_id, count(*) as n')->groupBy('d.task_id')->pluck('n', 'task_id');
        return ['data' => $tasks->map(fn ($t) => $this->row($t) + ['blocked_by_open' => (int) ($blocked[$t->id] ?? 0)]), 'my_level' => $level];
    }

    public function mine(Request $request)
    {
        $viewAll = $this->access->viewAll();
        $tasks = Task::with($this->with())->where('assignee_id', $request->user()->id)->whereHas('status', fn ($s) => $s->whereNotIn('code', ['done', 'cancelled']))
            ->whereHas('project', fn ($q) => $q->visibleTo($request->user(), $viewAll))->orderByRaw('due_date is null')->orderBy('due_date')->limit(200)->get();
        return ['data' => $tasks->map(fn ($t) => $this->row($t))];
    }

    // ---- CRUD ------------------------------------------------------------------------------------------------------------
    public function store(Request $request, string $projectId)
    {
        [$p, $level] = $this->access->find($request->user(), $projectId);
        abort_unless(ProjectAccess::canEdit($level), 403, 'forbidden');
        $d = $request->validate($this->rules($p, false));
        $t = $this->tasks->create($p, $d, $request->user());
        return response()->json(['data' => $this->row($t->load($this->with()))], 201);
    }

    public function show(Request $request, string $id)
    {
        [$t, $p, $level] = $this->find($request, $id, ['comments.user:id,name', 'history.to', 'history.actor:id,name', 'blockedBy.status', 'blockedBy.project:id,code']);
        $isMgr = ProjectAccess::isManager($level);
        return ['data' => $this->row($t) + [
            'description' => $t->description, 'my_level' => $level, 'can_edit' => $this->mayEdit($request, $t, $level), 'can_comment' => ProjectAccess::canComment($level),
            'allowed_moves' => $this->mayEdit($request, $t, $level) ? TaskWorkflow::targets($t->status->code, $isMgr) : [],
            'blocked_by' => $t->blockedBy->map(fn ($b) => ['id' => $b->id, 'key' => "{$b->project->code}-{$b->number}", 'title' => $b->title, 'status' => $b->status->code]),
            'comments' => $t->comments->map(fn ($c) => ['id' => $c->id, 'user' => $c->user?->name, 'user_id' => $c->user_id, 'body' => $c->body, 'created_at' => $c->created_at->toIso8601String()]),
            'history' => $t->history->map(fn ($h) => ['to' => $h->to->code, 'actor' => $h->actor?->name, 'note' => $h->note, 'at' => $h->created_at?->toIso8601String()]),
        ]];
    }

    public function update(Request $request, string $id)
    {
        [$t, $p, $level] = $this->find($request, $id);
        abort_unless($this->mayEdit($request, $t, $level), 403, 'forbidden');
        $d = $request->validate($this->rules($p, true));
        return ['data' => $this->row($this->tasks->update($t, $d, $request->user())->load($this->with()))];
    }

    public function move(Request $request, string $id)
    {
        [$t, , $level] = $this->find($request, $id);
        abort_unless($this->mayEdit($request, $t, $level), 403, 'forbidden');
        $d = $request->validate(['status' => ['required', Rule::in(['todo', 'in_progress', 'in_review', 'done', 'cancelled'])], 'position' => 'nullable|integer|min:0']);
        $moved = $this->tasks->move($t, $d['status'], $request->user(), ProjectAccess::isManager($level), $d['position'] ?? null);
        return ['data' => $this->row($moved->load($this->with()))];
    }

    public function destroy(Request $request, string $id)
    {
        [$t, , $level] = $this->find($request, $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $t->delete();
        AuditLogger::log('task.deleted', $t);
        return response()->noContent();
    }

    // ---- dependencies and comments ------------------------------------------------------------------------------------------
    public function addDependency(Request $request, string $id)
    {
        [$t, , $level] = $this->find($request, $id);
        abort_unless($this->mayEdit($request, $t, $level), 403, 'forbidden');
        $d = $request->validate(['depends_on_task_id' => ['required', Rule::exists('tasks', 'id')->where('organization_id', $this->org())]]);
        $this->tasks->addDependency($t, Task::findOrFail($d['depends_on_task_id']));
        return response()->noContent(201);
    }

    public function removeDependency(Request $request, string $id, string $dependsOn)
    {
        [$t, , $level] = $this->find($request, $id);
        abort_unless($this->mayEdit($request, $t, $level), 403, 'forbidden');
        \DB::table('task_dependencies')->where('task_id', $t->id)->where('depends_on_task_id', $dependsOn)->delete();
        AuditLogger::log('task.dependency_removed', $t);
        return response()->noContent();
    }

    public function comment(Request $request, string $id)
    {
        [$t, , $level] = $this->find($request, $id);
        abort_unless(ProjectAccess::canComment($level), 403, 'forbidden');
        $d = $request->validate(['body' => 'required|string|max:20000']);
        $c = TaskComment::create(['task_id' => $t->id, 'user_id' => $request->user()->id, 'body' => trim($d['body'])]);
        AuditLogger::log('task.commented', $t);
        return response()->json(['id' => $c->id], 201);
    }

    public function deleteComment(Request $request, string $id, string $commentId)
    {
        [$t, , $level] = $this->find($request, $id);
        $c = TaskComment::where('task_id', $t->id)->findOrFail($commentId);
        abort_unless($c->user_id === $request->user()->id || ProjectAccess::isManager($level), 403, 'forbidden');
        $c->delete();
        AuditLogger::log('task.comment_deleted', $t);
        return response()->noContent();
    }
}
