<?php

namespace App\Services\Hr\Payroll;

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PayEmployeeItem;
use App\Models\PayScheme;
use App\Models\PaySchemeAssignment;
use App\Services\Hr\EmploymentService;
use App\Services\Hr\Payroll\Proration;

/**
 * Turns "this employee, this period" into the input of PayrollEngine, using ONLY verified configuration. Nothing is guessed:
 *  - an unverified rate, assignment or recurring item is skipped and reported (never applied);
 *  - a scheme with no assignment for the employee's category does not apply, and the result says so;
 *  - a missing salary or basic-salary component is a blocker.
 * Salary is the monthly basic of the employment record in force on the last day of the period (the unit and any proration are decisions listed in docs/PHASE-6A-PAYROLL.md).
 */
class PayrollConfigResolver
{
    /**
     * $opts (payroll runs): 'factor' = proration factor applied to basic and recurring earnings only (variable lines and deductions are never prorated), 'decimals' = decimals of a prorated earning.
     * Without $opts (the dry-run of a single employee) nothing is prorated and a part-period employee gets the `partial_period` warning.
     * @return array{blockers:array,warnings:array,input:?array,meta:array}
     */
    public function inputFor(Employee $e, string $from, string $to, array $extraLines = [], array $opts = []): array
    {
        $org = $e->organization_id;
        $blockers = [];
        $warnings = [];
        $w = function (string $code, array $p = []) use (&$warnings) {
            $warnings[] = ['code' => $code, 'params' => $p];
        };
        $b = function (string $code, array $p = []) use (&$blockers) {
            $blockers[] = ['code' => $code, 'params' => $p];
        };

        $rec = EmploymentService::asOfRecord($e->id, $to);
        $salary = $rec?->salary_amount === null ? null : (string) $rec->salary_amount;
        if ($rec === null || $salary === null || $salary === '') {
            $b('no_salary');
        } elseif (!is_numeric($salary) || bccomp($salary, '0', 2) <= 0) {
            $b('bad_salary');
        }
        $rec?->loadMissing('category');
        $categoryId = $rec?->category_id ?? $e->category_id;
        $categoryCode = $rec?->category?->code;

        $basics = PayComponent::withoutGlobalScopes()->with(['kind', 'source'])->where('organization_id', $org)->where('is_active', true)->whereHas('source', fn($q) => $q->where('code', 'basic_salary'))->get();
        if ($basics->count() === 0) {
            $b('no_basic_component');
        } elseif ($basics->count() > 1) {
            $b('several_basic_components');
        }

        $earnings = [];
        $deductions = [];
        if ($basics->count() === 1 && $salary !== null && is_numeric($salary) && bccomp($salary, '0', 2) > 0) {
            $c = $basics->first();
            $earnings[] = ['code' => $c->code, 'amount' => bcadd($salary, '0', 2), 'taxable' => (bool) $c->is_taxable, 'pensionable' => (bool) $c->is_pensionable, 'component_id' => $c->id, 'source' => 'basic_salary'];
        }

        $items = PayEmployeeItem::withoutGlobalScopes()->with(['component.kind', 'verification'])->where('organization_id', $org)->where('employee_id', $e->id)
            ->whereDate('valid_from', '<=', $to)->where(fn($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))->get();
        foreach ($items as $i) {
            if ($i->verification?->code !== 'verified') {
                $w('unverified_item', ['code' => $i->component->code]);
                continue;
            }
            if (!$i->component->is_active) {
                $w('inactive_component', ['code' => $i->component->code]);
                continue;
            }
            $line = ['code' => $i->component->code, 'amount' => bcadd((string) $i->amount, '0', 2)];
            if ($i->component->kind->code === 'deduction') {
                $deductions[] = $line;
            } else {
                $earnings[] = $line + ['taxable' => (bool) $i->component->is_taxable, 'pensionable' => (bool) $i->component->is_pensionable, 'component_id' => $i->component_id, 'source' => 'recurring'];
            }
        }

        foreach ($extraLines as $x) {
            $c = PayComponent::withoutGlobalScopes()->with(['kind', 'source'])->where('organization_id', $org)->find($x['component_id'] ?? '');
            if (!$c || !$c->is_active || $c->source->code !== 'variable' || !isset($x['amount']) || !is_numeric($x['amount']) || bccomp((string) $x['amount'], '0', 2) < 0) {
                $b('bad_extra_line');
                continue;
            }
            $a = bcadd((string) $x['amount'], '0', 2);
            if ($c->kind->code === 'deduction') {
                $deductions[] = ['code' => $c->code, 'amount' => $a];
            } else {
                $earnings[] = ['code' => $c->code, 'amount' => $a, 'taxable' => (bool) $c->is_taxable, 'pensionable' => (bool) $c->is_pensionable, 'component_id' => $c->id, 'source' => 'variable'];
            }
        }

        if (isset($opts['factor']) && bccomp((string) $opts['factor'], '1', 6) < 0) {
            $dec = (int) ($opts['decimals'] ?? 2);
            foreach ($earnings as &$line) {
                if (in_array($line['source'], ['basic_salary', 'recurring'], true)) {
                    $line['amount'] = bcadd(PayRound::apply(bcmul($line['amount'], (string) $opts['factor'], 8), PayRound::NEAREST, $dec), '0', 2);
                }
            }
            unset($line);
        }

        $schemesIn = [];
        $applied = [];
        $excluded = [];
        foreach (PayScheme::withoutGlobalScopes()->with(['statutoryType', 'mode', 'baseKind', 'rounding'])->where('organization_id', $org)->where('is_active', true)->orderBy('calc_order')->get() as $s) {
            $versions = $s->versions()->withoutGlobalScopes()->with(['verification', 'brackets'])->whereDate('valid_from', '<=', $to)->where(fn($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $to))->orderByDesc('valid_from')->get();
            $v = $versions->first(fn($x) => $x->verification?->code === 'verified');
            if (!$v) {
                $versions->isEmpty() ? $w('no_version', ['code' => $s->code]) : $w('unverified_version', ['code' => $s->code]);
                continue;
            }
            $assign = $this->assignment($s->id, $e->id, $categoryId, $to);
            if ($assign['state'] === 'none') {
                $w('no_assignment', ['code' => $s->code, 'category' => $categoryCode]);
                continue;
            }
            if ($assign['state'] === 'unverified') {
                $w('unverified_assignment', ['code' => $s->code]);
                continue;
            }
            if (!$assign['applies']) {
                $excluded[] = ['code' => $s->code, 'by' => $assign['level']];
                continue;
            }
            $mode = $s->mode->code === 'brackets' ? 'brackets' : 'flat';
            $schemesIn[] = [
                'code' => $s->code,
                'type' => $s->statutoryType->code,
                'order' => $s->calc_order,
                'mode' => $mode,
                'base_kind' => $s->baseKind->code,
                'employee_rate' => $v->employee_rate === null ? '0' : (string) $v->employee_rate,
                'employer_rate' => $v->employer_rate === null ? '0' : (string) $v->employer_rate,
                'base_cap' => $v->base_cap === null ? null : (string) $v->base_cap,
                'reduces_taxable_base' => (bool) $s->reduces_taxable_base,
                'rounding' => $s->rounding->code,
                'decimals' => (int) $s->decimals,
                'round_each_band' => (bool) $s->round_each_band,
                'version_id' => $v->id,
                'brackets' => $mode === 'brackets' ? $v->brackets->sortBy('lower')->map(fn($x) => ['lower' => (string) $x->lower, 'upper' => $x->upper === null ? null : (string) $x->upper, 'rate' => (string) $x->rate])->values()->all() : []
            ];
            $applied[] = ['code' => $s->code, 'version_id' => $v->id, 'assignment' => $assign['level']];
        }

        if (!isset($opts['factor']) && Proration::isPartial($from, $to, $e->hired_on?->toDateString(), $e->ended_on?->toDateString())) {
            $w('partial_period');
        }

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'input' => $blockers ? null : ['earnings' => $earnings, 'deductions' => $deductions, 'schemes' => $schemesIn],
            'meta' => ['period_from' => $from, 'period_to' => $to, 'currency' => $rec?->salary_currency, 'category' => $categoryCode, 'applied' => $applied, 'excluded' => $excluded, 'employment_record_id' => $rec?->id]
        ];
    }

    /** An employee-level verified row beats a category-level verified row. Unverified rows are never used. @return array{state:string,applies?:bool,level?:string} */
    private function assignment(string $schemeId, string $employeeId, ?string $categoryId, string $on): array
    {
        $rows = PaySchemeAssignment::withoutGlobalScopes()->with('verification')->where('scheme_id', $schemeId)->whereDate('valid_from', '<=', $on)
            ->where(fn($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on))
            ->where(fn($q) => $q->where('employee_id', $employeeId)->when($categoryId, fn($w) => $w->orWhere('category_id', $categoryId)))->orderByDesc('valid_from')->get();
        $verified = $rows->filter(fn($r) => $r->verification?->code === 'verified');
        $pick = $verified->first(fn($r) => $r->employee_id === $employeeId) ?? $verified->first(fn($r) => $r->category_id !== null && $r->employee_id === null);
        if ($pick) {
            return ['state' => 'verified', 'applies' => (bool) $pick->applies, 'level' => $pick->employee_id ? 'employee' : 'category'];
        }
        return ['state' => $rows->isEmpty() ? 'none' : 'unverified'];
    }
}
