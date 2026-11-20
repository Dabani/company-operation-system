<?php

namespace App\Services\Hr\Payroll;

use App\Models\Employee;

/** Dry run (Guide 5.6.1): the full calculation for one employee and one period, with NOTHING written to a payroll table. */
class PayrollDryRun
{
    public function __construct(private PayrollConfigResolver $config) {}

    /** @return array{blockers:array,warnings:array,result:?array,meta:array} */
    public function run(Employee $e, string $from, string $to, array $extraLines = []): array
    {
        $r = $this->config->inputFor($e, $from, $to, $extraLines);
        $result = $r['input'] === null ? null : PayrollEngine::calculate($r['input']);
        $warnings = $r['warnings'];
        foreach ($result['warnings'] ?? [] as $code) {
            $warnings[] = ['code' => $code, 'params' => []];
        }
        return ['blockers' => $r['blockers'], 'warnings' => $warnings, 'result' => $result, 'meta' => $r['meta']];
    }
}
