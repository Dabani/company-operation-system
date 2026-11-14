<?php

namespace App\Console\Commands;

use App\Services\Hr\Attendance\AttendanceService;
use Illuminate\Console\Command;

class HrAttendanceClose extends Command
{
    protected $signature = 'cops:hr-attendance-close';
    protected $description = 'Bring the last two completed days of attendance up to date (detects absences)';

    public function handle(AttendanceService $svc): int
    {
        $this->info($svc->closeRecentDays() . ' employee(s) processed.');
        return self::SUCCESS;
    }
}
