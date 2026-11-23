<?php

namespace App\Console\Commands;

use App\Services\Hr\Leave\LeaveBalanceService;
use Illuminate\Console\Command;

class LeaveAccrue extends Command
{
    protected $signature = 'cops:leave-accrue';
    protected $description = 'Accrue leave for every completed month of the current leave year that is not accrued yet (safe to repeat)';

    public function handle(LeaveBalanceService $svc): int
    {
        $this->info($svc->accrueDue() . ' accrual(s) posted.');
        return self::SUCCESS;
    }
}
