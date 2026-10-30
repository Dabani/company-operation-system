<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

/**
 * Operational data only. The audit log, case history, record history and disposal evidence are NEVER pruned here
 * (retention of those is governed by the retention schedule and legal requirements).
 */
class Prune extends Command
{
    protected $signature = 'ops:prune {--dry-run : Only report what would be removed}';
    protected $description = 'Remove old notification delivery logs and read in-app notifications beyond their configured retention.';

    public function handle(): int
    {
        $deliveryCutoff = now()->subDays((int) config('ops.retention.delivery_log_days', 365));
        $notificationCutoff = now()->subDays((int) config('ops.retention.read_notification_days', 180));

        $deliveries = NotificationDelivery::withoutGlobalScopes()->where('created_at', '<', $deliveryCutoff);
        $notifications = AppNotification::withoutGlobalScopes()->whereNotNull('read_at')->where('read_at', '<', $notificationCutoff);

        if ($this->option('dry-run')) {
            $this->info(sprintf('Would remove %d delivery log(s) and %d read notification(s).', $deliveries->count(), $notifications->count()));
            return self::SUCCESS;
        }

        $d = $deliveries->delete();
        $n = $notifications->delete();
        $this->info("Removed {$d} delivery log(s) and {$n} read notification(s).");
        return self::SUCCESS;
    }
}
