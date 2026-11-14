<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\HrPosition;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\MoneyService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Effective-dated employment history (Guide 5.2). A change is a new dated row; past rows are never rewritten.
 * Promotion, confirmation, salary and category changes go through the approval engine; the employee record
 * (hr_employees) is only a snapshot of the latest effective row whose date has arrived.
 */
class EmploymentService
{
    /** Change types that need approval. Everything else (transfer, manager change) is effective once recorded. */
    public const APPROVAL_REQUIRED = ['promotion', 'confirmation', 'salary_change', 'category_change'];
    public const TYPES = ['promotion', 'confirmation', 'transfer', 'category_change', 'salary_change', 'manager_change'];
    private const TRACKED = ['category_id', 'position_id', 'grade_id', 'department_id', 'manager_employee_id', 'contract_type_id', 'contract_end_on', 'probation_end_on'];

    /** Extra facts for the hire row (salary, contract type, reason) when an employee is created by recruitment conversion. */
    public static array $hireExtras = [];

    public static function withHireExtras(array $extras, \Closure $fn): mixed
    {
        self::$hireExtras = $extras;
        try {
            return $fn();
        } finally {
            self::$hireExtras = [];
        }
    }

    public function __construct(private ApprovalEngine $approvals) {}

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => [__("hr.employment.$key")]]);
    }

    private function status(string $code): string { return Lookup::id('employment_record_status', $code); }

    public static function asOfRecord(string $employeeId, string $date): ?EmploymentRecord
    {
        return EmploymentRecord::query()->where('employee_id', $employeeId)
            ->where('status_id', Lookup::id('employment_record_status', 'effective'))
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('created_at')->first();
    }

    /** The first row of every employee: how they entered the organization. */
    public function recordHire(Employee $e, ?User $by = null): EmploymentRecord
    {
        $date = ($e->hired_on ?? now())->toDateString();
        $rec = EmploymentRecord::create([
            'organization_id' => $e->organization_id, 'employee_id' => $e->id, 'change_type_id' => Lookup::id('hr_change_type', 'hire'),
            'status_id' => $this->status('effective'), 'effective_from' => $date, 'category_id' => $e->category_id, 'position_id' => $e->position_id,
            'grade_id' => $e->grade_id, 'department_id' => $e->department_id, 'manager_employee_id' => $e->manager_employee_id,
            'contract_end_on' => $e->contract_end_on?->toDateString(), 'probation_end_on' => $e->probation_end_on?->toDateString(),
            'created_by' => $by?->id ?? request()->user()?->id, 'applied_at' => now(),
        ] + array_intersect_key(self::$hireExtras, array_flip(['salary_amount', 'salary_currency', 'contract_type_id', 'reason'])));
        return $rec;
    }

    /** Propose (or, for types without approval, record) a dated change. */
    public function propose(Employee $employee, array $d, User $by): EmploymentRecord
    {
        $type = $d['change_type'];
        abort_unless(in_array($type, self::TYPES, true), 422);

        return DB::transaction(function () use ($employee, $d, $by, $type) {
            $e = Employee::lockForUpdate()->findOrFail($employee->id);
            $effective = $this->status('effective');

            if (EmploymentRecord::where('employee_id', $e->id)->where('status_id', $this->status('pending_approval'))->exists()) {
                $this->fail('employee', 'pending_exists');
            }
            $last = EmploymentRecord::where('employee_id', $e->id)->where('status_id', $effective)->orderByDesc('effective_from')->orderByDesc('created_at')->first();
            if ($last && $d['effective_from'] <= $last->effective_from->toDateString()) {
                $this->fail('effective_from', 'date_order');
            }

            // Base = the latest effective row; only the employee snapshot columns can stand in when there is none yet.
            $snap = ['category_id', 'position_id', 'grade_id', 'department_id', 'manager_employee_id', 'contract_end_on', 'probation_end_on'];
            $base = function (string $f) use ($last, $e, $snap) {
                $v = $last ? $last->{$f} : (in_array($f, $snap, true) ? $e->{$f} : null);
                return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
            };
            $new = [];
            foreach (self::TRACKED as $f) {
                $new[$f] = array_key_exists($f, $d) ? $d[$f] : $base($f);
                if ($f === 'category_id' && $new[$f] === null) {
                    $new[$f] = $base($f);                                  // the category can be changed but never cleared
                }
            }
            if ($type === 'confirmation') {
                $new['probation_end_on'] = null;                       // confirmed: the probation period is over
            }

            $changed = false;
            foreach (self::TRACKED as $f) {
                $changed = $changed || ($base($f) !== $new[$f]);
            }
            $salary = $last?->salary_amount;
            $currency = $last?->salary_currency;
            if (array_key_exists('salary_amount', $d) && $d['salary_amount'] !== null) {
                $amount = MoneyService::round((string) $d['salary_amount'], 2);
                $changed = $changed || $amount !== ($salary === null ? null : MoneyService::round((string) $salary, 2));
                $salary = $amount;
                $currency = $d['salary_currency'] ?? $currency;
            }
            if ($type === 'salary_change' && ($d['salary_amount'] ?? null) === null) {
                $this->fail('salary_amount', 'salary_required');
            }
            if ($type === 'salary_change' && !$currency) {
                $this->fail('salary_currency', 'currency_required');
            }
            if (!$changed) {
                $this->fail('change_type', 'nothing_changed');
            }
            if ($type === 'confirmation' && $new['category_id'] === $base('category_id')) {
                $this->fail('category_id', 'confirmation_needs_category');
            }

            $needsApproval = in_array($type, self::APPROVAL_REQUIRED, true);
            $rec = EmploymentRecord::create($new + [
                'organization_id' => $e->organization_id, 'employee_id' => $e->id, 'change_type_id' => Lookup::id('hr_change_type', $type),
                'status_id' => $this->status($needsApproval ? 'pending_approval' : 'effective'), 'effective_from' => $d['effective_from'],
                'salary_amount' => $salary, 'salary_currency' => $currency, 'reason' => $d['reason'] ?? null, 'review_id' => $d['review_id'] ?? null,
                'created_by' => $by->id,
            ]);
            AuditLogger::log('employment.change_proposed', $rec, [], ['type' => $type, 'effective_from' => $d['effective_from'], 'needs_approval' => $needsApproval]);

            if ($needsApproval) {
                $req = $this->approvals->submit('employment_change', $rec->id, $by, __("hr.employment.approval_title.$type", ['name' => $e->full_name]), ['department_id' => $e->department_id]);
                $rec->update(['approval_request_id' => $req->id]);
            } else {
                $this->syncSnapshot($e->id);
            }
            return $rec->refresh();
        });
    }

    /** Approval outcome. */
    public function applyOutcome(string $recordId, string $outcome): void
    {
        $rec = EmploymentRecord::withoutGlobalScopes()->find($recordId);
        if (!$rec || $rec->status_id !== $this->status('pending_approval')) {
            return;
        }
        $rec->update(['status_id' => $this->status($outcome)]);
        AuditLogger::log("employment.change_$outcome", $rec);
        if ($outcome === 'effective') {
            $this->syncSnapshot($rec->employee_id);
        }
    }

    /** Bring hr_employees up to the latest effective row whose date has arrived. Idempotent. */
    public function syncSnapshot(string $employeeId, ?string $today = null): void
    {
        $today ??= now()->toDateString();
        $rec = self::asOfRecord($employeeId, $today);
        if (!$rec) {
            return;
        }
        $e = Employee::withoutGlobalScopes()->withTrashed()->find($employeeId);
        if (!$e) {
            return;
        }
        $title = $rec->position_id ? HrPosition::withoutGlobalScopes()->where('id', $rec->position_id)->value('title') : null;
        $e->forceFill([
            'category_id' => $rec->category_id, 'department_id' => $rec->department_id, 'manager_employee_id' => $rec->manager_employee_id,
            'position_id' => $rec->position_id, 'grade_id' => $rec->grade_id, 'position_title' => $title ?? $e->position_title,
            'contract_end_on' => $rec->contract_end_on?->toDateString(), 'probation_end_on' => $rec->probation_end_on?->toDateString(),
        ])->saveQuietly();
        EmploymentRecord::withoutGlobalScopes()->where('employee_id', $employeeId)->where('status_id', $this->status('effective'))
            ->whereDate('effective_from', '<=', $today)->whereNull('applied_at')->update(['applied_at' => now()]);
    }

    /** Daily job: apply dated changes whose day has come. */
    public function applyDue(?string $today = null): int
    {
        $today ??= now()->toDateString();
        $ids = EmploymentRecord::withoutGlobalScopes()->where('status_id', $this->status('effective'))->whereNull('applied_at')
            ->whereDate('effective_from', '<=', $today)->distinct()->pluck('employee_id');
        foreach ($ids as $id) {
            $this->syncSnapshot($id, $today);
        }
        return $ids->count();
    }

    /** Months in the current grade, from the continuous run of effective rows with that grade. */
    public function monthsInGrade(string $employeeId, ?string $today = null): ?int
    {
        $today ??= now()->toDateString();
        $rows = EmploymentRecord::where('employee_id', $employeeId)->where('status_id', $this->status('effective'))
            ->orderBy('effective_from')->orderBy('created_at')->get()
            ->map(fn ($r) => ['from' => $r->effective_from->toDateString(), 'grade' => $r->grade_id])->all();
        $since = CareerPath::gradeSince($rows, $today);
        return $since === null ? null : CareerPath::months($since, $today);
    }
}
