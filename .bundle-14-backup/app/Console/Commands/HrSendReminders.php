<?php

namespace App\Console\Commands;

use App\Services\Hr\HrReminders;
use Illuminate\Console\Command;

class HrSendReminders extends Command
{
    protected $signature = 'cops:hr-reminders';
    protected $description = 'Send probation-end and contract-expiry reminders';

    public function handle(HrReminders $r): int
    {
        $this->info($r->run() . ' notification(s) sent.');
        return self::SUCCESS;
    }
}
