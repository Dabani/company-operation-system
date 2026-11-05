<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Approvals\ApprovalEngine;
use App\Services\OrganizationContext;
use Illuminate\Console\Command;

class ApprovalsEscalate extends Command
{
    protected $signature = 'cops:approvals-escalate';
    protected $description = 'Send reminders for approval steps pending longer than their configured hours';

    public function handle(ApprovalEngine $engine): int
    {
        $total = 0;
        foreach (Organization::all() as $org) {
            app(OrganizationContext::class)->resolveForOrganization($org);
            $total += $engine->escalateOverdue();
        }
        $this->info("Escalated $total request(s).");
        return self::SUCCESS;
    }
}
