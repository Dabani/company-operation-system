<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditFinding;
use App\Models\ComplianceAudit;
use App\Models\LookupType;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\ObligationService;
use App\Services\OrganizationContext;
use App\Services\References;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Compliance audits (e.g. semi-annual): plan -> run -> complete with summary -> report to senior management; findings tracked to closure. */
class AuditController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['status.translations', 'lead', 'findings.severity.translations', 'findings.status.translations', 'findings.owner']; }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    private function lv($v, $lang): ?array { return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null; }

    private function finding(AuditFinding $f, $lang): array
    {
        $open = !in_array($f->status->code, ['resolved', 'accepted_risk'], true);
        return ['id' => $f->id, 'title' => $f->title, 'description' => $f->description, 'severity' => $this->lv($f->severity, $lang), 'status' => $this->lv($f->status, $lang),
            'owner' => $f->owner ? ['id' => $f->owner->id, 'name' => $f->owner->name] : null, 'due_on' => $f->due_on?->toDateString(), 'closed_on' => $f->closed_on?->toDateString(),
            'resolution_note' => $f->resolution_note, 'overdue' => $open && $f->due_on && $f->due_on->lt(today())];
    }

    private function row(ComplianceAudit $a, $lang, bool $detail = false): array
    {
        $open = $a->findings->filter(fn ($f) => !in_array($f->status->code, ['resolved', 'accepted_risk'], true));
        $r = ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'period_label' => $a->period_label, 'status' => $this->lv($a->status, $lang),
            'lead' => ['id' => $a->lead->id, 'name' => $a->lead->name], 'started_on' => $a->started_on?->toDateString(), 'completed_on' => $a->completed_on?->toDateString(),
            'reported_at' => $a->reported_at?->toIso8601String(), 'findings_total' => $a->findings->count(), 'findings_open' => $open->count()];
        if ($detail) $r += ['scope' => $a->scope, 'summary' => $a->summary, 'findings' => $a->findings->sortByDesc(fn ($f) => $f->created_at)->map(fn ($f) => $this->finding($f, $lang))->values()];
        return $r;
    }

    public function index(Request $request)
    {
        $lang = $request->user()->language_id;
        return response()->json(['data' => ComplianceAudit::with($this->with())->orderByDesc('created_at')->limit(100)->get()->map(fn ($a) => $this->row($a, $lang))->values()]);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(['data' => $this->row(ComplianceAudit::with($this->with())->findOrFail($id), $request->user()->language_id, true)]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['title' => 'required|string|max:200', 'period_label' => 'nullable|string|max:60', 'description' => 'nullable|string|max:20000',
            'lead_auditor_id' => ['required', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId())]]);
        $a = ComplianceAudit::create(['reference' => References::next($this->ctx->organizationId(), 'audit', 'AUD', true), 'title' => $d['title'], 'period_label' => $d['period_label'] ?? null,
            'scope' => $d['description'] ?? null, 'lead_auditor_id' => $d['lead_auditor_id'], 'status_id' => Lookup::id('audit_status', 'planned')]);
        AuditLogger::log('audit.created', $a, [], ['reference' => $a->reference]);
        return response()->json(['data' => $this->row($a->load($this->with()), $request->user()->language_id, true)], 201);
    }

    public function update(Request $request, string $id)
    {
        $a = ComplianceAudit::with($this->with())->findOrFail($id);
        abort_if($a->status->code === 'reported', 422, __('api.audit_reported'));
        $d = $request->validate(['title' => 'required|string|max:200', 'period_label' => 'nullable|string|max:60', 'description' => 'nullable|string|max:20000',
            'lead_auditor_id' => ['required', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId())]]);
        $a->update(['title' => $d['title'], 'period_label' => $d['period_label'] ?? null, 'scope' => $d['description'] ?? null, 'lead_auditor_id' => $d['lead_auditor_id']]);
        AuditLogger::log('audit.updated', $a, [], ['reference' => $a->reference]);
        return response()->json(['data' => $this->row($a->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    public function start(Request $request, string $id)
    {
        $a = ComplianceAudit::with($this->with())->findOrFail($id);
        abort_unless($a->status->code === 'planned', 422, __('api.audit_status'));
        $a->update(['status_id' => Lookup::id('audit_status', 'in_progress'), 'started_on' => today()]);
        AuditLogger::log('audit.started', $a, [], ['reference' => $a->reference]);
        return response()->json(['data' => $this->row($a->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    public function complete(Request $request, string $id)
    {
        $a = ComplianceAudit::with($this->with())->findOrFail($id);
        abort_unless($a->status->code === 'in_progress', 422, __('api.audit_status'));
        $d = $request->validate(['summary' => 'required|string|max:50000']);
        $a->update(['status_id' => Lookup::id('audit_status', 'completed'), 'completed_on' => today(), 'summary' => $d['summary']]);
        AuditLogger::log('audit.completed', $a, [], ['reference' => $a->reference]);
        ObligationService::autoComplete($this->ctx->organizationId(), 'compliance_audit', $request->user(), $a->reference);
        return response()->json(['data' => $this->row($a->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    /** Send the report to senior management (stored in the management-report trail + notified). */
    public function report(Request $request, string $id)
    {
        $a = ComplianceAudit::with($this->with())->findOrFail($id);
        abort_unless($a->status->code === 'completed', 422, __('api.audit_status'));
        $e = fn ($s) => e((string) $s);

        $html = '<p><strong>' . $e($a->reference . ' - ' . $a->title) . '</strong> (' . $e($a->period_label ?? '') . ')</p>' . ($a->summary ?? '');
        if ($a->findings->isNotEmpty()) {
            $html .= '<p>' . $a->findings->count() . ' finding(s):</p><ul>' . $a->findings->map(fn ($f) => '<li>[' . $e($f->severity->label()) . '] ' . $e($f->title) . ($f->owner ? ' - ' . $e($f->owner->name) : '') . ($f->due_on ? ' (' . $f->due_on->toDateString() . ')' : '') . '</li>')->implode('') . '</ul>';
        }
        $r = ManagementReportController::submit('audit', $a->reference . ' - ' . $a->title, $a->period_label, $html, $request->user(), $a->id);
        $a->update(['status_id' => Lookup::id('audit_status', 'reported'), 'reported_at' => now(), 'reported_by' => $request->user()->id]);
        AuditLogger::log('audit.reported', $a, [], ['reference' => $a->reference, 'recipients' => (string) $r->recipients_count]);
        return response()->json(['data' => $this->row($a->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    // ---- findings -----------------------------------------------------------------------------

    private function findingRules(): array
    {
        return ['title' => 'required|string|max:250', 'description' => 'nullable|string|max:20000', 'severity_id' => ['required', $this->lookupRule('incident_severity')],
            'owner_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId())], 'due_on' => 'nullable|date'];
    }

    public function addFinding(Request $request, string $id)
    {
        $a = ComplianceAudit::with('status')->findOrFail($id);
        abort_if($a->status->code === 'reported', 422, __('api.audit_reported'));
        $d = $request->validate($this->findingRules());
        $f = AuditFinding::create($d + ['audit_id' => $a->id, 'status_id' => Lookup::id('finding_status', 'open'), 'created_by' => $request->user()->id]);
        AuditLogger::log('audit.finding_added', $a, [], ['finding' => $f->title]);
        $this->notifyOwner($f, $a, $request);
        return response()->json(['data' => ['id' => $f->id]], 201);
    }

    /** Compliance managers edit everything; the finding's owner may only move the status and write the resolution note. */
    public function updateFinding(Request $request, string $id)
    {
        $f = AuditFinding::with(['status', 'audit'])->findOrFail($id);
        $manage = $this->ctx->can('compliance.manage');
        abort_unless($manage || $f->owner_id === $request->user()->id, 403);
        $rules = ['status_id' => ['sometimes', $this->lookupRule('finding_status')], 'resolution_note' => 'sometimes|nullable|string|max:1000'];
        $d = $request->validate($manage ? $rules + $this->findingRules() : $rules);

        $oldOwner = $f->owner_id;
        $f->fill($d);
        if (isset($d['status_id'])) {
            $closing = in_array(\App\Models\LookupValue::find($d['status_id'])->code, ['resolved', 'accepted_risk'], true);
            $f->closed_on = $closing ? today() : null;
        }
        $f->save();
        AuditLogger::log('audit.finding_updated', $f->audit, [], ['finding' => $f->title]);
        if ($f->owner_id && $f->owner_id !== $oldOwner) $this->notifyOwner($f, $f->audit, $request);
        return response()->json(['data' => ['id' => $f->id]]);
    }

    private function notifyOwner(AuditFinding $f, ComplianceAudit $a, Request $request): void
    {
        if ($f->owner_id && $f->owner_id !== $request->user()->id) {
            Notifier::send(\App\Models\User::with('language')->find($f->owner_id), 'audit_finding_assigned', ['title' => $f->title, 'ref' => $a->reference, 'user' => $request->user()->name], null, ['in_app', 'email'], '/compliance/audits?open=' . $a->id, $this->ctx->organizationId());
        }
    }
}
