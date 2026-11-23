<?php

namespace App\Console\Commands;

use App\Services\Hr\Trips\TripService;
use Illuminate\Console\Command;

class TripsRemind extends Command
{
    protected $signature = 'cops:trips-remind';
    protected $description = 'Remind travellers and finance of advances that are past their retirement date (at most once a week per advance)';

    public function handle(TripService $svc): int
    {
        $this->info($svc->remindOverdue() . ' reminder(s) sent.');
        return self::SUCCESS;
    }
}
