<?php

namespace App\Providers;

use App\Console\Commands\MeetingsRemind;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 7a wiring: the meeting reminder job. */
class MeetingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([MeetingsRemind::class]);
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('cops:meetings-remind')->everyFiveMinutes()->withoutOverlapping());
    }
}
