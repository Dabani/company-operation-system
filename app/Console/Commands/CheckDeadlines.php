<?php

namespace App\Console\Commands;

use App\Models\ArchiveRecord;
use App\Models\ComplianceObligation;
use App\Models\User;
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

        // Inactive physical records that missed the transfer-to-archive window (one alert per organization per run).
        ArchiveRecord::withoutGlobalScopes()->whereHas('status', fn ($s) => $s->where('code', 'inactive'))->whereNull('transfer_alerted_at')->whereDate('transfer_due_on', '<', today())->get()
            ->groupBy('organization_id')->each(function ($recs, $org) use (&$n) {
                Notifier::send(Notifier::usersWithPermission($org, 'archive.manage'), 'record_transfer_due', ['count' => $recs->count()], null, ['in_app', 'email'], '/archive/records?due=transfer', $org);
                ArchiveRecord::withoutGlobalScopes()->whereIn('id', $recs->pluck('id'))->update(['transfer_alerted_at' => now()]); $n++;
            });

        // Archived records whose retention ended and that are free to be proposed for disposal.
        ArchiveRecord::withoutGlobalScopes()->whereHas('status', fn ($s) => $s->where('code', 'archived'))->whereNull('retention_alerted_at')->where('legal_hold', false)->whereDate('retention_until', '<', today())->get()
            ->groupBy('organization_id')->each(function ($recs, $org) use (&$n) {
                $to = Notifier::usersWithPermission($org, 'archive.manage')->merge(Notifier::usersWithPermission($org, 'archive.approve_disposal'))->unique('id');
                Notifier::send($to, 'record_retention_due', ['count' => $recs->count()], null, ['in_app', 'email'], '/archive/disposal', $org);
                ArchiveRecord::withoutGlobalScopes()->whereIn('id', $recs->pluck('id'))->update(['retention_alerted_at' => now()]); $n++;
            });

        // Compliance calendar: one alert per due date, to the responsible person/role and the compliance managers.
        ComplianceObligation::withoutGlobalScopes()->where('is_active', true)->whereDate('next_due_on', '<=', today())
            ->where(fn ($q) => $q->whereNull('alerted_for')->orWhereColumn('alerted_for', '!=', 'next_due_on'))->get()->each(function ($o) use (&$n) {
                $org = $o->organization_id;
                $to = Notifier::usersWithPermission($org, 'compliance.manage');
                if ($o->responsible_user_id) $to = $to->push(User::with('language')->find($o->responsible_user_id))->filter();
                if ($o->responsible_role_id) {
                    $to = $to->merge(User::with('language')->whereHas('memberships', fn ($m) => $m->where('organization_id', $org)->whereHas('roles', fn ($r) => $r->whereKey($o->responsible_role_id)))->get());
                }
                Notifier::send($to->unique('id'), 'obligation_due', ['title' => $o->title, 'date' => $o->next_due_on->toDateString()], null, ['in_app', 'email'], $o->link_route ?: '/compliance/calendar', $org);
                $o->update(['alerted_for' => $o->next_due_on]); $n++;
            });

        $this->info("Sent {$n} deadline alert(s).");
        return self::SUCCESS;
    }
}
