<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\DashboardWidget;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    public function index(Request $request)
    {
        $q = Role::query()->withCount('memberships')->with(['permissions', 'widgets']);
        return $this->respondList($request, $q, RoleResource::class, ['name', 'code'], ['level', 'name', 'code'], ['is_active'],
            ['code', 'name', 'level', 'users_count']);
    }

    public function permissions()
    {
        $perms = Permission::with('group.translations.language')->orderBy('code')->get();
        $lang = request()->user()->language_id;
        return response()->json(['data' => $perms->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description,
            'group' => $p->group->code, 'group_label' => $p->group->label($lang), 'group_order' => $p->group->sort_order,
        ])->sortBy('group_order')->values()]);
    }

    public function widgets()
    {
        return response()->json(['data' => DashboardWidget::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_key'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        abort_if($data['level'] > $this->ctx->level(), 403, __('api.role_above_authority'));
        $role = Role::create($data + ['is_system' => false]);
        $this->syncRelations($role, $request);
        AuditLogger::log('role.created', $role, [], $data);
        return (new RoleResource($role->load(['permissions', 'widgets'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $role = Role::findOrFail($id);
        abort_if($role->level > $this->ctx->level(), 403, __('api.role_above_authority'));
        $data = $this->validated($request, $role);
        abort_if($data['level'] > $this->ctx->level(), 403, __('api.role_above_authority'));
        if ($role->is_system) {
            unset($data['code']);                 // system role codes are stable identifiers
        }
        $old = $role->only(array_keys($data));
        $oldPerms = $role->permissions()->pluck('code')->sort()->implode(',');
        $role->update($data);
        $this->syncRelations($role, $request);
        AuditLogger::log('role.updated', $role, $old + ['permissions' => $oldPerms], $data + ['permissions' => $role->permissions()->pluck('code')->sort()->implode(',')]);
        return new RoleResource($role->load(['permissions', 'widgets']));
    }

    public function destroy(string $id)
    {
        $role = Role::withCount('memberships')->findOrFail($id);
        abort_if($role->is_system, 422, __('api.system_role_locked'));
        abort_if($role->memberships_count > 0, 422, __('api.role_in_use'));
        abort_if($role->level > $this->ctx->level(), 403, __('api.role_above_authority'));
        $role->delete();
        AuditLogger::log('role.deleted', $role);
        return response()->noContent();
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $v = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:60', Rule::unique('roles', 'code')->where('organization_id', $this->ctx->organizationId())->ignore($role?->id)],
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'level' => 'required|integer|min:1|max:100',
            'color' => 'nullable|string|max:20',
            'is_active' => 'boolean',
            'permission_ids' => 'array', 'permission_ids.*' => 'exists:permissions,id',
            'widget_ids' => 'array', 'widget_ids.*' => 'exists:dashboard_widgets,id',
        ]);
        return \Illuminate\Support\Arr::except($v, ['permission_ids', 'widget_ids']);   // relations are synced separately
    }

    private function syncRelations(Role $role, Request $request): void
    {
        // A non-top actor can only grant permissions they hold themselves (no privilege escalation).
        if ($request->has('permission_ids')) {
            $ids = Permission::whereIn('id', $request->permission_ids)->get()
                ->filter(fn ($p) => $this->ctx->can($p->code))->pluck('id');
            $role->permissions()->sync($ids);
        }
        if ($request->has('widget_ids')) {
            $role->widgets()->sync(collect($request->widget_ids)->values()->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());
        }
    }
}
