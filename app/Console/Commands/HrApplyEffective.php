<?php

namespace App\Console\Commands;

use App\Services\Hr\EmploymentService;
use Illuminate\Console\Command;

class HrApplyEffective extends Command
{
    protected $signature = 'cops:hr-apply-effective';
    protected $description = 'Apply approved employment changes whose effective date has arrived';

    public function handle(EmploymentService $svc): int
    {
        $this->info($svc->applyDue() . ' employee record(s) brought up to date.');
        return self::SUCCESS;
    }
}
