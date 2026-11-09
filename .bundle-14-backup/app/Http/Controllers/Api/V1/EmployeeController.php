<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Canonical person record. Every query is department-scoped (visibleTo); sensitive fields have their own audited endpoint. */
class EmployeeController extends Controller
{
    use AppliesListQuery;

    private function q(Request $r) { return Employee::query()->visibleTo($r->user()); }

    public function index(Request $request)
    {
        if ($request->query('export')) {
            AuditLogger::log('employee.exported', null, [], ['format' => $request->query('export')]);
        }
        return $this->respondList($request, $this->q($request)->with(['category', 'status', 'department', 'manager']), EmployeeResource::class,
            ['first_name', 'last_name', 'employee_number', 'email', 'position_title'], ['last_name', 'employee_number', 'hired_on', 'created_at'],
            ['category_id', 'status_id', 'department_id'], ['employee_number', 'full_name', 'email', 'phone', 'position_title', 'category', 'status', 'department', 'hired_on']);
    }

    public function show(Request $request, string $id)
    {
        return new EmployeeResource($this->q($request)->with(['category', 'status', 'department', 'manager'])->findOrFail($id));
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $e = Employee::create($d);
        AuditLogger::log('employee.created', $e, [], collect($d)->except(['national_id', 'bank_account'])->all());
        return (new EmployeeResource($e->load(['category', 'status', 'department', 'manager'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $e = $this->q($request)->findOrFail($id);
        $d = $this->validated($request, $e);
        // Category, department, manager and contract dates have an effective-dated history: they change only through an employment change.
        foreach (['category_id', 'department_id', 'manager_employee_id', 'contract_end_on', 'probation_end_on'] as $f) {
            if (array_key_exists($f, $d)) {
                $cur = $e->{$f} instanceof \DateTimeInterface ? $e->{$f}->format('Y-m-d') : $e->{$f};
                if (($d[$f] ?: null) !== ($cur ?: null)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([$f => [__('hr.employment.use_change')]]);
                }
                unset($d[$f]);
            }
        }
        $old = $e->only(array_keys(collect($d)->except(['national_id', 'bank_account'])->all()));
        $e->update($d);
        AuditLogger::log('employee.updated', $e, $old, collect($d)->except(['national_id', 'bank_account'])->all());
        return new EmployeeResource($e->load(['category', 'status', 'department', 'manager']));
    }

    public function destroy(Request $request, string $id)
    {
        $e = $this->q($request)->findOrFail($id);
        $e->delete();
        AuditLogger::log('employee.deleted', $e);
        return response()->noContent();
    }

    /** One optional login per employee, one employee per login. */
    public function linkUser(Request $request, string $id)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $d = $request->validate(['user_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org), Rule::unique('hr_employees', 'user_id')->ignore($id)]]);
        $e = $this->q($request)->findOrFail($id);
        $e->update(['user_id' => $d['user_id'] ?? null]);
        AuditLogger::log($d['user_id'] ?? null ? 'employee.user_linked' : 'employee.user_unlinked', $e);
        return new EmployeeResource($e);
    }

    /** National ID and bank account: separate permission, never in lists, every read audited. perm: employee.view_sensitive */
    public function sensitive(Request $request, string $id)
    {
        $e = $this->q($request)->findOrFail($id);
        AuditLogger::log('employee.sensitive_viewed', $e);
        return ['national_id' => $e->national_id, 'bank_account' => $e->bank_account];
    }

    private function validated(Request $r, ?Employee $m = null): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        $rules = [
            // no 'employee_number': system-generated (EMP-0001) and immutable
            'first_name' => [$m ? 'sometimes' : 'required', 'string', 'max:100'], 'last_name' => [$m ? 'sometimes' : 'required', 'string', 'max:100'],
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'position_title' => 'nullable|string|max:150',
            'category_id' => [$m ? 'sometimes' : 'required', Rule::exists('lookup_values', 'id')], 'status_id' => ['sometimes', Rule::exists('lookup_values', 'id')],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'manager_employee_id' => ['nullable', Rule::exists('hr_employees', 'id')->where('organization_id', $org), Rule::notIn([$m?->id])],
            'hired_on' => 'nullable|date', 'ended_on' => 'nullable|date|after_or_equal:hired_on',
            'contract_end_on' => 'nullable|date_format:Y-m-d', 'probation_end_on' => 'nullable|date_format:Y-m-d',
        ];
        if (app(OrganizationContext::class)->can('employee.manage_sensitive')) {
            $rules += ['national_id' => 'nullable|string|max:60', 'bank_account' => 'nullable|string|max:80'];
        }
        $d = $r->validate($rules);
        if (!$m) {
            $d['status_id'] ??= Lookup::id('employee_status', 'active');
        }
        return $d;
    }
}
