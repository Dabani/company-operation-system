<?php

namespace App\Models;

use App\Support\Lookup;
use RuntimeException;

/**
 * The database-level promise that a locked payroll run is immutable: every model that belongs to a run calls this before it is created, changed or deleted.
 * Even a bug in a service cannot alter a locked, paid or declared run.
 */
final class PayRunLock
{
    public static function assertOpen(?string $runId): void
    {
        if (!$runId) {
            return;
        }
        $status = PayRun::withoutGlobalScopes()->whereKey($runId)->value('status_id');
        foreach (['locked', 'paid', 'declared'] as $code) {
            if ($status !== null && $status === Lookup::id('pay_run_status', $code)) {
                throw new RuntimeException('payroll run is locked: it can no longer be changed');
            }
        }
    }

    public static function runIdOfPayslip(?string $payslipId): ?string
    {
        return $payslipId ? PayPayslip::withoutGlobalScopes()->whereKey($payslipId)->value('run_id') : null;
    }

    public static function runIdOfLine(?string $lineId): ?string
    {
        return $lineId ? self::runIdOfPayslip(PayPayslipLine::withoutGlobalScopes()->whereKey($lineId)->value('payslip_id')) : null;
    }
}
