<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Requisition;
use App\Models\Vacancy;
use App\Services\AuditLogger;
use App\Services\Hr\HrSettings;
use App\Services\Hr\RecruitmentService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Requisitions, vacancies and recruitment settings (Guide 5.1). */
class RecruitmentController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }

    private function reqShape(Requisition $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'department_id' => $r->department_id, 'department' => $r->department?->name, 'position_id' => $r->position_id,
            'headcount' => $r->headcount, 'justification' => $r->justification, 'needed_by' => $r->needed_by?->toDateString(), 'status' => $r->status?->code, 'requested_by' => $r->requester?->name,
            'approval_request_id' => $r->approval_request_id, 'vacancy_id' => $r->vacancy?->id, 'created_at' => $r->created_at?->toIso8601String()];
    }

    public function requisitions(Request $request)
    {
        $q = Requisition::with(['department', 'status', 'requester', 'vacancy'])->orderByDesc('created_at');
        if (!$this->ctx()->can('hr.recruitment.manage') && !$this->ctx()->can('hr.requisition.approve')) {
            $q->where('requested_by', $request->user()->id);
        }
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id('requisition_status', $s));
        }
        return ['data' => $q->limit(300)->get()->map(fn ($r) => $this->reqShape($r))];
    }

    public function storeRequisition(Request $request, RecruitmentService $svc)
    {
        $org = $this->ctx()->organizationId();
        $d = $request->validate([
            'department_id' => ['required', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'position_id' => ['nullable', Rule::exists('hr_positions', 'id')->where('organization_id', $org)],
            'title' => 'required|string|max:150', 'headcount' => 'required|integer|min:1|max:500', 'justification' => 'required|string|max:5000', 'needed_by' => 'nullable|date_format:Y-m-d|after_or_equal:today',
        ]);
        $r = $svc->createRequisition($d, $request->user());
        return response()->json(['data' => $this->reqShape($r->load(['department', 'status', 'requester', 'vacancy']))], 201);
    }

    public function cancelRequisition(Request $request, string $id, RecruitmentService $svc)
    {
        $r = Requisition::findOrFail($id);
        abort_unless($r->requested_by === $request->user()->id || $this->ctx()->can('hr.recruitment.manage'), 403, 'forbidden');
        $svc->cancelRequisition($r, $request->user());
        return ['data' => $this->reqShape($r->refresh()->load(['department', 'status', 'requester', 'vacancy']))];
    }

    // ---- vacancies --------------------------------------------------------------------------------------------------
    private function vacShape(Vacancy $v): array
    {
        $applicants = Applicant::where('vacancy_id', $v->id);
        return ['id' => $v->id, 'code' => $v->code, 'title' => $v->title, 'description' => $v->description, 'status' => $v->status?->code, 'opened_on' => $v->opened_on->toDateString(), 'closes_on' => $v->closes_on?->toDateString(),
            'requisition_id' => $v->requisition_id, 'requisition_code' => $v->requisition?->code, 'headcount' => $v->requisition?->headcount, 'applicants' => (clone $applicants)->count(),
            'hired' => (clone $applicants)->whereNotNull('converted_employee_id')->count()];
    }

    public function vacancies()
    {
        return ['data' => Vacancy::with(['status', 'requisition'])->orderByDesc('opened_on')->limit(300)->get()->map(fn ($v) => $this->vacShape($v))];
    }

    public function openVacancy(Request $request, string $requisitionId, RecruitmentService $svc)
    {
        $r = Requisition::findOrFail($requisitionId);
        $d = $request->validate(['title' => 'nullable|string|max:150', 'description' => 'nullable|string|max:10000', 'closes_on' => 'nullable|date_format:Y-m-d|after_or_equal:today']);
        $v = $svc->openVacancy($r, $d);
        return response()->json(['data' => $this->vacShape($v->load(['status', 'requisition']))], 201);
    }

    public function updateVacancy(Request $request, string $id)
    {
        $v = Vacancy::findOrFail($id);
        $v->update($request->validate(['title' => 'sometimes|string|max:150', 'description' => 'nullable|string|max:10000', 'closes_on' => 'nullable|date_format:Y-m-d']));
        AuditLogger::log('vacancy.updated', $v);
        return ['data' => $this->vacShape($v->refresh()->load(['status', 'requisition']))];
    }

    public function closeVacancy(string $id, RecruitmentService $svc)
    {
        $v = Vacancy::findOrFail($id);
        $svc->closeVacancy($v);
        return ['data' => $this->vacShape($v->refresh()->load(['status', 'requisition']))];
    }

    // ---- settings -----------------------------------------------------------------------------------------------------
    public function settings() { return ['data' => HrSettings::all($this->ctx()->organizationId())]; }

    public function updateSettings(Request $request)
    {
        $d = $request->validate(['applicant_retention_months' => 'sometimes|integer', 'offer_validity_days' => 'sometimes|integer']);
        foreach ($d as $k => $v) {
            HrSettings::set($this->ctx()->organizationId(), $k, (int) $v);
            AuditLogger::log('hr.setting_changed', null, [], [$k => $v]);
        }
        return ['data' => HrSettings::all($this->ctx()->organizationId())];
    }
}
