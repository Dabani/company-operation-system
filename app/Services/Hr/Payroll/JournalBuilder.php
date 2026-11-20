<?php

namespace App\Services\Hr\Payroll;

/**
 * The accounting payroll journal of one run (Guide 5.6). Pure. The ACCOUNTS are the accountant's configuration: a needed account that is not mapped is reported as missing, never guessed.
 *   Debit : each earning component (gross), each employer contribution (expense)
 *   Credit: each employee contribution (liability), each deduction (liability), each employer contribution (liability), net pay (payable)
 * The journal must balance to the cent, otherwise it is refused.
 *
 * $agg = ['earnings' => [code => amount], 'deductions' => [code => amount], 'schemes' => [code => ['employee' => amount, 'employer' => amount]], 'net' => amount]
 * $accounts = [key => account code]. Keys: component.{code}, scheme.{code}.employee_liability, scheme.{code}.employer_expense, scheme.{code}.employer_liability, net_pay.
 *
 * @return array{lines:array<int,array{account:string,key:string,label:string,debit:string,credit:string}>,missing:string[],balanced:bool,debit:string,credit:string}
 */
final class JournalBuilder
{
    public static function build(array $agg, array $accounts): array
    {
        $lines = [];
        $missing = [];
        $add = function (string $key, string $label, string $debit, string $credit) use (&$lines, &$missing, $accounts) {
            if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                return;
            }
            if (empty($accounts[$key])) {
                $missing[$key] = true;
            }
            $lines[] = ['account' => (string) ($accounts[$key] ?? ''), 'key' => $key, 'label' => $label, 'debit' => $debit, 'credit' => $credit];
        };
        $z = '0.00';
        foreach ($agg['earnings'] ?? [] as $code => $amt) {
            $add("component.$code", $code, bcadd((string) $amt, '0', 2), $z);
        }
        foreach ($agg['schemes'] ?? [] as $code => $s) {
            $add("scheme.$code.employer_expense", "$code (employer)", bcadd((string) ($s['employer'] ?? '0'), '0', 2), $z);
        }
        foreach ($agg['schemes'] ?? [] as $code => $s) {
            $add("scheme.$code.employee_liability", "$code (employee)", $z, bcadd((string) ($s['employee'] ?? '0'), '0', 2));
            $add("scheme.$code.employer_liability", "$code (employer)", $z, bcadd((string) ($s['employer'] ?? '0'), '0', 2));
        }
        foreach ($agg['deductions'] ?? [] as $code => $amt) {
            $add("component.$code", $code, $z, bcadd((string) $amt, '0', 2));
        }
        $add('net_pay', 'net pay', $z, bcadd((string) ($agg['net'] ?? '0'), '0', 2));
        $debit = $credit = '0';
        foreach ($lines as $l) {
            $debit = bcadd($debit, $l['debit'], 2);
            $credit = bcadd($credit, $l['credit'], 2);
        }
        return ['lines' => $lines, 'missing' => array_keys($missing), 'balanced' => bccomp($debit, $credit, 2) === 0, 'debit' => $debit, 'credit' => $credit];
    }
}
