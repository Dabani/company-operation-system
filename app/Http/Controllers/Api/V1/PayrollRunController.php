<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LookupType;
use App\Models\PayPayslip;
use App\Models\PayRun;
use App\Models\PayRunExclusion;
use App\Models\PayRunInput;
use App\Services\AuditLogger;
use App\Services\Hr\Payroll\PayrollRunService;
use App\Services\Hr\Payroll\PayslipPdf;
use App\Services\Hr\Payroll\PayslipPresenter;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll runs and payslips (Guide 5.6.1). Permissions per stage: hr.payroll.prepare (create, inputs, calculate, cancel), review, approve (finance), approve_md,
 * lock, pay, declare; hr.payroll.view reads. The service enforces who may do what at each stage (separation of duties) and refuses any change to a locked run.
 */
class PayrollRunController extends Controller
{
    public function __construct(private PayrollRunService $svc) {}

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }
    private function can(string $p): bool { return app(OrganizationContext::class)->can($p); }

    private function shape(PayRun $r, bool $detail = false): array
    {
        $r->loadMissing(['kind', 'status', 'currency', 'parent', 'preparer']);
        $out = ['id' => $r->id, 'code' => $r->code, 'kind' => $r->kind->code, 'period_from' => $r->period_from->toDateString(), 'period_to' => $r->period_to->toDateString(), 'currency' => $r->currency->code, 'status' => $r->status->code,
            'parent' => $r->parent ? ['id' => $r->parent->id, 'code' => $r->parent->code] : null, 'md_required' => $r->md_required, 'proration_mode' => $r->proration_mode, 'prepared_by' => $r->preparer?->name, 'note' => $r->note];
        if (!$detail) {
            return $out;
        }
        $who = fn ($id) => $id ? \App\Models\User::find($id)?->name : null;
        return $out + ['totals' => $this->svc->totals($r), 'returned_reason' => $r->returned_reason, 'cancel_reason' => $r->cancel_reason, 'earnings_decimals' => $r->earnings_decimals,
            'stages' => ['calculated_by' => $who($r->calculated_by), 'calculated_at' => $r->calculated_at?->toIso8601String(), 'reviewed_by' => $who($r->reviewed_by), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'finance_by' => $who($r->finance_by), 'finance_at' => $r->finance_at?->toIso8601String(), 'md_by' => $who($r->md_by), 'md_at' => $r->md_at?->toIso8601String(), 'locked_by' => $who($r->locked_by), 'locked_at' => $r->locked_at?->toIso8601String(),
                'paid_on' => $r->paid_on?->toDateString(), 'paid_by' => $who($r->paid_by), 'payment_reference' => $r->payment_reference, 'declared_on' => $r->declared_on?->toDateString(), 'declared_by' => $who($r->declared_by), 'declaration_reference' => $r->declaration_reference],
            'inputs' => PayRunInput::with(['component', 'employee'])->where('run_id', $r->id)->get()->map(fn ($i) => ['id' => $i->id, 'employee_id' => $i->employee_id, 'employee' => $i->employee?->full_name, 'component' => $i->component->code, 'amount' => (string) $i->amount, 'note' => $i->note])->values(),
            'exclusions' => PayRunExclusion::where('run_id', $r->id)->get()->map(fn ($x) => ['employee_id' => $x->employee_id, 'reason' => $x->reason])->values()];
    }

    private function find(string $id): PayRun { return PayRun::with(['kind', 'status', 'currency', 'parent', 'preparer'])->findOrFail($id); }

    public function index(Request $request)
    {
        $q = PayRun::with(['kind', 'status', 'currency', 'parent', 'preparer'])->orderByDesc('period_from')->orderByDesc('created_at')->limit(200);
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id('pay_run_status', $s));
        }
        return ['data' => $q->get()->map(fn ($r) => $this->shape($r))];
    }

    public function show(string $id) { return ['data' => $this->shape($this->find($id), true)]; }

    public function store(Request $request)
    {
        $d = $request->validate(['period_from' => 'required|date_format:Y-m-d', 'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from', 'note' => 'nullable|string|max:2000',
            'currency_id' => ['required', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', 'currency')->pluck('id'))]]);
        return response()->json(['data' => $this->shape($this->svc->create($request->user(), $d), true)], 201);
    }

    public function adjustment(Request $request, string $id)
    {
        $d = $request->validate(['employee_ids' => 'required|array|min:1|max:200', 'employee_ids.*' => 'string', 'note' => 'nullable|string|max:2000']);
        return response()->json(['data' => $this->shape($this->svc->createAdjustment($request->user(), $this->find($id), $d['employee_ids'], $d['note'] ?? null), true)], 201);
    }

    // ---- inputs and exclusions -------------------------------------------------------------------------------------------------------------
    public function addInput(Request $request, string $id)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'component_id' => ['required', Rule::exists('pay_components', 'id')->where('organization_id', $this->org())],
            'amount' => 'required|numeric|min:0|max:9999999999999', 'note' => 'nullable|string|max:255']);
        $i = $this->svc->addInput($this->find($id), $request->user(), $d);
        return response()->json(['data' => ['id' => $i->id]], 201);
    }

    public function removeInput(string $id, string $inputId) { $this->svc->removeInput($this->find($id), $inputId); return response()->noContent(); }

    public function exclude(Request $request, string $id)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'reason' => 'required|string|max:255']);
        $this->svc->exclude($this->find($id), $request->user(), $d['employee_id'], $d['reason']);
        return response()->noContent();
    }

    public function include(string $id, string $employeeId) { $this->svc->include($this->find($id), $employeeId); return response()->noContent(); }

    // ---- lifecycle -------------------------------------------------------------------------------------------------------------------------
    public function calculate(Request $request, string $id)
    {
        $r = $this->find($id);
        $count = $this->svc->calculate($r, $request->user());
        return ['data' => $this->shape($r->refresh(), true) + ['result' => $count]];
    }

    public function review(Request $request, string $id) { return ['data' => $this->shape($this->svc->review($this->find($id), $request->user()), true)]; }
    public function approveFinance(Request $request, string $id) { return ['data' => $this->shape($this->svc->approve($this->find($id), $request->user(), 'approve_finance'), true)]; }
    public function approveMd(Request $request, string $id) { return ['data' => $this->shape($this->svc->approve($this->find($id), $request->user(), 'approve_md'), true)]; }
    public function lock(Request $request, string $id) { return ['data' => $this->shape($this->svc->lock($this->find($id), $request->user()), true)]; }

    public function returnToDraft(Request $request, string $id)
    {
        abort_unless($this->can('hr.payroll.review') || $this->can('hr.payroll.approve') || $this->can('hr.payroll.approve_md'), 403, 'forbidden');
        $d = $request->validate(['reason' => 'required|string|max:2000']);
        return ['data' => $this->shape($this->svc->returnToDraft($this->find($id), $request->user(), $d['reason']), true)];
    }

    public function pay(Request $request, string $id)
    {
        $d = $request->validate(['paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'nullable|string|max:100']);
        return ['data' => $this->shape($this->svc->markPaid($this->find($id), $request->user(), $d['paid_on'], $d['reference'] ?? null), true)];
    }

    public function declare(Request $request, string $id)
    {
        $d = $request->validate(['declared_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'nullable|string|max:100']);
        return ['data' => $this->shape($this->svc->declare($this->find($id), $request->user(), $d['declared_on'], $d['reference'] ?? null), true)];
    }

    public function cancel(Request $request, string $id)
    {
        $d = $request->validate(['reason' => 'required|string|max:2000']);
        return ['data' => $this->shape($this->svc->cancel($this->find($id), $request->user(), $d['reason']), true)];
    }

    // ---- payslips (HR view) -----------------------------------------------------------------------------------------------------------------
    public function payslips(string $id)
    {
        $run = $this->find($id);
        $rows = PayPayslip::with(['status', 'flags.severity'])->where('run_id', $run->id)->orderBy('employee_name')->get();
        return ['data' => $rows->map(fn ($p) => ['id' => $p->id, 'employee_id' => $p->employee_id, 'employee' => $p->employee_name, 'number' => $p->employee_number, 'status' => $p->status->code, 'gross' => (string) $p->gross,
            'employee_contributions' => (string) $p->employee_contributions, 'deductions' => (string) $p->deductions, 'net' => (string) $p->net, 'employer_cost' => (string) $p->employer_cost, 'net_difference' => $p->net_difference === null ? null : (string) $p->net_difference,
            'blockers' => $p->flags->filter(fn ($f) => $f->severity->code === 'blocker')->count(), 'warnings' => $p->flags->filter(fn ($f) => $f->severity->code === 'warning')->count(), 'excluded_reason' => $p->excluded_reason])->values()];
    }

    public function payslip(string $payslipId)
    {
        $p = PayPayslip::findOrFail($payslipId);
        AuditLogger::log('payroll.payslip_viewed', $p, [], ['run' => $p->run_id, 'employee_id' => $p->employee_id]);             // a salary read: audited, without amounts
        return ['data' => PayslipPresenter::detail($p, app()->getLocale())];
    }

    public function payslipPdf(Request $request, string $payslipId)
    {
        $p = PayPayslip::findOrFail($payslipId);
        AuditLogger::log('payroll.payslip_downloaded', $p, [], ['run' => $p->run_id, 'employee_id' => $p->employee_id]);
        $locale = in_array($request->query('locale'), ['en', 'fr', 'rw', 'sw'], true) ? $request->query('locale') : app()->getLocale();
        return response((new PayslipPdf())->render($p, $locale), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="payslip-' . $p->run_id . '-' . ($p->employee_number ?: $p->employee_id) . '.pdf"']);
    }
}
