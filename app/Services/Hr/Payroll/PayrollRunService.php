<?php

namespace App\Services\Hr\Payroll;

use App\Models\Employee;
use App\Models\LookupValue;
use App\Models\PayComponent;
use App\Models\PayPayslip;
use App\Models\PayPayslipBand;
use App\Models\PayPayslipFlag;
use App\Models\PayPayslipLine;
use App\Models\PayRun;
use App\Models\PayRunExclusion;
use App\Models\PayRunInput;
use App\Models\PayRunMember;
use App\Models\PayScheme;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\HrSettings;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll runs (Guide 5.6.1). Draft -> Calculated -> Reviewed -> Approved (Finance, then the MD where required) -> Locked -> Paid -> Declared, with different
 * people at each stage (PayRunFlow::conflict). Calculation persists a payslip per employee with every line and the inputs it came from. A locked run is refused
 * by the models themselves; a correction is an adjustment run that records the difference against the original payslip.
 */
class PayrollRunService
{
    public function __construct(private PayrollConfigResolver $config) {}

    private function st(string $c): string { return Lookup::id('pay_run_status', $c); }
    private function ps(string $c): string { return Lookup::id('pay_payslip_status', $c); }
    private function code(string $id): ?string { return LookupValue::find($id)?->code; }
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }
    private function fail(string $field, string $key, array $p = []): never { throw ValidationException::withMessages([$field => [__("payrun.err.$key", $p)]]); }
    private function status(PayRun $r): string { return $this->code($r->status_id) ?? ''; }

    private function expect(PayRun $run, string $action): void
    {
        if (!PayRunFlow::can($this->status($run), $action)) {
            $this->fail('status', 'cannot_transition', ['status' => $this->status($run), 'action' => $action]);
        }
    }

    private function segregate(PayRun $run, string $action, User $by): void
    {
        $people = ['prepared' => $run->prepared_by, 'calculated' => $run->calculated_by, 'reviewed' => $run->reviewed_by, 'finance' => $run->finance_by];
        if (PayRunFlow::conflict($action, $by->id, $people)) {
            $this->fail('status', 'segregation');
        }
    }

    private function editable(PayRun $run): void
    {
        if (!PayRunFlow::editable($this->status($run))) {
            $this->fail('status', 'run_not_editable');
        }
    }

    // ---- creating ------------------------------------------------------------------------------------------------------------------
    public function create(User $by, array $d): PayRun
    {
        if ($d['period_to'] < $d['period_from'] || (new \DateTimeImmutable($d['period_from']))->diff(new \DateTimeImmutable($d['period_to']))->days > 62) {
            $this->fail('period_to', 'bad_period');
        }
        $dup = PayRun::where('kind_id', Lookup::id('pay_run_kind', 'regular'))->whereDate('period_from', $d['period_from'])->whereDate('period_to', $d['period_to'])->where('status_id', '!=', $this->st('cancelled'))->exists();
        if ($dup) {
            $this->fail('period_from', 'duplicate_period');
        }
        $org = $this->org();
        $run = PayRun::create(['kind_id' => Lookup::id('pay_run_kind', 'regular'), 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'currency_id' => $d['currency_id'], 'status_id' => $this->st('draft'),
            'md_required' => HrSettings::get($org, 'payroll_md_approval_required') === 1, 'proration_mode' => HrSettings::get($org, 'payroll_proration_mode'), 'earnings_decimals' => HrSettings::get($org, 'payroll_earnings_decimals'),
            'prepared_by' => $by->id, 'note' => $d['note'] ?? null]);
        AuditLogger::log('payroll.run_created', $run, [], ['period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'proration_mode' => $run->proration_mode, 'md_required' => $run->md_required]);
        return $run;
    }

    /** A correction of a locked run: a new run for chosen employees, whose payslips record the difference against the originals. */
    public function createAdjustment(User $by, PayRun $parent, array $employeeIds, ?string $note): PayRun
    {
        if (!PayRunFlow::frozen($this->status($parent)) || $this->code($parent->kind_id) !== 'regular') {
            $this->fail('status', 'parent_not_locked');
        }
        $employeeIds = array_values(array_unique($employeeIds));
        $have = PayPayslip::where('run_id', $parent->id)->where('status_id', $this->ps('calculated'))->whereIn('employee_id', $employeeIds)->pluck('employee_id')->all();
        if ($employeeIds === [] || count($have) !== count($employeeIds)) {
            $this->fail('employee_ids', 'not_in_original');
        }
        return DB::transaction(function () use ($by, $parent, $employeeIds, $note) {
            $org = $this->org();
            $run = PayRun::create(['kind_id' => Lookup::id('pay_run_kind', 'adjustment'), 'period_from' => $parent->period_from->toDateString(), 'period_to' => $parent->period_to->toDateString(), 'currency_id' => $parent->currency_id,
                'status_id' => $this->st('draft'), 'parent_run_id' => $parent->id, 'md_required' => HrSettings::get($org, 'payroll_md_approval_required') === 1, 'proration_mode' => $parent->proration_mode,
                'earnings_decimals' => $parent->earnings_decimals, 'prepared_by' => $by->id, 'note' => $note]);
            foreach ($employeeIds as $eid) {
                PayRunMember::create(['run_id' => $run->id, 'employee_id' => $eid]);
            }
            AuditLogger::log('payroll.adjustment_created', $run, [], ['parent' => $parent->code, 'employees' => count($employeeIds)]);
            return $run;
        });
    }

    // ---- inputs and exclusions -----------------------------------------------------------------------------------------------------
    public function addInput(PayRun $run, User $by, array $d): PayRunInput
    {
        $this->editable($run);
        $c = PayComponent::with('source')->find($d['component_id']);
        if (!$c || !$c->is_active || $c->source->code !== 'variable') {
            $this->fail('component_id', 'input_not_variable');
        }
        $i = PayRunInput::create(['run_id' => $run->id, 'employee_id' => $d['employee_id'], 'component_id' => $c->id, 'amount' => $d['amount'], 'note' => $d['note'] ?? null, 'created_by' => $by->id]);
        AuditLogger::log('payroll.input_added', $i, [], ['run' => $run->code, 'employee_id' => $d['employee_id'], 'component' => $c->code]);     // the amount stays out of the audit trail
        return $i;
    }

    public function removeInput(PayRun $run, string $inputId): void
    {
        $this->editable($run);
        $i = PayRunInput::where('run_id', $run->id)->findOrFail($inputId);
        AuditLogger::log('payroll.input_removed', $i, [], ['run' => $run->code]);
        $i->delete();
    }

    public function exclude(PayRun $run, User $by, string $employeeId, string $reason): void
    {
        $this->editable($run);
        if (trim($reason) === '') {
            $this->fail('reason', 'reason_required');
        }
        PayRunExclusion::withoutGlobalScopes()->updateOrCreate(['run_id' => $run->id, 'employee_id' => $employeeId], ['organization_id' => $run->organization_id, 'reason' => $reason, 'created_by' => $by->id]);
        AuditLogger::log('payroll.employee_excluded', $run, [], ['employee_id' => $employeeId, 'reason' => $reason]);
    }

    public function include(PayRun $run, string $employeeId): void
    {
        $this->editable($run);
        PayRunExclusion::where('run_id', $run->id)->where('employee_id', $employeeId)->delete();
        AuditLogger::log('payroll.employee_included', $run, [], ['employee_id' => $employeeId]);
    }

    // ---- calculation ---------------------------------------------------------------------------------------------------------------------
    /** @return \Illuminate\Support\Collection<int,Employee> */
    private function population(PayRun $run)
    {
        $q = Employee::withoutGlobalScopes()->where('organization_id', $run->organization_id);
        if ($run->parent_run_id) {
            return $q->whereIn('id', PayRunMember::where('run_id', $run->id)->pluck('employee_id'))->get();
        }
        return $q->where(fn ($w) => $w->whereNull('hired_on')->orWhereDate('hired_on', '<=', $run->period_to))->where(fn ($w) => $w->whereNull('ended_on')->orWhereDate('ended_on', '>=', $run->period_from))->orderBy('last_name')->get();
    }

    /** Recalculates the whole run from the current verified configuration. Before review only. @return array{calculated:int,blocked:int,excluded:int} */
    public function calculate(PayRun $run, User $by): array
    {
        $this->expect($run, 'calculate');
        $from = $run->period_from->toDateString();
        $to = $run->period_to->toDateString();
        $holidays = PublicHoliday::withoutGlobalScopes()->where('organization_id', $run->organization_id)->whereDate('holiday_date', '>=', $from)->whereDate('holiday_date', '<=', $to)->get()
            ->mapWithKeys(fn ($h) => [$h->holiday_date->toDateString() => true])->all();
        $names = ['c' => PayComponent::withoutGlobalScopes()->where('organization_id', $run->organization_id)->pluck('name', 'code')->all(), 's' => PayScheme::withoutGlobalScopes()->where('organization_id', $run->organization_id)->pluck('name', 'code')->all()];
        $exclusions = PayRunExclusion::where('run_id', $run->id)->pluck('reason', 'employee_id')->all();
        $currency = $this->code($run->currency_id) ?? '';
        $count = ['calculated' => 0, 'blocked' => 0, 'excluded' => 0];

        DB::transaction(function () use ($run, $by, $from, $to, $holidays, $names, $exclusions, $currency, &$count) {
            PayPayslip::where('run_id', $run->id)->get()->each->delete();
            foreach ($this->population($run) as $e) {
                $p = Proration::factor($from, $to, $e->hired_on?->toDateString(), $e->ended_on?->toDateString(), $run->proration_mode, $holidays);
                if ($e->hired_on !== null && !$p['employed']) {
                    continue;                                                                     // not employed in this period: no payslip
                }
                $status = $this->payslipFor($run, $e, $p, $exclusions[$e->id] ?? null, $names, $currency, $holidays);
                $count[$status]++;
            }
            $run->update(['status_id' => $this->st('calculated'), 'calculated_by' => $by->id, 'calculated_at' => now()]);
            AuditLogger::log('payroll.run_calculated', $run, [], $count);
        });
        return $count;
    }

    private function flag(PayPayslip $slip, string $severity, string $code, array $params = []): void
    {
        PayPayslipFlag::create(['payslip_id' => $slip->id, 'severity_id' => Lookup::id('pay_flag_severity', $severity), 'code' => $code,
            'detail' => $params === [] ? null : implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($params), $params))]);
    }

    /** @return string calculated|blocked|excluded */
    private function payslipFor(PayRun $run, Employee $e, array $p, ?string $excluded, array $names, string $currency, array $holidays): string
    {
        $from = $run->period_from->toDateString();
        $to = $run->period_to->toDateString();
        $slip = PayPayslip::create(['run_id' => $run->id, 'employee_id' => $e->id, 'status_id' => $this->ps('calculated'), 'employee_name' => $e->full_name, 'employee_number' => $e->employee_number,
            'bank_account' => $e->bank_account, 'currency_code' => $currency, 'proration_factor' => $p['factor'], 'worked_days' => $p['worked'], 'period_days' => $p['total']]);
        if ($excluded !== null) {
            $slip->update(['status_id' => $this->ps('excluded'), 'excluded_reason' => $excluded]);
            return 'excluded';
        }
        $blockers = [];
        if ($e->hired_on === null) {
            $blockers[] = ['no_hire_date', []];
        }
        $extra = PayRunInput::where('run_id', $run->id)->where('employee_id', $e->id)->get()->map(fn ($i) => ['component_id' => $i->component_id, 'amount' => (string) $i->amount])->all();
        $cfg = $this->config->inputFor($e, $from, $to, $extra, ['factor' => $p['factor'], 'decimals' => $run->earnings_decimals]);
        foreach ($cfg['blockers'] as $b) {
            $blockers[] = [$b['code'], $b['params']];
        }
        if (empty($cfg['blockers']) && ($cfg['meta']['currency'] ?? null) !== null && $cfg['meta']['currency'] !== $currency) {
            $blockers[] = ['currency_mismatch', ['salary' => $cfg['meta']['currency'], 'run' => $currency]];
        }
        foreach ($cfg['warnings'] as $w) {
            $this->flag($slip, 'warning', $w['code'], $w['params']);
        }
        $partial = Proration::isPartial($from, $to, $e->hired_on?->toDateString(), $e->ended_on?->toDateString());
        if ($partial) {
            $run->proration_mode > 0 ? $this->flag($slip, 'info', 'prorated', ['worked' => $p['worked'], 'total' => $p['total']]) : $this->flag($slip, 'warning', 'partial_period_unprorated');
        }
        if ($blockers) {
            foreach ($blockers as [$code, $params]) {
                $this->flag($slip, 'blocker', $code, $params);
            }
            $slip->update(['status_id' => $this->ps('blocked')]);
            return 'blocked';
        }

        $r = PayrollEngine::calculate($cfg['input']);
        foreach ($r['warnings'] as $code) {
            $this->flag($slip, 'warning', $code);
        }
        foreach ($r['lines'] as $i => $l) {
            $kind = $l['kind'];
            $name = $kind === 'earning' || $kind === 'deduction' ? ($names['c'][$l['code']] ?? $l['code']) : ($names['s'][$l['code']] ?? $l['code']);
            $earn = $kind === 'earning' ? collect($cfg['input']['earnings'])->firstWhere('code', $l['code']) : null;
            $row = PayPayslipLine::create(['payslip_id' => $slip->id, 'kind_id' => Lookup::id('pay_line_kind', $kind), 'code' => $l['code'], 'name' => $name, 'source' => $earn['source'] ?? null,
                'base' => $l['base'] ?? null, 'rate' => $l['rate'] ?? null, 'amount' => $l['amount'], 'taxable' => $kind === 'earning' ? $l['taxable'] : null, 'pensionable' => $kind === 'earning' ? $l['pensionable'] : null,
                'version_id' => $l['version_id'] ?? null, 'base_kind' => $l['base_kind'] ?? null, 'rounding' => $l['rounding'] ?? null, 'decimals' => $l['decimals'] ?? null, 'statutory_type' => $l['type'] ?? null, 'sort_order' => $i]);
            foreach ($l['bands'] ?? [] as $j => $b) {
                PayPayslipBand::create(['line_id' => $row->id, 'lower' => $b['lower'], 'upper' => $b['upper'], 'rate' => $b['rate'], 'part' => $b['part'], 'amount' => $b['amount'], 'sort_order' => $j]);
            }
        }
        $t = $r['totals'];
        $upd = ['gross' => $t['gross'], 'taxable_earnings' => $t['taxable_earnings'], 'pensionable_earnings' => $t['pensionable_earnings'], 'employee_contributions' => $t['employee_contributions'], 'deductions' => $t['deductions'],
            'net' => $t['net'], 'employer_contributions' => $t['employer_contributions'], 'employer_cost' => $t['employer_cost'], 'category_code' => $cfg['meta']['category'] ?? null, 'employment_record_id' => $cfg['meta']['employment_record_id'] ?? null];
        if ($run->parent_run_id) {
            $orig = PayPayslip::where('run_id', $run->parent_run_id)->where('employee_id', $e->id)->first();
            if ($orig) {
                $upd += ['original_payslip_id' => $orig->id, 'net_difference' => bcsub($t['net'], (string) $orig->net, 2)];
            } else {
                $this->flag($slip, 'warning', 'no_original');
            }
        }
        $slip->update($upd);
        return 'calculated';
    }

    // ---- lifecycle -----------------------------------------------------------------------------------------------------------------------
    public function review(PayRun $run, User $by): PayRun
    {
        $this->expect($run, 'review');
        $this->segregate($run, 'review', $by);
        if (PayPayslip::where('run_id', $run->id)->where('status_id', $this->ps('blocked'))->exists()) {
            $this->fail('status', 'blocked_payslips');
        }
        if (!PayPayslip::where('run_id', $run->id)->where('status_id', $this->ps('calculated'))->exists()) {
            $this->fail('status', 'empty_run');
        }
        $run->update(['status_id' => $this->st('reviewed'), 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        AuditLogger::log('payroll.run_reviewed', $run);
        return $run->refresh();
    }

    public function approve(PayRun $run, User $by, string $step): PayRun
    {
        $this->expect($run, $step);
        $this->segregate($run, $step, $by);
        $r = PayRunFlow::afterApproval($step, (bool) $run->md_required, $run->finance_by !== null, $run->md_by !== null);
        if ($r['error']) {
            $this->fail('status', $r['error']);
        }
        $run->update($step === 'approve_md' ? ['md_by' => $by->id, 'md_at' => now(), 'status_id' => $this->st($r['status'])] : ['finance_by' => $by->id, 'finance_at' => now(), 'status_id' => $this->st($r['status'])]);
        AuditLogger::log('payroll.run_' . ($step === 'approve_md' ? 'approved_md' : 'approved_finance'), $run, [], ['status' => $r['status']]);
        return $run->refresh();
    }

    /** Back to draft with a reason: review and approvals are cleared and the run is recalculated. Never possible once locked. */
    public function returnToDraft(PayRun $run, User $by, string $reason): PayRun
    {
        $this->expect($run, 'return');
        if (trim($reason) === '') {
            $this->fail('reason', 'reason_required');
        }
        $run->update(['status_id' => $this->st('draft'), 'reviewed_by' => null, 'reviewed_at' => null, 'finance_by' => null, 'finance_at' => null, 'md_by' => null, 'md_at' => null, 'returned_reason' => $reason]);
        AuditLogger::log('payroll.run_returned', $run, [], ['by' => $by->id, 'reason' => $reason]);
        return $run->refresh();
    }

    public function lock(PayRun $run, User $by): PayRun
    {
        $this->expect($run, 'lock');
        $this->segregate($run, 'lock', $by);
        $run->update(['status_id' => $this->st('locked'), 'locked_by' => $by->id, 'locked_at' => now()]);
        AuditLogger::log('payroll.run_locked', $run);
        return $run->refresh();
    }

    public function markPaid(PayRun $run, User $by, string $paidOn, ?string $reference): PayRun
    {
        $this->expect($run, 'pay');
        $this->segregate($run, 'pay', $by);
        $run->update(['status_id' => $this->st('paid'), 'paid_on' => $paidOn, 'paid_by' => $by->id, 'payment_reference' => $reference]);
        AuditLogger::log('payroll.run_paid', $run, [], ['paid_on' => $paidOn]);
        return $run->refresh();
    }

    public function declare(PayRun $run, User $by, string $declaredOn, ?string $reference): PayRun
    {
        $this->expect($run, 'declare');
        $run->update(['status_id' => $this->st('declared'), 'declared_on' => $declaredOn, 'declared_by' => $by->id, 'declaration_reference' => $reference]);
        AuditLogger::log('payroll.run_declared', $run, [], ['declared_on' => $declaredOn]);
        return $run->refresh();
    }

    public function cancel(PayRun $run, User $by, string $reason): PayRun
    {
        $this->expect($run, 'cancel');
        if (trim($reason) === '') {
            $this->fail('reason', 'reason_required');
        }
        $run->update(['status_id' => $this->st('cancelled'), 'cancel_reason' => $reason]);
        AuditLogger::log('payroll.run_cancelled', $run, [], ['reason' => $reason]);
        return $run->refresh();
    }

    /** Sums of the calculated payslips of a run. */
    public function totals(PayRun $run): array
    {
        $q = PayPayslip::where('run_id', $run->id);
        $sum = fn (string $col) => bcadd((string) (clone $q)->where('status_id', $this->ps('calculated'))->sum($col), '0', 2);
        return ['gross' => $sum('gross'), 'employee_contributions' => $sum('employee_contributions'), 'deductions' => $sum('deductions'), 'net' => $sum('net'), 'employer_contributions' => $sum('employer_contributions'), 'employer_cost' => $sum('employer_cost'),
            'net_difference' => (clone $q)->whereNotNull('net_difference')->exists() ? bcadd((string) (clone $q)->sum('net_difference'), '0', 2) : null,
            'calculated' => (clone $q)->where('status_id', $this->ps('calculated'))->count(), 'blocked' => (clone $q)->where('status_id', $this->ps('blocked'))->count(), 'excluded' => (clone $q)->where('status_id', $this->ps('excluded'))->count()];
    }
}
