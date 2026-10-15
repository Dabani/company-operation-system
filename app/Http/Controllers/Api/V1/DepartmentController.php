<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Models\Department;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use AppliesListQuery;

    public function index(Request $request)
    {
        return $this->respondList($request, Department::query()->with('parent'), DepartmentResource::class,
            ['code', 'name'], ['name', 'code', 'created_at'], ['is_active', 'parent_id'], ['code', 'name', 'parent', 'is_active']);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $m = Department::create($d);
        AuditLogger::log('department.created', $m, [], $d);
        return (new DepartmentResource($m->load('parent')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $m = Department::findOrFail($id);
        $d = $this->validated($request, $m);
        abort_if(($d['parent_id'] ?? null) === $m->id, 422);
        $old = $m->only(array_keys($d));
        $m->update($d);
        AuditLogger::log('department.updated', $m, $old, $d);
        return new DepartmentResource($m->load('parent'));
    }

    public function destroy(string $id)
    {
        $m = Department::findOrFail($id);
        abort_if(\App\Models\Membership::where('department_id', $m->id)->exists(), 422, __('api.in_use'));
        $m->delete();
        AuditLogger::log('department.deleted', $m);
        return response()->noContent();
    }

    private function validated(Request $r, ?Department $m = null): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        return $r->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('departments', 'code')->where('organization_id', $org)->ignore($m?->id)],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'parent_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'is_active' => 'boolean',
        ]);
    }
}
