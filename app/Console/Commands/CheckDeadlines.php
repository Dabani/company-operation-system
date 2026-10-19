<?php

namespace App\Console\Commands;

use App\Models\EquipmentMovement;
use App\Models\IncidentDetail;
use App\Services\Notifier;
use Illuminate\Console\Command;

class CheckDeadlines extends Command
{
    protected $signature = 'ops:check-deadlines';
    protected $description = 'Alert on breached incident response targets and overdue equipment returns (each alert is sent once).';

    public function handle(): int
    {
        $n = 0;

        $open = fn ($q) => $q->whereHas('status', fn ($s) => $s->whereNotIn('code', ['resolved', 'closed', 'cancelled']));

        IncidentDetail::with('supportCase')->whereHas('supportCase', $open)
            ->whereNull('contained_at')->whereNull('containment_alerted_at')->where('containment_due_at', '<', now())->get()
            ->each(function ($i) use (&$n) {
                $c = $i->supportCase;
                Notifier::send(Notifier::usersWithPermission($c->organization_id, 'incident.manage'), 'incident_sla_containment', ['ref' => $c->reference, 'title' => $c->title], $c, ['in_app', 'email', 'sms'], null, $c->organization_id);
                $i->update(['containment_alerted_at' => now()]); $n++;
            });

        IncidentDetail::with('supportCase')->whereHas('supportCase', $open)
            ->whereNull('rca_completed_at')->whereNull('rca_alerted_at')->where('rca_due_at', '<', now())->get()
            ->each(function ($i) use (&$n) {
                $c = $i->supportCase;
                Notifier::send(Notifier::usersWithPermission($c->organization_id, 'incident.manage'), 'incident_sla_rca', ['ref' => $c->reference, 'title' => $c->title], $c, ['in_app', 'email'], null, $c->organization_id);
                $i->update(['rca_alerted_at' => now()]); $n++;
            });

        EquipmentMovement::withoutGlobalScopes()->with(['requester.language'])->whereHas('status', fn ($s) => $s->where('code', 'checked_out'))
            ->whereNull('overdue_alerted_at')->whereDate('expected_return_date', '<', today())->get()
            ->each(function ($m) use (&$n) {
                $to = Notifier::usersWithPermission($m->organization_id, 'asset.manage')->push($m->requester)->unique('id');
                Notifier::send($to, 'movement_overdue', ['ref' => $m->reference, 'user' => $m->requester->name, 'date' => $m->expected_return_date->toDateString()], null, ['in_app', 'email'], '/assets/movements?open=' . $m->id, $m->organization_id);
                $m->update(['overdue_alerted_at' => now()]); $n++;
            });

        $this->info("Sent {$n} deadline alert(s).");
        return self::SUCCESS;
    }
}
