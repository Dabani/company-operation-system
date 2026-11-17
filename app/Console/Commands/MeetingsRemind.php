<?php

namespace App\Console\Commands;

use App\Services\Meetings\MeetingReminders;
use Illuminate\Console\Command;

class MeetingsRemind extends Command
{
    protected $signature = 'cops:meetings-remind';
    protected $description = 'Send meeting reminders (24 h and 1 h before by default; each reminder goes once)';

    public function handle(MeetingReminders $r): int
    {
        $this->info($r->run() . ' reminder(s) sent.');
        return self::SUCCESS;
    }
}
