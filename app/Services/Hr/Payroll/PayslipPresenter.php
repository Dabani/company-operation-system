<?php

namespace App\Services\Hr\Payroll;

use App\Models\PayPayslip;
use Illuminate\Support\Facades\Lang;

/** One shape of a payslip for HR screens, employee self-service and the PDF. Everything comes from the stored payslip (never recomputed), so it reads the same years later. */
final class PayslipPresenter
{
    public static function detail(PayPayslip $p, ?string $locale = null): array
    {
        $p->loadMissing(['run.status', 'run.kind', 'status', 'lines.kind', 'lines.bands', 'flags.severity']);
        $line = fn ($l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'source' => $l->source, 'base' => $l->base === null ? null : (string) $l->base, 'rate' => $l->rate === null ? null : (string) $l->rate,
            'amount' => (string) $l->amount, 'taxable' => $l->taxable, 'pensionable' => $l->pensionable, 'statutory_type' => $l->statutory_type, 'version_id' => $l->version_id,
            'bands' => $l->bands->map(fn ($b) => ['lower' => (string) $b->lower, 'upper' => $b->upper === null ? null : (string) $b->upper, 'rate' => (string) $b->rate, 'part' => (string) $b->part, 'amount' => (string) $b->amount])->values()->all()];
        $by = fn (string $k) => $p->lines->filter(fn ($l) => $l->kind->code === $k)->map($line)->values()->all();
        return ['id' => $p->id, 'status' => $p->status->code, 'run' => ['id' => $p->run->id, 'code' => $p->run->code, 'kind' => $p->run->kind->code, 'status' => $p->run->status->code,
                'period_from' => $p->run->period_from->toDateString(), 'period_to' => $p->run->period_to->toDateString(), 'paid_on' => $p->run->paid_on?->toDateString()],
            'employee' => ['id' => $p->employee_id, 'name' => $p->employee_name, 'number' => $p->employee_number], 'currency' => $p->currency_code, 'category' => $p->category_code,
            'proration' => ['factor' => $p->proration_factor === null ? null : (string) $p->proration_factor, 'worked_days' => $p->worked_days, 'period_days' => $p->period_days],
            'totals' => ['gross' => (string) $p->gross, 'taxable_earnings' => (string) $p->taxable_earnings, 'pensionable_earnings' => (string) $p->pensionable_earnings, 'employee_contributions' => (string) $p->employee_contributions,
                'deductions' => (string) $p->deductions, 'net' => (string) $p->net, 'employer_contributions' => (string) $p->employer_contributions, 'employer_cost' => (string) $p->employer_cost],
            'net_difference' => $p->net_difference === null ? null : (string) $p->net_difference, 'excluded_reason' => $p->excluded_reason,
            'earnings' => $by('earning'), 'contributions' => $by('scheme_employee'), 'deductions' => $by('deduction'), 'employer' => $by('scheme_employer'),
            'flags' => $p->flags->map(fn ($f) => ['severity' => $f->severity->code, 'code' => $f->code, 'message' => self::message($f->code, $f->detail, $locale)])->values()->all()];
    }

    /** The detail text is "key=value; key=value": turn it back into the translation parameters. */
    public static function message(string $code, ?string $detail, ?string $locale = null): string
    {
        $params = [];
        foreach (array_filter(array_map('trim', explode(';', (string) $detail))) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $params[$k] = $v;
        }
        $key = Lang::has("payroll.check.$code", $locale) ? "payroll.check.$code" : "payrun.flag.$code";
        return __($key, $params, $locale);
    }
}
