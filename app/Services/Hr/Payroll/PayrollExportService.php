<?php

namespace App\Services\Hr\Payroll;

use App\Models\Employee;
use App\Models\LookupValue;
use App\Models\PayExport;
use App\Models\PayExportProfile;
use App\Models\PayJournalAccount;
use App\Models\PayPayslip;
use App\Models\PayRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Lookup;
use Illuminate\Validation\ValidationException;

/**
 * Payroll exports (Guide 5.6): bank-transfer file, declaration files, accounting journal, all from the STORED payslips of a locked / paid run and from a layout the accountant
 * defined, had a second person verify, and recorded who agreed it with. Nothing about a bank or statutory format is built in. Money files are never produced for an unlocked run.
 */
class PayrollExportService
{
    private function fail(string $field, string $key, array $p = []): never { throw ValidationException::withMessages([$field => [__("payexport.err.$key", $p)]]); }
    private function code(?string $id): ?string { return $id ? LookupValue::find($id)?->code : null; }

    /** The layout in the shape ExportFormatter expects. Scheme / component columns read the composite key "source:code". */
    public function profileArray(PayExportProfile $p): array
    {
        $p->loadMissing(['layout', 'columns.scheme', 'columns.component']);
        return ['layout' => $p->layout->code, 'delimiter' => $p->delimiter ?? ';', 'quote' => $p->quote_char ?? '', 'line_ending' => $p->line_ending, 'encoding' => $p->encoding, 'decimal_separator' => $p->decimal_separator,
            'date_format' => $p->date_format, 'include_header' => $p->include_header, 'header_template' => $p->header_template, 'trailer_template' => $p->trailer_template,
            'columns' => $p->columns->map(fn ($c) => ['header' => $c->header, 'source' => ExportFields::needsScheme($c->source) ? $c->source . ':' . $c->scheme->code : (ExportFields::needsComponent($c->source) ? $c->source . ':' . $c->component->code : $c->source),
                'format' => $c->format, 'decimals' => $c->decimals ?? 2, 'width' => $c->width, 'align' => $c->align ?? 'left', 'pad_char' => $c->pad_char ?? ' ', 'truncate' => $c->truncate, 'constant' => $c->constant])->all()];
    }

    private function runVars(PayRun $run): array
    {
        return ['run_code' => $run->code, 'period_from' => $run->period_from->toDateString(), 'period_to' => $run->period_to->toDateString(), 'paid_on' => $run->paid_on?->toDateString() ?? '',
            'currency' => $this->code($run->currency_id) ?? '', 'generated_on' => now()->toDateString()];
    }

    /** @return array{rows:array,blockers:array,total:?string} */
    private function payslipRows(PayRun $run, PayExportProfile $p, array $profile, bool $bank): array
    {
        $isAdj = $run->parent_run_id !== null;
        $slips = PayPayslip::with('lines.kind')->where('run_id', $run->id)->where('status_id', Lookup::id('pay_payslip_status', 'calculated'))->orderBy('employee_name')->get();
        $needsNid = collect($profile['columns'])->contains(fn ($c) => $c['source'] === 'employee_national_id');
        $nid = $needsNid ? Employee::withoutGlobalScopes()->whereIn('id', $slips->pluck('employee_id'))->get()->mapWithKeys(fn ($e) => [$e->id => (string) $e->national_id])->all() : [];   // the employee master at export time
        $vars = $this->runVars($run);
        $rows = [];
        $blockers = [];
        $total = '0.00';
        foreach ($slips as $s) {
            $pay = $isAdj ? (string) ($s->net_difference ?? '0.00') : (string) $s->net;
            if ($bank && bccomp($pay, '0', 2) <= 0) {
                continue;                                                                   // nothing to transfer (zero, or an adjustment that recovers money: handled outside the bank file)
            }
            if ($bank && trim((string) $s->bank_account) === '') {
                $blockers[] = $s->employee_name;
                continue;
            }
            $row = $vars + ['employee_number' => (string) $s->employee_number, 'employee_name' => $s->employee_name, 'employee_national_id' => $nid[$s->employee_id] ?? '', 'bank_account' => (string) $s->bank_account,
                'category_code' => (string) $s->category_code, 'gross' => (string) $s->gross, 'taxable_earnings' => (string) $s->taxable_earnings, 'pensionable_earnings' => (string) $s->pensionable_earnings,
                'employee_contributions' => (string) $s->employee_contributions, 'deductions' => (string) $s->deductions, 'net' => (string) $s->net, 'employer_contributions' => (string) $s->employer_contributions,
                'employer_cost' => (string) $s->employer_cost, 'pay_amount' => $pay, 'currency' => $s->currency_code];
            foreach ($profile['columns'] as $c) {
                if (!str_contains($c['source'], ':')) {
                    continue;
                }
                [$src, $code] = explode(':', $c['source'], 2);
                $row[$c['source']] = match ($src) {
                    'scheme_base' => (string) ($s->lines->first(fn ($l) => $l->kind->code === 'scheme_employee' && $l->code === $code)?->base ?? '0.00'),
                    'scheme_employee_amount' => (string) ($s->lines->first(fn ($l) => $l->kind->code === 'scheme_employee' && $l->code === $code)?->amount ?? '0.00'),
                    'scheme_employer_amount' => (string) ($s->lines->first(fn ($l) => $l->kind->code === 'scheme_employer' && $l->code === $code)?->amount ?? '0.00'),
                    default => (string) ($s->lines->first(fn ($l) => in_array($l->kind->code, ['earning', 'deduction'], true) && $l->code === $code)?->amount ?? '0.00'),
                };
            }
            $rows[] = $row;
            $total = bcadd($total, $pay, 2);
        }
        return ['rows' => $rows, 'blockers' => $blockers, 'total' => $bank ? $total : null];
    }

