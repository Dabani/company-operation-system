<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\HrProgressionCriterion;
use App\Models\PerformanceReview;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Hr\CareerPath;
use App\Services\Hr\EmploymentService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use App\Support\LookupRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employment history, dated changes, career progression and the employee's own view (Guide 5.2, 5.7). */
class EmploymentController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }
    private function employee(Request $r, string $id): Employee { return Employee::query()->visibleTo($r->user())->findOrFail($id); }

    private const WITH = ['changeType', 'status', 'category', 'position', 'grade', 'department'];

    private function shape(EmploymentRecord $r, bool $salary, ?string $validTo = null): array
    {
        $o = [
            'id' => $r->id, 'employee_id' => $r->employee_id, 'change_type' => $r->changeType?->code, 'status' => $r->status?->code,
            'effective_from' => $r->effective_from->toDateString(), 'valid_to' => $validTo, 'category' => $r->category?->code, 'category_id' => $r->category_id,
            'position_id' => $r->position_id, 'position' => $r->position?->title, 'grade_id' => $r->grade_id, 'grade' => $r->grade?->name,
            'department_id' => $r->department_id, 'department' => $r->department?->name, 'manager_employee_id' => $r->manager_employee_id,
            'contract_type_id' => $r->contract_type_id, 'contract_end_on' => $r->contract_end_on?->toDateString(), 'probation_end_on' => $r->probation_end_on?->toDateString(),
            'reason' => $r->reason, 'review_id' => $r->review_id, 'approval_request_id' => $r->approval_request_id, 'created_by' => $r->created_by, 'applied' => $r->applied_at !== null,
        ];
        return $salary ? $o + ['salary_amount' => $r->salary_amount, 'salary_currency' => $r->salary_currency] : $o;
    }

    /** Rows newest first; each effective row also shows the day before the next effective row starts. */
    private function shapeAll($records, bool $salary): array
    {
        $effective = Lookup::id('employment_record_status', 'effective');
        $asc = $records->where('status_id', $effective)->sortBy(fn ($r) => $r->effective_from->toDateString() . $r->created_at->format('His.u'))->values();
        $validTo = [];
        foreach ($asc as $i => $r) {
            $next = $asc[$i + 1] ?? null;
            $validTo[$r->id] = $next ? CarbonImmutable::parse($next->effective_from->toDateString())->subDay()->toDateString() : null;
        }
        return $records->map(fn ($r) => $this->shape($r, $salary, $validTo[$r->id] ?? null))->values()->all();
    }

    public function history(Request $request, string $id)
    {
        $e = $this->employee($request, $id);
        $salary = $this->ctx()->can('hr.salary.view');
        $records = EmploymentRecord::with(self::WITH)->where('employee_id', $e->id)->orderByDesc('effective_from')->orderByDesc('created_at')->get();
        if ($salary) {
            AuditLogger::log('employment.salary_viewed', $e);
        }
        return ['data' => $this->shapeAll($records, $salary)];
    }

    /** The state in force on a date: what payroll must use to reproduce a past period. */
    public function asOf(Request $request, string $id)
    {
        $e = $this->employee($request, $id);
        $d = $request->validate(['date' => 'required|date_format:Y-m-d']);
        $rec = EmploymentService::asOfRecord($e->id, $d['date']);
        abort_unless($rec, 404);
        $salary = $this->ctx()->can('hr.salary.view');
        if ($salary) {
            AuditLogger::log('employment.salary_viewed', $e);
        }
        return ['data' => $this->shape($rec->load(self::WITH), $salary)];
    }

    public function propose(Request $request, string $id, EmploymentService $svc)
    {
        $e = $this->employee($request, $id);
        $org = $this->ctx()->organizationId();
        $exists = fn (string $table) => ['nullable', Rule::exists($table, 'id')->where('organization_id', $org)];
        $d = $request->validate([
            'change_type' => ['required', Rule::in(EmploymentService::TYPES)], 'effective_from' => 'required|date_format:Y-m-d',
            'category_id' => ['nullable', Rule::exists('lookup_values', 'id')], 'contract_type_id' => ['nullable', Rule::exists('lookup_values', 'id')],
            'position_id' => $exists('hr_positions'), 'grade_id' => $exists('hr_grades'), 'department_id' => $exists('departments'), 'manager_employee_id' => $exists('hr_employees'),
            'contract_end_on' => 'nullable|date_format:Y-m-d', 'probation_end_on' => 'nullable|date_format:Y-m-d',
            'salary_amount' => 'nullable|numeric|min:0|max:9999999999999', 'salary_currency' => ['nullable', LookupRule::code('currency')],
            'reason' => 'nullable|string|max:2000', 'review_id' => $exists('hr_performance_reviews'),
        ]);
        if (array_key_exists('salary_amount', $d) && $d['salary_amount'] !== null && !$this->ctx()->can('hr.salary.view')) {
            abort(403, 'forbidden');
        }
        if (($d['manager_employee_id'] ?? null) === $e->id) {
            abort(422, 'An employee cannot be their own manager.');
        }
        $rec = $svc->propose($e, $d, $request->user());
        return response()->json(['data' => $this->shape($rec->load(self::WITH), $this->ctx()->can('hr.salary.view'))], 201);
    }

    /** Pending and recent changes across the people the user may see. */
    public function changes(Request $request)
    {
        $status = $request->query('status', 'pending_approval');
        $q = EmploymentRecord::with(array_merge(self::WITH, ['employee']))
            ->whereIn('employee_id', Employee::query()->visibleTo($request->user())->select('hr_employees.id'))
            ->where('status_id', Lookup::id('employment_record_status', $status))->orderByDesc('created_at')->limit(200);
        $salary = $this->ctx()->can('hr.salary.view');
        return ['data' => $q->get()->map(fn ($r) => $this->shape($r, $salary) + ['employee' => $r->employee?->full_name])->values()];
    }

    public function cancelChange(Request $request, string $id, ApprovalEngine $engine)
    {
        $rec = EmploymentRecord::findOrFail($id);
        abort_unless($rec->created_by === $request->user()->id, 403, 'forbidden');
        abort_unless($rec->status_id === Lookup::id('employment_record_status', 'pending_approval') && $rec->approval_request_id, 422);
        $engine->cancel($rec->approval_request_id, $request->user());
        return ['data' => $this->shape($rec->refresh()->load(self::WITH), false)];
    }

    /** Months in grade, latest review rating, and which next grades the employee already qualifies for. */
    public function career(Request $request, string $id, EmploymentService $svc)
    {
        $e = $this->employee($request, $id);
        $months = $svc->monthsInGrade($e->id);
        $review = PerformanceReview::with('rating')->where('employee_id', $e->id)->whereNotNull('submitted_at')->orderByDesc('submitted_at')->first();
        $level = $review?->rating?->sort_order;
        $next = $e->grade_id ? HrProgressionCriterion::with('toGrade')->where('from_grade_id', $e->grade_id)->get()->map(function ($c) use ($months, $level) {
            $r = CareerPath::evaluate(['min_months_in_grade' => $c->min_months_in_grade, 'min_rating_level' => $c->min_rating_level], (int) $months, $level);
            return ['to_grade_id' => $c->to_grade_id, 'to_grade' => $c->toGrade?->name, 'min_months_in_grade' => $c->min_months_in_grade, 'min_rating_level' => $c->min_rating_level] + $r;
        })->values() : [];
        return ['data' => ['grade_id' => $e->grade_id, 'months_in_grade' => $months, 'latest_rating_level' => $level, 'latest_review_id' => $review?->id,
            'probation_end_on' => $e->probation_end_on?->toDateString(), 'contract_end_on' => $e->contract_end_on?->toDateString(), 'next_grades' => $next]];
    }

    /** Self-service: my own profile, history (no salary) and submitted reviews. */
    public function mine(Request $request)
    {
        $e = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($e, 404);
        $records = EmploymentRecord::with(self::WITH)->where('employee_id', $e->id)
            ->where('status_id', Lookup::id('employment_record_status', 'effective'))->orderByDesc('effective_from')->orderByDesc('created_at')->get();
        $reviews = PerformanceReview::with(['cycle', 'rating', 'recommendation', 'status'])->where('employee_id', $e->id)->whereNotNull('submitted_at')->orderByDesc('submitted_at')->get()
            ->map(fn ($r) => ['id' => $r->id, 'cycle' => $r->cycle?->name, 'status' => $r->status?->code, 'rating' => $r->rating?->code, 'recommendation' => $r->recommendation?->code,
                'submitted_at' => $r->submitted_at?->toIso8601String()])->values();
        return ['data' => ['employee' => (new EmployeeResource($e->load(['category', 'status', 'department', 'manager'])))->resolve($request),
            'history' => $this->shapeAll($records, false), 'reviews' => $reviews]];
    }
}
