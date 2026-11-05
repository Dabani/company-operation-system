<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LookupValue;
use App\Models\UserDepartmentAssignment;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Who sees which departments (own / team / department / organization) and who heads a department. perm: scope.manage */
class DepartmentAccessController extends Controller
{
    public function index()
    {
        return ['data' => UserDepartmentAssignment::with(['user', 'department', 'scopeLevel'])->orderBy('created_at', 'desc')->get()->map(fn ($a) => [
            'id' => $a->id, 'user_id' => $a->user_id, 'user' => $a->user?->name, 'department_id' => $a->department_id, 'department' => $a->department?->name,
            'scope_level_id' => $a->scope_level_id, 'scope_level' => $a->scopeLevel?->code, 'is_head' => $a->is_head])];
    }

    public function store(Request $request)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $d = $request->validate([
            'user_id' => ['required', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'scope_level_id' => ['required', Rule::exists('lookup_values', 'id')], 'is_head' => 'boolean',
        ]);
        $level = LookupValue::findOrFail($d['scope_level_id'])->code;
        if ($level === 'organization') {
            // Privilege escalation guard: only someone who already has organization-wide scope may grant it.
            abort_unless(app(OrganizationContext::class)->can('scope.organization'), 403, 'forbidden');
            $d['department_id'] = null;
        } elseif (empty($d['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('foundation.scope.department_required')]]);
        }
        $a = UserDepartmentAssignment::updateOrCreate(['user_id' => $d['user_id'], 'department_id' => $d['department_id']], ['scope_level_id' => $d['scope_level_id'], 'is_head' => $d['is_head'] ?? false]);
        AuditLogger::log('scope.assigned', $a, [], ['level' => $level, 'department_id' => $d['department_id'], 'is_head' => $a->is_head]);
        return response()->json(['id' => $a->id], 201);
    }

    public function destroy(string $id)
    {
        $a = UserDepartmentAssignment::findOrFail($id);
        $a->delete();
        AuditLogger::log('scope.revoked', $a);
        return response()->noContent();
    }
}
