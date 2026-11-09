<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectRowResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use App\Support\LookupRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    use AppliesListQuery;

    public function __construct(private ProjectAccess $access) {}

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    private function row(Project $p, ?string $level = null): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status?->code, 'status_id' => $p->status_id,
            'owner_id' => $p->owner_id, 'owner' => $p->owner?->name, 'client_id' => $p->client_id, 'client' => $p->client?->name,
            'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(),
            'budget_amount' => $p->budget_amount, 'currency_code' => $p->currency_code, 'my_level' => $level,
        ];
    }

    public function index(Request $request)
    {
        $q = Project::query()->visibleTo($request->user(), $this->access->viewAll())->with(['status', 'owner', 'client']);
        return $this->respondList($request, $q, ProjectRowResource::class,
            ['code', 'name'], ['name', 'code', 'start_date', 'end_date', 'created_at'], ['status_id', 'client_id', 'owner_id'], ['code', 'name', 'status', 'owner', 'start_date', 'end_date']);
    }

    public function show(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        $p->load(['owner', 'client', 'departments:id,name', 'milestones', 'members.user:id,name', 'members.role']);
        $counts = Task::where('project_id', $p->id)->join('lookup_values', 'lookup_values.id', '=', 'tasks.status_id')->selectRaw('lookup_values.code as code, count(*) as n')->groupBy('lookup_values.code')->pluck('n', 'code');
        return ['data' => $this->row($p, $level) + [
            'departments' => $p->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name]),
            'milestones' => $p->milestones->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'due_date' => $m->due_date?->toDateString(), 'completed_at' => $m->completed_at?->toIso8601String()]),
            'members' => $p->members->map(fn ($m) => ['id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->user?->name, 'role' => $m->role?->code]),
            'task_counts' => $counts,
        ]];
    }

    private function rules(?Project $m = null): array
    {
        $org = $this->org();
        return [
            'code' => [$m ? 'sometimes' : 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('projects', 'code')->where('organization_id', $org)->ignore($m?->id)],
            'name' => [$m ? 'sometimes' : 'required', 'string', 'max:255'], 'description' => 'nullable|string|max:10000',
            'client_id' => ['nullable', Rule::exists('clients', 'id')->where('organization_id', $org)],
            'owner_id' => ['sometimes', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'status_id' => ['sometimes', Rule::exists('lookup_values', 'id')],
            'start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget_amount' => 'nullable|numeric|min:0|max:9999999999999', 'currency_code' => ['nullable', LookupRule::code('currency')],
            'department_ids' => 'array|max:30', 'department_ids.*' => [Rule::exists('departments', 'id')->where('organization_id', $org)],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules());
        $p = Project::create(collect($d)->except('department_ids')->all() + [
            'owner_id' => $d['owner_id'] ?? $request->user()->id, 'status_id' => $d['status_id'] ?? Lookup::id('project_status', 'planning'),
            'created_by' => $request->user()->id, 'currency_code' => isset($d['currency_code']) ? strtoupper($d['currency_code']) : null,
        ]);
        $this->syncDepartments($p, $d['department_ids'] ?? []);
        AuditLogger::log('project.created', $p, [], ['code' => $p->code]);
        return response()->json(['data' => $this->row($p->load(['status', 'owner', 'client']), 'manager')], 201);
    }

    public function update(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $d = $request->validate($this->rules($p));
        $old = $p->only(array_keys($d));
        $p->update(collect($d)->except('department_ids')->all());
        if (array_key_exists('department_ids', $d)) $this->syncDepartments($p, $d['department_ids']);
        AuditLogger::log('project.updated', $p, collect($old)->except('department_ids')->all(), collect($d)->except('department_ids')->all());
        return ['data' => $this->row($p->load(['status', 'owner', 'client']), $level)];
    }

    public function destroy(string $id)
    {
        $p = Project::findOrFail($id);
        $p->delete();
        AuditLogger::log('project.deleted', $p);
        return response()->noContent();
    }

    // ---- members and milestones (project managers) ---------------------------------------------------------------------------
    public function storeMember(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $d = $request->validate([
            'user_id' => ['required', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->org())],
            'project_role_id' => ['required', Rule::exists('lookup_values', 'id')],
        ]);
        $m = ProjectMember::updateOrCreate(['project_id' => $p->id, 'user_id' => $d['user_id']], ['project_role_id' => $d['project_role_id']]);
        AuditLogger::log('project.member_set', $p, [], ['user_id' => $d['user_id'], 'role_id' => $d['project_role_id']]);
        return response()->json(['id' => $m->id], 201);
    }

    public function destroyMember(Request $request, string $id, string $userId)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        ProjectMember::where('project_id', $p->id)->where('user_id', $userId)->delete();
        Task::where('project_id', $p->id)->where('assignee_id', $userId)->update(['assignee_id' => null]);      // no orphan assignments
        AuditLogger::log('project.member_removed', $p, [], ['user_id' => $userId]);
        return response()->noContent();
    }

    public function storeMilestone(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $d = $request->validate(['name' => 'required|string|max:255', 'due_date' => 'nullable|date']);
        $m = Milestone::create($d + ['project_id' => $p->id]);
        AuditLogger::log('milestone.created', $m, [], $d);
        return response()->json(['id' => $m->id], 201);
    }

    public function updateMilestone(Request $request, string $id, string $milestoneId)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $m = Milestone::where('project_id', $p->id)->findOrFail($milestoneId);
        $d = $request->validate(['name' => 'sometimes|string|max:255', 'due_date' => 'nullable|date', 'completed' => 'sometimes|boolean']);
        if (array_key_exists('completed', $d)) { $m->completed_at = $d['completed'] ? now() : null; unset($d['completed']); }
        $m->fill($d)->save();
        AuditLogger::log('milestone.updated', $m);
        return response()->noContent();
    }

    public function destroyMilestone(Request $request, string $id, string $milestoneId)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        Milestone::where('project_id', $p->id)->findOrFail($milestoneId)->delete();
        AuditLogger::log('milestone.deleted', $p);
        return response()->noContent();
    }

    private function syncDepartments(Project $p, array $ids): void
    {
        $p->departments()->sync(collect($ids)->mapWithKeys(fn ($i) => [$i => ['id' => (string) \Illuminate\Support\Str::ulid(), 'organization_id' => $p->organization_id]])->all());
    }
}
