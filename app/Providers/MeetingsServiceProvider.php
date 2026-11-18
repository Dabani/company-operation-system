<?php

namespace App\Providers;

use App\Console\Commands\MeetingsFollowups;
use App\Console\Commands\MeetingsRemind;
use App\Services\Events\ExpenseApprovalHandler;
use App\Services\Meetings\MinutesApprovalHandler;
use App\Services\Meetings\MinutesChairResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 7 wiring: reminder jobs (7a meeting reminders, 7d follow-up reminders), the minutes approval handler and the "meeting_chair" step resolver (7c), the event expense approval handler (7e). */
class MeetingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([MeetingsRemind::class, MeetingsFollowups::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $s) {
            $s->command('cops:meetings-remind')->everyFiveMinutes()->withoutOverlapping();
            $s->command('cops:meetings-followups')->hourly()->withoutOverlapping();
        });
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['meeting_minutes' => MinutesApprovalHandler::class, 'event_expense' => ExpenseApprovalHandler::class]),
            'cops.step_resolvers' => array_merge(config('cops.step_resolvers', []), ['meeting_chair' => MinutesChairResolver::class]),
        ]);
    }
}
