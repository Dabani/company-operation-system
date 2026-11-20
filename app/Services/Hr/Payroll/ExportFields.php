<?php

namespace App\Services\Hr\Payroll;

/** The closed catalogue of values an export column can take, per kind of export. A layout can only name a field from here, so a typo can never export the wrong data. Pure. */
final class ExportFields
{
    public const COMMON = ['constant', 'row_number'];
    public const RUN = ['run_code', 'period_from', 'period_to', 'paid_on', 'currency'];
    public const PAYSLIP = ['employee_number', 'employee_name', 'employee_national_id', 'bank_account', 'category_code', 'gross', 'taxable_earnings', 'pensionable_earnings',
        'employee_contributions', 'deductions', 'net', 'employer_contributions', 'employer_cost', 'pay_amount'];
    public const SCHEME = ['scheme_base', 'scheme_employee_amount', 'scheme_employer_amount'];      // need scheme_id
    public const COMPONENT = ['component_amount'];                                                    // needs component_id
    public const JOURNAL = ['journal_account', 'journal_label', 'journal_debit', 'journal_credit'];

    /** @return string[] */
    public static function allowed(string $kind): array
    {
        return match ($kind) {
            'bank_transfer' => array_merge(self::COMMON, self::RUN, self::PAYSLIP),
            'declaration' => array_merge(self::COMMON, self::RUN, self::PAYSLIP, self::SCHEME, self::COMPONENT),
            'journal' => array_merge(self::COMMON, self::RUN, self::JOURNAL),
            default => [],
        };
    }

    public static function needsScheme(string $source): bool { return in_array($source, self::SCHEME, true); }
    public static function needsComponent(string $source): bool { return in_array($source, self::COMPONENT, true); }

    /** Which run statuses a kind of export may be generated in: money files only after the run is locked, declarations once paid. */
    public static function runStatuses(string $kind): array
    {
        return match ($kind) {
            'bank_transfer' => ['locked', 'paid'],
            'declaration' => ['paid', 'declared'],
            'journal' => ['locked', 'paid', 'declared'],
            default => [],
        };
    }
}
