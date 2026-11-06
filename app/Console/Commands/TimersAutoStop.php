<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\OrganizationContext;
use App\Services\Time\TimeService;
use Illuminate\Console\Command;

class TimersAutoStop extends Command
{
    protected $signature = 'cops:timers-autostop';
    protected $description = 'Stop forgotten timers that ran past the allowed duration and flag them for correction';

    public function handle(): int
    {
        $n = 0;
        foreach (Organization::all() as $org) {
            app(OrganizationContext::class)->resolveForOrganization($org);
            $n += app(TimeService::class)->autoStopDue();
        }
        $this->info("Auto-stopped $n timer(s).");
        return self::SUCCESS;
    }
}
