<?php

namespace App\Console\Commands;

use App\Services\Meetings\FollowUpReminders;
use Illuminate\Console\Command;

class MeetingsFollowups extends Command
{
    protected $signature = 'cops:meetings-followups';
    protected $description = 'Remind owners of meeting resolutions and action items that are due soon or overdue';

    public function handle(FollowUpReminders $r): int
    {
        $this->info($r->run() . ' notice(s) sent.');
        return self::SUCCESS;
    }
}
