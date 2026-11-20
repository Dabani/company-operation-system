<?php

namespace App\Services\Hr\Payroll;

/**
 * The payroll calculation (Guide 5.6). PURE: no database, no clock, no built-in rate, threshold or exemption. Every number arrives as an argument and every
 * result line carries the inputs it was computed from, so a payslip can be reproduced years later from what was stored.
 *
 * in = [
 *   'earnings'   => [ ['code','amount','taxable' => bool,'pensionable' => bool], ... ],       // basic, recurring and variable earnings of the period
 *   'deductions' => [ ['code','amount'], ... ],                                                // after-tax deductions (loan, advance recovery...), reduce net pay only
 *   'schemes'    => [ ['code','type','order','mode' => flat|brackets,'base_kind','employee_rate','employer_rate','base_cap','reduces_taxable_base' => bool,
 *                      'rounding','decimals','round_each_band' => bool,'brackets' => [['lower','upper','rate'],...],'version_id'], ... ],
 * ]
 * Rates are percentages ("6.5" = 6.5 %). Bases: gross_earnings | taxable_earnings | pensionable_earnings | taxable_after_deductions
 * (taxable earnings minus the employee contributions of the earlier schemes flagged reduces_taxable_base).
 */
final class PayrollEngine
{
    private const S = 8;   // working scale

    /** @return array{lines:array<int,array>,totals:array<string,string>,warnings:array<int,string>} */
    public static function calculate(array $in): array
    {
        $gross = $taxable = $pensionable = '0';
        $lines = [];
        foreach ($in['earnings'] ?? [] as $e) {
            $a = bcadd((string) $e['amount'], '0', 2);
            $gross = bcadd($gross, $a, self::S);
            $taxable = !empty($e['taxable']) ? bcadd($taxable, $a, self::S) : $taxable;
            $pensionable = !empty($e['pensionable']) ? bcadd($pensionable, $a, self::S) : $pensionable;
            $lines[] = ['kind' => 'earning', 'code' => $e['code'], 'base' => null, 'rate' => null, 'amount' => $a, 'taxable' => !empty($e['taxable']), 'pensionable' => !empty($e['pensionable'])];
        }

        $schemes = $in['schemes'] ?? [];
        usort($schemes, fn ($a, $b) => [(int) $a['order'], $a['code']] <=> [(int) $b['order'], $b['code']]);
        $deductible = '0';        // employee contributions so far that reduce the taxable base
        $employee = '0';
        $employer = '0';
        foreach ($schemes as $s) {
            $raw = match ($s['base_kind']) {
                'gross_earnings' => $gross,
                'taxable_earnings' => $taxable,
                'pensionable_earnings' => $pensionable,
                'taxable_after_deductions' => bcsub($taxable, $deductible, self::S),
            };
            $base = bccomp($raw, '0', self::S) < 0 ? '0' : $raw;
            if (!empty($s['base_cap']) && bccomp($base, (string) $s['base_cap'], self::S) > 0) {
                $base = (string) $s['base_cap'];
            }
            $dec = (int) ($s['decimals'] ?? 0);
            $mode = $s['rounding'] ?? PayRound::NEAREST;
            $bands = [];
            if (($s['mode'] ?? 'flat') === 'brackets') {
                $emp = self::bracketTax($base, $s['brackets'] ?? [], $mode, $dec, !empty($s['round_each_band']), $bands);
                $rate = null;
            } else {
                $rate = (string) ($s['employee_rate'] ?? '0');
                $emp = PayRound::apply(bcdiv(bcmul($base, $rate, self::S), '100', self::S), $mode, $dec);
            }
            $erate = (string) ($s['employer_rate'] ?? '0');
            $er = bccomp($erate, '0', self::S) === 0 ? PayRound::apply('0', $mode, $dec) : PayRound::apply(bcdiv(bcmul($base, $erate, self::S), '100', self::S), $mode, $dec);
            $employee = bcadd($employee, $emp, self::S);
            $employer = bcadd($employer, $er, self::S);
            if (!empty($s['reduces_taxable_base'])) {
                $deductible = bcadd($deductible, $emp, self::S);
            }
            $lines[] = ['kind' => 'scheme_employee', 'code' => $s['code'], 'type' => $s['type'] ?? null, 'base' => bcadd($base, '0', $dec ?: 2), 'rate' => $rate, 'amount' => $emp, 'bands' => $bands,
                'version_id' => $s['version_id'] ?? null, 'base_kind' => $s['base_kind'], 'rounding' => $mode, 'decimals' => $dec];
            if (bccomp($erate, '0', self::S) !== 0) {
                $lines[] = ['kind' => 'scheme_employer', 'code' => $s['code'], 'type' => $s['type'] ?? null, 'base' => bcadd($base, '0', $dec ?: 2), 'rate' => $erate, 'amount' => $er, 'version_id' => $s['version_id'] ?? null, 'rounding' => $mode, 'decimals' => $dec];
            }
        }

        $ded = '0';
        foreach ($in['deductions'] ?? [] as $d) {
            $a = bcadd((string) $d['amount'], '0', 2);
            $ded = bcadd($ded, $a, self::S);
            $lines[] = ['kind' => 'deduction', 'code' => $d['code'], 'base' => null, 'rate' => null, 'amount' => $a];
        }

        $net = bcsub(bcsub($gross, $employee, self::S), $ded, self::S);
        $totals = ['gross' => self::fix($gross), 'taxable_earnings' => self::fix($taxable), 'pensionable_earnings' => self::fix($pensionable), 'employee_contributions' => self::fix($employee),
            'deductions' => self::fix($ded), 'net' => self::fix($net), 'employer_contributions' => self::fix($employer), 'employer_cost' => self::fix(bcadd($gross, $employer, self::S))];
        return ['lines' => $lines, 'totals' => $totals, 'warnings' => bccomp($net, '0', self::S) < 0 ? ['negative_net'] : []];
    }

    /** Progressive bands: each band taxes the part of the base that falls inside it. Rounded once at the end, or per band when the scheme says so. */
    private static function bracketTax(string $base, array $brackets, string $mode, int $dec, bool $eachBand, array &$bands): string
    {
        usort($brackets, fn ($a, $b) => bccomp((string) $a['lower'], (string) $b['lower'], self::S));
        $sum = '0';
        foreach ($brackets as $b) {
            $lower = (string) $b['lower'];
            $upper = ($b['upper'] ?? null) === null || $b['upper'] === '' ? null : (string) $b['upper'];
            $top = $upper !== null && bccomp($base, $upper, self::S) > 0 ? $upper : $base;
            $part = bcsub($top, $lower, self::S);
            if (bccomp($part, '0', self::S) <= 0) {
                continue;
            }
            $raw = bcdiv(bcmul($part, (string) $b['rate'], self::S), '100', self::S);
            $amt = $eachBand ? PayRound::apply($raw, $mode, $dec) : $raw;
            $sum = bcadd($sum, $amt, self::S);
            $bands[] = ['lower' => $lower, 'upper' => $upper, 'rate' => (string) $b['rate'], 'part' => bcadd($part, '0', 2), 'amount' => $eachBand ? $amt : bcadd($raw, '0', 4)];
        }
        return PayRound::apply($sum, $mode, $dec);
    }

    private static function fix(string $v): string { return bcadd($v, '0', 2); }
}
