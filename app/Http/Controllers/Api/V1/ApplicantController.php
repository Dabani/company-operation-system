<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Offer;
use App\Models\ReferenceCheck;
use App\Models\Vacancy;
use App\Services\AuditLogger;
use App\Services\Hr\RecruitmentService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Applicants, stages, references, selection, conversion and personal-data retention (Guide 5.1). Every route needs hr.recruitment.manage. */
class ApplicantController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }

    private function listShape(Applicant $a): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => trim("$a->first_name $a->last_name"), 'email' => $a->email, 'stage' => $a->stage?->code, 'vacancy_id' => $a->vacancy_id,
            'vacancy' => $a->vacancy?->title, 'applied_on' => $a->applied_on->toDateString(), 'retention_until' => $a->retention_until?->toDateString(), 'anonymized' => $a->anonymized_at !== null,
            'converted_employee_id' => $a->converted_employee_id];
    }

    public function index(Request $request)
    {
        $q = Applicant::with(['stage', 'vacancy'])->orderByDesc('applied_on')->orderByDesc('created_at');
        if ($v = $request->query('vacancy_id')) {
            $q->where('vacancy_id', $v);
        }
        if ($s = $request->query('stage')) {
            $q->where('stage_id', Lookup::id('application_stage', $s));
        }
        if ($request->boolean('retention_due')) {
            $q->whereNull('anonymized_at')->whereNull('converted_employee_id')->whereNotNull('retention_until')->whereDate('retention_until', '<=', now()->toDateString());
        }
        if ($t = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('first_name', 'like', "%$t%")->orWhere('last_name', 'like', "%$t%")->orWhere('email', 'like', "%$t%")->orWhere('code', 'like', "%$t%"));
        }
        return ['data' => $q->limit(300)->get()->map(fn ($a) => $this->listShape($a))];
    }

    private function detail(Applicant $a): array
    {
        $a->load(['stage', 'vacancy.requisition', 'history.toStage', 'interviews.type', 'interviews.status', 'interviews.panel', 'interviews.scorecards', 'references.result', 'offers.status', 'offers.position']);
        $salary = $this->ctx()->can('hr.salary.view');
        $hasAccepted = $a->offers->contains(fn ($o) => $o->status?->code === 'accepted');
        return $this->listShape($a) + [
            'first_name' => $a->first_name, 'last_name' => $a->last_name, 'phone' => $a->phone, 'source' => $a->source, 'notes' => $a->notes, 'rejection_reason' => $a->rejection_reason,
            'consent_recorded_at' => $a->consent_recorded_at?->toIso8601String(), 'can_convert' => $a->stage?->code === 'offer' && $hasAccepted && !$a->converted_employee_id,
            'history' => $a->history->sortBy('created_at')->values()->map(fn ($h) => ['stage' => $h->toStage?->code, 'note' => $h->note, 'at' => $h->created_at?->toIso8601String()]),
            'interviews' => $a->interviews->sortBy('scheduled_at')->values()->map(fn ($i) => ['id' => $i->id, 'type' => $i->type?->code, 'status' => $i->status?->code, 'scheduled_at' => $i->scheduled_at->toIso8601String(),
                'panel' => $i->panel->map(fn ($p) => $p->full_name)->values(), 'scorecards' => $i->scorecards->count()]),
            'references' => $a->references->map(fn ($r) => ['id' => $r->id, 'referee_name' => $r->referee_name, 'referee_contact' => $r->referee_contact, 'relationship' => $r->relationship,
                'result' => $r->result?->code, 'notes' => $r->notes, 'checked_on' => $r->checked_on?->toDateString()])->values(),
            'offers' => $a->offers->map(fn ($o) => ['id' => $o->id, 'code' => $o->code, 'status' => $o->status?->code, 'position' => $o->position?->title, 'start_date' => $o->start_date->toDateString(),
                'valid_until' => $o->valid_until?->toDateString()] + ($salary ? ['salary_amount' => $o->salary_amount, 'salary_currency' => $o->salary_currency, 'salary_period' => $o->salary_period] : []))->values(),
        ];
    }

    /** Reading a person's full application is audited. */
    public function show(string $id)
    {
        $a = Applicant::findOrFail($id);
        AuditLogger::log('applicant.viewed', $a);
        return ['data' => $this->detail($a)];
    }

    public function store(Request $request, string $vacancyId, RecruitmentService $svc)
    {
        $v = Vacancy::findOrFail($vacancyId);
        $d = $request->validate(['first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100', 'email' => 'required|email:rfc|max:190', 'phone' => 'nullable|string|max:40',
            'source' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:5000', 'consent' => 'required|boolean']);
        $a = $svc->addApplicant($v, $d, $request->user());
        return response()->json(['data' => $this->detail($a)], 201);
    }

    public function update(Request $request, string $id)
    {
        $a = Applicant::findOrFail($id);
        abort_if($a->anonymized_at, 422);
        $a->update($request->validate(['first_name' => 'sometimes|string|max:100', 'last_name' => 'sometimes|string|max:100', 'phone' => 'nullable|string|max:40', 'source' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:5000']));
        AuditLogger::log('applicant.updated', $a);
        return ['data' => $this->detail($a->refresh())];
    }

    public function stage(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        $d = $request->validate(['stage' => ['required', 'string', 'max:30'], 'note' => 'nullable|string|max:1000']);
        $svc->moveStage($a, $d['stage'], $d['note'] ?? null, $request->user());
        return ['data' => $this->detail($a->refresh())];
    }

    public function reject(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        $d = $request->validate(['reason' => 'required|string|max:1000']);
        $svc->reject($a, $d['reason'], $request->user());
        return ['data' => $this->detail($a->refresh())];
    }

    public function selection(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        $req = $svc->requestSelection($a, $request->user());
        return response()->json(['data' => ['approval_request_id' => $req->id]], 201);
    }

    public function convert(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        $d = $request->validate(['manager_employee_id' => ['nullable', Rule::exists('hr_employees', 'id')->where('organization_id', $this->ctx()->organizationId())]]);
        $e = $svc->convert($a, $d, $request->user());
        return response()->json(['data' => ['employee_id' => $e->id, 'employee_number' => $e->employee_number]], 201);
    }

    // ---- references --------------------------------------------------------------------------------------------------------
    private function refRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return ['referee_name' => "$req|string|max:150", 'referee_contact' => 'nullable|string|max:190', 'relationship' => 'nullable|string|max:100', 'result' => ['sometimes', Rule::in(['pending', 'satisfactory', 'concerns'])],
            'notes' => 'nullable|string|max:3000', 'checked_on' => 'nullable|date_format:Y-m-d'];
    }

    public function addReference(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        abort_if($a->anonymized_at, 422);
        $svc->addReference($a, $request->validate($this->refRules(true)), $request->user());
        return response()->json(['data' => $this->detail($a->refresh())], 201);
    }

    public function updateReference(Request $request, string $id)
    {
        $r = ReferenceCheck::findOrFail($id);
        $d = $request->validate($this->refRules(false));
        if (isset($d['result'])) {
            $d['result_id'] = Lookup::id('reference_result', $d['result']);
            unset($d['result']);
        }
        $r->update($d + ['checked_by' => $request->user()->id]);
        AuditLogger::log('reference.updated', $r);
        return ['data' => $this->detail(Applicant::findOrFail($r->applicant_id))];
    }

    // ---- retention ----------------------------------------------------------------------------------------------------------
    public function extendRetention(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        $d = $request->validate(['until' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:1000']);
        $svc->extendRetention($a, $d['until'], $d['reason'], $request->user());
        return ['data' => $this->detail($a->refresh())];
    }

    public function anonymize(Request $request, string $id, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($id);
        $svc->anonymize($a, $request->user());
        return ['data' => $this->listShape($a->refresh()->load(['stage', 'vacancy']))];
    }
}
