<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ManagementReport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;

/** The trail of what was formally reported to senior management. */
class ManagementReportController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    public function index(Request $request)
    {
        $lang = $request->user()->language_id;
        $q = ManagementReport::with(['kind.translations', 'submitter'])->orderByDesc('submitted_at');
        if ($k = data_get($request->query('filter'), 'kind_id')) $q->where('kind_id', $k);
        return response()->json(['data' => $q->limit(200)->get()->map(fn ($r) => [
            'id' => $r->id, 'title' => $r->title, 'period_label' => $r->period_label, 'body' => $r->body,
            'kind' => ['id' => $r->kind->id, 'code' => $r->kind->code, 'label' => $r->kind->label($lang), 'color' => $r->kind->color],
            'submitted_by' => $r->submitter?->name, 'submitted_at' => $r->submitted_at->toIso8601String(), 'recipients_count' => $r->recipients_count, 'source_id' => $r->source_id,
        ])]);
    }

    /** Stores the report and notifies everyone who may receive management reports. Used by training and audit modules. */
    public static function submit(string $kind, string $title, ?string $period, string $body, User $by, ?string $sourceId = null): ManagementReport
    {
        $ctx = app(OrganizationContext::class);
        $to = Notifier::usersWithPermission($ctx->organizationId(), 'report.receive')->reject(fn ($u) => $u->id === $by->id);
        $r = ManagementReport::create(['kind_id' => Lookup::id('report_kind', $kind), 'title' => $title, 'period_label' => $period, 'body' => $body,
            'submitted_by' => $by->id, 'recipients_count' => $to->count(), 'source_id' => $sourceId]);
        AuditLogger::log('management_report.submitted', $r, [], ['title' => $title, 'recipients' => (string) $to->count()]);
        Notifier::send($to, 'management_report', ['title' => $title, 'period' => $period ?? '', 'user' => $by->name], null, ['in_app', 'email'], '/compliance/reports', $ctx->organizationId());
        return $r;
    }
}