    /** Aggregates of the calculated payslips of a run, as JournalBuilder expects. */
    public function journalAggregate(PayRun $run): array
    {
        $agg = ['earnings' => [], 'deductions' => [], 'schemes' => [], 'net' => '0.00'];
        foreach (PayPayslip::with('lines.kind')->where('run_id', $run->id)->where('status_id', Lookup::id('pay_payslip_status', 'calculated'))->get() as $s) {
            $agg['net'] = bcadd($agg['net'], (string) $s->net, 2);
            foreach ($s->lines as $l) {
                $k = $l->kind->code;
                if ($k === 'earning') {
                    $agg['earnings'][$l->code] = bcadd($agg['earnings'][$l->code] ?? '0', (string) $l->amount, 2);
                } elseif ($k === 'deduction') {
                    $agg['deductions'][$l->code] = bcadd($agg['deductions'][$l->code] ?? '0', (string) $l->amount, 2);
                } elseif ($k === 'scheme_employee' || $k === 'scheme_employer') {
                    $f = $k === 'scheme_employee' ? 'employee' : 'employer';
                    $agg['schemes'][$l->code][$f] = bcadd($agg['schemes'][$l->code][$f] ?? '0', (string) $l->amount, 2);
                }
            }
        }
        foreach ($agg['schemes'] as $c => $v) {
            $agg['schemes'][$c] += ['employee' => '0.00', 'employer' => '0.00'];
        }
        return $agg;
    }

    private function accounts(): array { return PayJournalAccount::pluck('account_code', 'account_key')->all(); }

    /** Every journal account key this run needs, with its current mapping. @return array<int,array{key:string,account_code:?string}> */
    public function requiredAccounts(PayRun $run): array
    {
        $r = JournalBuilder::build($this->journalAggregate($run), $this->accounts());
        return array_map(fn ($l) => ['key' => $l['key'], 'account_code' => $l['account'] === '' ? null : $l['account']], $r['lines']);
    }

