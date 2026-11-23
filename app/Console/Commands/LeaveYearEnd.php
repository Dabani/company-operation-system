<?php

namespace App\Console\Commands;

use App\Services\Hr\Leave\LeaveBalanceService;
use Illuminate\Console\Command;

class LeaveYearEnd extends Command
{
    protected $signature = 'cops:leave-year-end';
    protected $description = 'Apply the carry-over limit and the carry-over expiry set by the leave policy (safe to repeat; nothing is forfeited without a policy)';

    public function handle(LeaveBalanceService $svc): int
    {
        $this->info($svc->yearEnd() . ' forfeiture(s) posted.');
        return self::SUCCESS;
    }
}
