<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayPayslip;
use App\Services\AuditLogger;
use App\Services\Hr\Payroll\PayslipPdf;
use App\Services\Hr\Payroll\PayslipPresenter;
use App\Support\Lookup;
use Illuminate\Http\Request;

/**
 * Employee self-service (perm hr.payslip.own): an employee sees ONLY their own payslips, and only once the run is paid (or declared), never a draft or a calculation in progress.
 * The employee is found from the signed-in user, never from a parameter: there is no way to ask for somebody else's payslip (404).
 */
class PayslipSelfController extends Controller
{
    private function mine(Request $r)
    {
        $eid = Employee::where('user_id', $r->user()->id)->value('id');
        return PayPayslip::query()->where('employee_id', $eid ?? '0')->where('status_id', Lookup::id('pay_payslip_status', 'calculated'))
            ->whereHas('run', fn ($q) => $q->whereIn('status_id', [Lookup::id('pay_run_status', 'paid'), Lookup::id('pay_run_status', 'declared')]));
    }

    public function index(Request $request)
    {
        $rows = $this->mine($request)->with(['run.status', 'run.kind'])->get()->sortByDesc(fn ($p) => $p->run->period_from)->values();
        return ['data' => $rows->map(fn ($p) => ['id' => $p->id, 'run_code' => $p->run->code, 'kind' => $p->run->kind->code, 'period_from' => $p->run->period_from->toDateString(), 'period_to' => $p->run->period_to->toDateString(),
            'paid_on' => $p->run->paid_on?->toDateString(), 'currency' => $p->currency_code, 'net' => (string) $p->net, 'net_difference' => $p->net_difference === null ? null : (string) $p->net_difference])];
    }

    public function show(Request $request, string $id)
    {
        $p = $this->mine($request)->findOrFail($id);
        AuditLogger::log('payroll.payslip_viewed_by_owner', $p, [], ['run' => $p->run_id]);
        $d = PayslipPresenter::detail($p, app()->getLocale());
        $d['flags'] = [];                                                    // review notes are for HR, not for the employee
        return ['data' => $d];
    }

    public function pdf(Request $request, string $id)
    {
        $p = $this->mine($request)->findOrFail($id);
        AuditLogger::log('payroll.payslip_downloaded_by_owner', $p, [], ['run' => $p->run_id]);
        return response((new PayslipPdf())->render($p, app()->getLocale()), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="payslip-' . $p->run->code . '.pdf"']);
    }
}
