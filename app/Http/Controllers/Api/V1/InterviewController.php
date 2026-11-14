<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Employee;
use App\Models\Interview;
use App\Services\Hr\RecruitmentService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Interviews, panels and scorecards. A panelist sees the other scorecards only after submitting their own (independent judgement). */
class InterviewController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }
    private function me(Request $r): ?Employee { return Employee::where('user_id', $r->user()->id)->first(); }
    private function manage(): bool { return $this->ctx()->can('hr.recruitment.manage'); }

    private function shape(Interview $i, ?Employee $me, bool $withScores): array
    {
        $submitted = $i->scorecards->pluck('panelist_employee_id');
        $mine = $me && $submitted->contains($me->id);
        $out = ['id' => $i->id, 'applicant_id' => $i->applicant_id, 'applicant' => trim($i->applicant?->first_name . ' ' . $i->applicant?->last_name), 'vacancy' => $i->applicant?->vacancy?->title,
            'type' => $i->type?->code, 'status' => $i->status?->code, 'scheduled_at' => $i->scheduled_at->toIso8601String(), 'location' => $i->location, 'notes' => $i->notes,
            'panel' => $i->panel->map(fn ($p) => ['id' => $p->id, 'name' => $p->full_name, 'submitted' => $submitted->contains($p->id)])->values(), 'my_scorecard_submitted' => $mine,
            'can_score' => $me && $i->panel->contains('id', $me->id) && !$mine && $i->status?->code !== 'cancelled'];
        if ($withScores) {
            $out['scorecards'] = $i->scorecards->filter(fn ($s) => $this->manage() || $mine || ($me && $s->panelist_employee_id === $me->id))->map(fn ($s) => [
                'panelist' => $s->panelist?->full_name, 'score' => $s->score, 'recommendation' => $s->recommendation?->code, 'strengths' => $s->strengths, 'concerns' => $s->concerns,
                'submitted_at' => $s->submitted_at->toIso8601String()])->values();
        }
        return $out;
    }

    private function query() { return Interview::with(['applicant.vacancy', 'type', 'status', 'panel', 'scorecards.panelist', 'scorecards.recommendation']); }

    public function index(Request $request)
    {
        $q = $this->query()->orderByDesc('scheduled_at');
        $me = $this->me($request);
        if (!$this->manage()) {
            $q->whereHas('panel', fn ($p) => $p->where('hr_employees.id', $me?->id ?? '0'));
        }
        if ($a = $request->query('applicant_id')) {
            $q->where('applicant_id', $a);
        }
        return ['data' => $q->limit(300)->get()->map(fn ($i) => $this->shape($i, $me, false))];
    }

    public function show(Request $request, string $id)
    {
        $i = $this->query()->findOrFail($id);
        $me = $this->me($request);
        abort_unless($this->manage() || ($me && $i->panel->contains('id', $me->id)), 404);
        return ['data' => $this->shape($i, $me, true)];
    }

    public function store(Request $request, string $applicantId, RecruitmentService $svc)
    {
        $a = Applicant::findOrFail($applicantId);
        $d = $request->validate(['type' => ['required', Rule::in(['interview', 'assessment'])], 'scheduled_at' => 'required|date', 'location' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000',
            'panel_employee_ids' => 'required|array|min:1|max:12', 'panel_employee_ids.*' => ['required', 'distinct', Rule::exists('hr_employees', 'id')->where('organization_id', $this->ctx()->organizationId())]]);
        $i = $svc->schedule($a, $d, $request->user());
        return response()->json(['data' => $this->shape($this->query()->findOrFail($i->id), null, false)], 201);
    }

    public function status(Request $request, string $id, RecruitmentService $svc)
    {
        $i = Interview::findOrFail($id);
        $d = $request->validate(['status' => ['required', Rule::in(['completed', 'cancelled'])]]);
        $svc->setInterviewStatus($i, $d['status']);
        return ['data' => $this->shape($this->query()->findOrFail($id), $this->me($request), false)];
    }

    public function scorecard(Request $request, string $id, RecruitmentService $svc)
    {
        $i = Interview::findOrFail($id);
        $me = $this->me($request);
        abort_unless($me, 403, 'forbidden');
        $d = $request->validate(['score' => 'required|integer|min:1|max:5', 'recommendation' => ['required', Rule::in(['strong_yes', 'yes', 'no', 'strong_no'])], 'strengths' => 'nullable|string|max:3000', 'concerns' => 'nullable|string|max:3000']);
        $svc->submitScorecard($i, $me, $d);
        return response()->json(['data' => $this->shape($this->query()->findOrFail($id), $me, true)], 201);
    }
}