    public function generate(PayRun $run, PayExportProfile $p, User $by): PayExport
    {
        $kind = $this->code($p->kind_id);
        $status = $this->code($run->status_id);
        if ($p->verification_status_id !== Lookup::id('rule_verification_status', 'verified') || !$p->is_active) {
            $this->fail('profile_id', 'profile_not_verified');
        }
        if (!in_array($status, ExportFields::runStatuses($kind), true)) {
            $this->fail('status', 'run_state', ['kind' => $kind, 'allowed' => implode(', ', ExportFields::runStatuses($kind))]);
        }
        if ($run->parent_run_id && $kind !== 'bank_transfer') {
            $this->fail('status', 'adjustment_unsupported');
        }
        if ($kind === 'bank_transfer' && PayRunFlow::conflict('pay', $by->id, ['prepared' => $run->prepared_by, 'calculated' => $run->calculated_by])) {
            $this->fail('status', 'segregation');
        }
        $profile = $this->profileArray($p);
        $vars = $this->runVars($run);
        if ($kind === 'journal') {
            $j = JournalBuilder::build($this->journalAggregate($run), $this->accounts());
            if ($j['missing']) {
                $this->fail('accounts', 'accounts_missing', ['keys' => implode(', ', $j['missing'])]);
            }
            if (!$j['balanced']) {
                $this->fail('accounts', 'journal_unbalanced');
            }
            $rows = array_map(fn ($l) => $vars + ['journal_account' => $l['account'], 'journal_label' => $l['label'], 'journal_debit' => $l['debit'], 'journal_credit' => $l['credit']], $j['lines']);
            $total = $j['debit'];
        } else {
            $b = $this->payslipRows($run, $p, $profile, $kind === 'bank_transfer');
            if ($b['blockers']) {
                $this->fail('bank_account', 'bank_account_missing', ['names' => implode(', ', array_slice($b['blockers'], 0, 20))]);
            }
            $rows = $b['rows'];
            $total = $b['total'];
        }
        if ($rows === []) {
            $this->fail('status', 'no_rows');
        }
        $content = $this->render($profile, $rows, $vars + ['total' => $total ?? '']);
        $name = $this->fileName($p, $vars + ['kind' => $kind]);
        $e = PayExport::create(['run_id' => $run->id, 'profile_id' => $p->id, 'kind_code' => $kind, 'profile_name' => $p->name, 'file_name' => $name, 'mime' => $p->file_extension === 'txt' ? 'text/plain' : 'text/csv',
            'row_count' => count($rows), 'total_amount' => $total, 'checksum' => hash('sha256', $content), 'content' => $content, 'generated_by' => $by->id]);
        AuditLogger::log('payroll.export_generated', $e, [], ['run' => $run->code, 'kind' => $kind, 'rows' => count($rows), 'checksum' => $e->checksum]);      // never the content
        return $e;
    }

    /** Layout errors become validation messages: nothing is truncated or altered silently. */
    public function render(array $profile, array $rows, array $vars): string
    {
        try {
            return ExportFormatter::render($profile, $rows, $vars);
        } catch (ExportFormatError $e) {
            throw ValidationException::withMessages(['layout' => [__("payexport.format.{$e->reason}", ['detail' => $e->detail])]]);
        }
    }

    private function fileName(PayExportProfile $p, array $vars): string
    {
        $base = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $this->fail('filename_template', 'filename_placeholder', ['name' => $m[1]]), $p->filename_template);
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) . '.' . ltrim($p->file_extension, '.');
    }

    /** Synthetic rows (invented names and numbers, never real data) so the accountant can check a layout with the bank before it is verified. */
    public function sampleRows(PayExportProfile $p): array
    {
        $profile = $this->profileArray($p);
        $kind = $this->code($p->kind_id);
        $vars = ['run_code' => 'PAY-SAMPLE', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'paid_on' => '2026-02-01', 'currency' => 'XXX', 'generated_on' => '2026-02-01'];
        $rows = [];
        if ($kind === 'journal') {
            foreach ([['600100', 'basic', '500000.00', '0.00'], ['420000', 'net pay', '0.00', '500000.00']] as [$a, $l, $d, $c]) {
                $rows[] = $vars + ['journal_account' => $a, 'journal_label' => $l, 'journal_debit' => $d, 'journal_credit' => $c];
            }
            return ['rows' => $rows, 'vars' => $vars + ['total' => '500000.00'], 'profile' => $profile];
        }
        foreach ([1, 2, 3] as $i) {
            $row = $vars + ['employee_number' => 'S00' . $i, 'employee_name' => 'SAMPLE EMPLOYEE ' . $i, 'employee_national_id' => '10000000000000' . $i, 'bank_account' => '000000000000' . $i, 'category_code' => 'sample',
                'gross' => '500000.00', 'taxable_earnings' => '500000.00', 'pensionable_earnings' => '500000.00', 'employee_contributions' => '25000.00', 'deductions' => '0.00', 'net' => '475000.00',
                'employer_contributions' => '25000.00', 'employer_cost' => '525000.00', 'pay_amount' => '475000.00'];
            foreach ($profile['columns'] as $c) {
                if (str_contains($c['source'], ':')) {
                    $row[$c['source']] = str_starts_with($c['source'], 'scheme_base') ? '500000.00' : '25000.00';
                }
            }
            $rows[] = $row;
        }
        return ['rows' => $rows, 'vars' => $vars + ['total' => '1425000.00'], 'profile' => $profile];
    }
}
