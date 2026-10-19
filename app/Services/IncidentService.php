<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CaseEvent;
use App\Models\IncidentDetail;
use App\Models\SupportCase;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    /** Response targets for a severity, from table incident_severity_rules. */
    public static function targets(string $severityId): array
    {
        $r = DB::table('incident_severity_rules')->where('severity_id', $severityId)->first();
        return [(int) ($r->containment_hours ?? 2), (int) ($r->rca_business_days ?? 5)];
    }

    public static function dueDates(string $severityId, Carbon $from): array
    {
        [$hours, $days] = self::targets($severityId);
        return [$from->copy()->addHours($hours), BusinessDays::add($from, $days)];
    }

    /**
     * Creates the incident case + its details in one transaction and alerts the incident managers.
     * $d: title, description (html), severity_id, detected_at?, impact?, immediate_action?, personal_data_breach?, department_id?
     */
    public static function report(array $d, User $reporter, ?Asset $asset = null, string $priorityCode = 'high'): SupportCase
    {
        $ctx = app(OrganizationContext::class);
        $detected = isset($d['detected_at']) ? Carbon::parse($d['detected_at']) : now();

        $case = DB::transaction(function () use ($d, $reporter, $asset, $priorityCode, $ctx, $detected) {
            $case = SupportCase::create([
                'reference' => CaseReference::next($ctx->organizationId()),
                'type_id' => Lookup::id('case_type', 'incident'),
                'status_id' => Lookup::id('case_status', 'new'),
                'priority_id' => Lookup::id('case_priority', $priorityCode),
                'title' => $d['title'], 'description' => $d['description'] ?? null,
                'department_id' => $d['department_id'] ?? null, 'requester_id' => $reporter->id,
            ]);
            CaseEvent::create(['case_id' => $case->id, 'user_id' => $reporter->id, 'event_type_id' => Lookup::id('case_event_type', 'created')]);

            [$containDue, $rcaDue] = self::dueDates($d['severity_id'], $detected);
            IncidentDetail::create([
                'case_id' => $case->id, 'severity_id' => $d['severity_id'], 'detected_at' => $detected,
                'impact' => $d['impact'] ?? null, 'immediate_action' => $d['immediate_action'] ?? null,
                'containment_due_at' => $containDue, 'rca_due_at' => $rcaDue,
                'personal_data_breach' => (bool) ($d['personal_data_breach'] ?? false), 'affected_asset_id' => $asset?->id,
            ]);
            return $case;
        });

        AuditLogger::log('incident.reported', $case, [], ['reference' => $case->reference]);

        $details = IncidentDetail::with('severity.translations')->find($case->id);
        $params = ['ref' => $case->reference, 'title' => $case->title, 'user' => $reporter->name, 'priority' => $details->severity->label(), 'app' => $ctx->organization()->name];
        Notifier::send($reporter->loadMissing('language'), 'case_created_user', $params, $case, ['in_app', 'email']);
        $managers = Notifier::usersWithPermission($ctx->organizationId(), 'incident.manage')->reject(fn ($u) => $u->id === $reporter->id);
        Notifier::send($managers, 'incident_reported', $params, $case, ['in_app', 'email', 'sms']);

        return $case;
    }
}
