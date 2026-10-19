<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IncidentResource;
use App\Models\Asset;
use App\Models\IncidentDetail;
use App\Models\LookupType;
use App\Models\SupportCase;
use App\Services\AuditLogger;
use App\Services\IncidentService;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    /** Same visibility rule as cases: everything for case.view_all, otherwise own / assigned. */
    private function visibleCases()
    {
        $q = SupportCase::query()->whereHas('type', fn ($t) => $t->where('code', 'incident'));
        if (!$this->ctx->can('case.view_all')) {
            $me = request()->user()->id;
            $q->where(fn ($w) => $w->where('requester_id', $me)->orWhere('assignee_id', $me));
        }
        return $q;
    }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    public function index(Request $request)
    {
        $q = $this->visibleCases()->with(['status.translations', 'priority.translations', 'requester', 'incident.severity.translations']);
        if ($sev = $request->query('severity_id')) $q->whereHas('incident', fn ($i) => $i->where('severity_id', $sev));
        if ($request->boolean('overdue')) {
            $q->whereHas('status', fn ($s) => $s->whereNotIn('code', ['resolved', 'closed', 'cancelled']))
              ->whereHas('incident', fn ($i) => $i->where(fn ($w) => $w->where(fn ($c) => $c->whereNull('contained_at')->where('containment_due_at', '<', now()))
                  ->orWhere(fn ($r) => $r->whereNull('rca_completed_at')->where('rca_due_at', '<', now()))));
        }
        return $this->respondList($request, $q, IncidentResource::class, ['reference', 'title', 'requester.name'], ['created_at', 'reference', 'title'], ['status_id'],
            ['reference', 'title', 'severity_label', 'status_label', 'requester_name', 'detected_at', 'containment_due_at', 'contained_at', 'rca_due_at', 'rca_completed_at', 'personal_data_breach']);
    }

    /** Incident Reporting Form. */
    public function store(Request $request)
    {
        $d = $request->validate([
            'title' => 'required|string|max:200',
            'severity_id' => ['nullable', $this->lookupRule('incident_severity')],
            'detected_at' => 'nullable|date|before_or_equal:now',
            'description' => 'required|string|max:50000',            // what happened
            'impact' => 'nullable|string|max:20000',
            'immediate_action' => 'nullable|string|max:20000',
            'personal_data_breach' => 'boolean',
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $this->ctx->organizationId())],
            'affected_asset_id' => ['nullable', Rule::exists('assets', 'id')->where('organization_id', $this->ctx->organizationId())],
        ]);
        $d['severity_id'] = $d['severity_id'] ?? Lookup::id('incident_severity', 'medium');
        $asset = !empty($d['affected_asset_id']) ? Asset::find($d['affected_asset_id']) : null;

        $case = IncidentService::report($d, $request->user(), $asset, 'high');
        return response()->json(['data' => ['id' => $case->id, 'reference' => $case->reference]], 201);
    }

    public function show(Request $request, string $caseId)
    {
        $case = $this->visibleCases()->findOrFail($caseId);
        $i = IncidentDetail::with(['severity.translations', 'asset'])->findOrFail($case->id);
        $lang = $request->user()->language_id;
        $open = !in_array($case->status->code, ['resolved', 'closed', 'cancelled'], true);

        return response()->json(['data' => [
            'case_id' => $case->id,
            'severity' => ['id' => $i->severity->id, 'code' => $i->severity->code, 'label' => $i->severity->label($lang), 'color' => $i->severity->color],
            'detected_at' => $i->detected_at?->toIso8601String(),
            'impact' => $i->impact, 'immediate_action' => $i->immediate_action,
            'containment_due_at' => $i->containment_due_at?->toIso8601String(), 'contained_at' => $i->contained_at?->toIso8601String(),
            'rca_due_at' => $i->rca_due_at?->toIso8601String(), 'rca_completed_at' => $i->rca_completed_at?->toIso8601String(),
            'containment_overdue' => $open && $i->containmentOverdue(), 'rca_overdue' => $open && $i->rcaOverdue(),
            'root_cause' => $i->root_cause, 'preventive_action_plan' => $i->preventive_action_plan,
            'follow_up_required' => $i->follow_up_required, 'personal_data_breach' => $i->personal_data_breach,
            'regulator_notified_at' => $i->regulator_notified_at?->toIso8601String(), 'data_subjects_notified_at' => $i->data_subjects_notified_at?->toIso8601String(),
            'affected_asset' => $i->asset ? ['id' => $i->asset->id, 'label' => $i->asset->asset_tag . ' — ' . $i->asset->name] : null,
            'can_manage' => $this->ctx->can('incident.manage'),
        ]]);
    }

    /** IT / incident managers: containment, root cause analysis, preventive plan, breach notifications. */
    public function update(Request $request, string $caseId)
    {
        $case = $this->visibleCases()->with('requester')->findOrFail($caseId);
        $i = IncidentDetail::findOrFail($case->id);

        $d = $request->validate([
            'severity_id' => ['sometimes', $this->lookupRule('incident_severity')],
            'impact' => 'sometimes|nullable|string|max:20000',
            'immediate_action' => 'sometimes|nullable|string|max:20000',
            'root_cause' => 'sometimes|nullable|string|max:20000',
            'preventive_action_plan' => 'sometimes|nullable|string|max:20000',
            'follow_up_required' => 'sometimes|boolean',
            'personal_data_breach' => 'sometimes|boolean',
            'contained' => 'sometimes|boolean',
            'rca_completed' => 'sometimes|boolean',
            'regulator_notified' => 'sometimes|boolean',
            'data_subjects_notified' => 'sometimes|boolean',
        ]);

        $old = ['contained' => $i->contained_at ? 'yes' : 'no', 'rca' => $i->rca_completed_at ? 'yes' : 'no', 'severity' => $i->severity_id];
        $attrs = array_diff_key($d, array_flip(['contained', 'rca_completed', 'regulator_notified', 'data_subjects_notified']));

        if (isset($d['severity_id']) && $d['severity_id'] !== $i->severity_id) {            // new severity => new response targets
            [$cd, $rd] = IncidentService::dueDates($d['severity_id'], $i->detected_at ?? $case->created_at);
            $attrs += ['containment_due_at' => $cd, 'rca_due_at' => $rd];
        }
        if (array_key_exists('contained', $d)) $attrs['contained_at'] = $d['contained'] ? ($i->contained_at ?? now()) : null;
        if (array_key_exists('regulator_notified', $d)) $attrs['regulator_notified_at'] = $d['regulator_notified'] ? ($i->regulator_notified_at ?? now()) : null;
        if (array_key_exists('data_subjects_notified', $d)) $attrs['data_subjects_notified_at'] = $d['data_subjects_notified'] ? ($i->data_subjects_notified_at ?? now()) : null;

        if (!empty($d['rca_completed'])) {
            $cause = trim(strip_tags((string) ($attrs['root_cause'] ?? $i->root_cause)));
            $plan = trim(strip_tags((string) ($attrs['preventive_action_plan'] ?? $i->preventive_action_plan)));
            abort_if($cause === '' || $plan === '', 422, __('api.rca_requires_plan'));        // every incident report needs a preventive action plan
            $attrs['rca_completed_at'] = $i->rca_completed_at ?? now();
        } elseif (array_key_exists('rca_completed', $d)) {
            $attrs['rca_completed_at'] = null;
        }

        $i->update($attrs);
        AuditLogger::log('incident.updated', $case, $old, ['contained' => $i->contained_at ? 'yes' : 'no', 'rca' => $i->rca_completed_at ? 'yes' : 'no', 'severity' => $i->severity_id]);

        if (!empty($d['contained']) && $old['contained'] === 'no' && $case->requester_id !== $request->user()->id) {
            Notifier::send($case->requester->loadMissing('language'), 'incident_contained', ['ref' => $case->reference, 'title' => $case->title, 'user' => $request->user()->name], $case, ['in_app', 'email']);
        }
        return $this->show($request, $caseId);
    }
}
