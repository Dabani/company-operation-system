<?php

namespace App\Services\Hr;

use App\Models\Applicant;
use App\Models\ApplicantStageHistory;
use App\Models\AppNotification;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Membership;
use App\Models\OnboardingItem;
use App\Models\OnboardingTemplateItem;
use App\Models\Offer;
use App\Models\ReferenceCheck;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\Scorecard;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\MoneyService;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recruitment (Guide 5.1): requisition -> approval -> vacancy -> applicants -> screening -> interviews and scorecards ->
 * selection (approval) -> offer -> acceptance -> one-click conversion to an employee with an onboarding checklist.
 * Applicant personal data is reviewed, never silently deleted.
 */
class RecruitmentService
{
    /** Stages an applicant can be moved between by hand. 'offer' comes from an approved selection, 'hired' from conversion, 'rejected' from reject(). */
    private const MANUAL_STAGES = ['applied', 'screened', 'interview', 'assessment'];

    public function __construct(private ApprovalEngine $approvals) {}

    private function fail(string $field, string $key, array $params = []): never
    {
        throw ValidationException::withMessages([$field => [__("recruitment.$key", $params)]]);
    }

    private function id(string $type, string $code): string { return Lookup::id($type, $code); }
    private function stageCode(Applicant $a): string { return $a->loadMissing('stage')->stage->code; }

    // ---- requisitions -------------------------------------------------------------------------------------------------
    public function createRequisition(array $d, User $by): Requisition
    {
        return DB::transaction(function () use ($d, $by) {
            $r = Requisition::create($d + ['status_id' => $this->id('requisition_status', 'pending_approval'), 'requested_by' => $by->id]);
            AuditLogger::log('requisition.created', $r, [], ['headcount' => $r->headcount]);
            $req = $this->approvals->submit('workforce_requisition', $r->id, $by, __('recruitment.approval_title.requisition', ['title' => $r->title]), ['department_id' => $r->department_id]);
            $r->update(['approval_request_id' => $req->id]);
            return $r->refresh();
        });
    }

    public function requisitionOutcome(string $id, string $outcome): void
    {
        $r = Requisition::withoutGlobalScopes()->find($id);
        if (!$r || $r->status_id !== $this->id('requisition_status', 'pending_approval')) {
            return;
        }
        $r->update(['status_id' => $this->id('requisition_status', $outcome)]);
        AuditLogger::log("requisition.$outcome", $r);
    }

    public function cancelRequisition(Requisition $r, User $by): void
    {
        $status = $r->loadMissing('status')->status->code;
        if ($status === 'pending_approval' && $r->approval_request_id) {
            $this->approvals->cancel($r->approval_request_id, $by);          // the handler marks it cancelled
        } elseif ($status === 'approved' && !$r->vacancy()->exists()) {
            $r->update(['status_id' => $this->id('requisition_status', 'cancelled')]);
            AuditLogger::log('requisition.cancelled', $r);
        } else {
            $this->fail('requisition', 'cannot_cancel');
        }
    }

    // ---- vacancies ----------------------------------------------------------------------------------------------------
    public function openVacancy(Requisition $r, array $d): Vacancy
    {
        if ($r->loadMissing('status')->status->code !== 'approved') {
            $this->fail('requisition', 'requisition_not_approved');
        }
        if ($r->vacancy()->exists()) {
            $this->fail('requisition', 'vacancy_exists');
        }
        $v = Vacancy::create(['requisition_id' => $r->id, 'title' => $d['title'] ?? $r->title, 'description' => $d['description'] ?? null,
            'status_id' => $this->id('vacancy_status', 'open'), 'opened_on' => now()->toDateString(), 'closes_on' => $d['closes_on'] ?? null]);
        AuditLogger::log('vacancy.opened', $v);
        return $v;
    }

    public function closeVacancy(Vacancy $v): void
    {
        $v->update(['status_id' => $this->id('vacancy_status', 'closed')]);
        AuditLogger::log('vacancy.closed', $v);
    }

    // ---- applicants ---------------------------------------------------------------------------------------------------
    private function record(Applicant $a, ?string $from, string $to, ?string $note, ?User $by): void
    {
        ApplicantStageHistory::create(['organization_id' => $a->organization_id, 'applicant_id' => $a->id, 'from_stage_id' => $from, 'to_stage_id' => $to,
            'note' => $note, 'changed_by' => $by?->id, 'created_at' => now()]);
    }

    private function setStage(Applicant $a, string $code, ?string $note, ?User $by): void
    {
        $from = $a->stage_id;
        $a->update(['stage_id' => $this->id('application_stage', $code)]);
        $a->unsetRelation('stage');
        $this->record($a, $from, $a->stage_id, $note, $by);
    }

    public function addApplicant(Vacancy $v, array $d, ?User $by = null): Applicant
    {
        if ($v->loadMissing('status')->status->code !== 'open') {
            $this->fail('vacancy', 'vacancy_closed');
        }
        if (empty($d['consent'])) {
            $this->fail('consent', 'consent_required');
        }
        $email = strtolower(trim($d['email']));
        if (Applicant::where('vacancy_id', $v->id)->where('email', $email)->exists()) {
            $this->fail('email', 'duplicate_applicant');
        }
        $months = HrSettings::get($v->organization_id, 'applicant_retention_months');
        $a = Applicant::create(['vacancy_id' => $v->id, 'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'email' => $email, 'phone' => $d['phone'] ?? null,
            'source' => $d['source'] ?? null, 'notes' => $d['notes'] ?? null, 'stage_id' => $this->id('application_stage', 'applied'), 'applied_on' => now()->toDateString(),
            'consent_recorded_at' => now(), 'retention_until' => now()->addMonthsNoOverflow($months)->toDateString()]);
        $this->record($a, null, $a->stage_id, null, $by);
        AuditLogger::log('applicant.created', $a);
        return $a->refresh();
    }

    public function moveStage(Applicant $a, string $to, ?string $note, User $by): void
    {
        $cur = $this->stageCode($a);
        if (in_array($cur, ['offer', 'hired', 'rejected'], true)) {
            $this->fail('stage', 'stage_locked');
        }
        if (!in_array($to, self::MANUAL_STAGES, true)) {
            $this->fail('stage', 'stage_not_allowed');
        }
        if ($to !== $cur) {
            $this->setStage($a, $to, $note, $by);
            AuditLogger::log('applicant.stage_changed', $a, ['stage' => $cur], ['stage' => $to]);
        }
    }

    public function reject(Applicant $a, string $reason, ?User $by): void
    {
        $cur = $this->stageCode($a);
        if (in_array($cur, ['hired', 'rejected'], true)) {
            $this->fail('stage', 'stage_locked');
        }
        DB::transaction(function () use ($a, $reason, $by) {
            Offer::where('applicant_id', $a->id)->whereIn('status_id', [$this->id('offer_status', 'draft'), $this->id('offer_status', 'issued'), $this->id('offer_status', 'accepted')])
                ->get()->each(fn ($o) => $o->update(['status_id' => $this->id('offer_status', 'withdrawn')]));
            $a->update(['rejection_reason' => $reason, 'retention_until' => now()->addMonthsNoOverflow(HrSettings::get($a->organization_id, 'applicant_retention_months'))->toDateString(), 'retention_flagged_at' => null]);
            $this->setStage($a, 'rejected', $reason, $by);
        });
        AuditLogger::log('applicant.rejected', $a, ['stage' => $cur]);
    }

    // ---- interviews and scorecards ------------------------------------------------------------------------------------
    public function schedule(Applicant $a, array $d, User $by): Interview
    {
        $cur = $this->stageCode($a);
        if (!in_array($cur, self::MANUAL_STAGES, true)) {
            $this->fail('applicant', 'stage_not_allowed');
        }
        return DB::transaction(function () use ($a, $d, $by, $cur) {
            $i = Interview::create(['applicant_id' => $a->id, 'type_id' => $this->id('interview_type', $d['type']), 'status_id' => $this->id('interview_status', 'scheduled'),
                'scheduled_at' => $d['scheduled_at'], 'location' => $d['location'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => $by->id]);
            $i->panel()->sync($d['panel_employee_ids']);
            if ($d['type'] === 'interview' && in_array($cur, ['applied', 'screened'], true)) {
                $this->setStage($a, 'interview', null, $by);
            } elseif ($d['type'] === 'assessment' && in_array($cur, ['applied', 'screened', 'interview'], true)) {
                $this->setStage($a, 'assessment', null, $by);
            }
            AuditLogger::log('interview.scheduled', $i, [], ['applicant_id' => $a->id, 'type' => $d['type']]);
            return $i;
        });
    }

    public function setInterviewStatus(Interview $i, string $status): void
    {
        if ($i->loadMissing('status')->status->code !== 'scheduled') {
            $this->fail('status', 'interview_closed');
        }
        $i->update(['status_id' => $this->id('interview_status', $status)]);
        AuditLogger::log("interview.$status", $i);
    }

    public function submitScorecard(Interview $i, Employee $panelist, array $d): Scorecard
    {
        if (!$i->panel()->where('hr_employees.id', $panelist->id)->exists()) {
            abort(404);
        }
        if ($i->loadMissing('status')->status->code === 'cancelled') {
            $this->fail('interview', 'interview_closed');
        }
        if (Scorecard::where('interview_id', $i->id)->where('panelist_employee_id', $panelist->id)->exists()) {
            $this->fail('score', 'scorecard_exists');
        }
        $s = Scorecard::create(['interview_id' => $i->id, 'panelist_employee_id' => $panelist->id, 'score' => $d['score'],
            'recommendation_id' => $this->id('scorecard_recommendation', $d['recommendation']), 'strengths' => $d['strengths'] ?? null, 'concerns' => $d['concerns'] ?? null, 'submitted_at' => now()]);
        if (Scorecard::where('interview_id', $i->id)->count() >= $i->panel()->count() && $i->status->code === 'scheduled') {
            $i->update(['status_id' => $this->id('interview_status', 'completed')]);      // everyone on the panel has scored
        }
        AuditLogger::log('scorecard.submitted', $s, [], ['interview_id' => $i->id]);
        return $s;
    }

    public function addReference(Applicant $a, array $d, User $by): ReferenceCheck
    {
        $r = ReferenceCheck::create(['applicant_id' => $a->id, 'referee_name' => $d['referee_name'], 'referee_contact' => $d['referee_contact'] ?? null, 'relationship' => $d['relationship'] ?? null,
            'result_id' => $this->id('reference_result', $d['result'] ?? 'pending'), 'notes' => $d['notes'] ?? null, 'checked_on' => $d['checked_on'] ?? null, 'checked_by' => $by->id]);
        AuditLogger::log('reference.recorded', $r, [], ['applicant_id' => $a->id]);
        return $r;
    }

    // ---- selection ------------------------------------------------------------------------------------------------------
    private function taken(Vacancy $v): int
    {
        return Applicant::where('vacancy_id', $v->id)->where(fn ($w) => $w->whereNotNull('converted_employee_id')->orWhere('stage_id', $this->id('application_stage', 'offer')))->count();
    }

    public function requestSelection(Applicant $a, User $by): ApprovalRequest
    {
        if (!in_array($this->stageCode($a), ['interview', 'assessment'], true)) {
            $this->fail('applicant', 'stage_not_allowed');
        }
        if (!Scorecard::whereIn('interview_id', Interview::where('applicant_id', $a->id)->select('id'))->exists()) {
            $this->fail('applicant', 'scorecard_required');
        }
        $pending = ApprovalRequest::where('subject_type_id', $this->id('approval_subject_type', 'applicant_selection'))->where('subject_id', $a->id)
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['pending', 'returned']))->exists();
        if ($pending) {
            $this->fail('applicant', 'selection_pending');
        }
        $v = $a->vacancy()->with('requisition')->first();
        if ($this->taken($v) >= $v->requisition->headcount) {
            $this->fail('applicant', 'headcount_reached');
        }
        AuditLogger::log('applicant.selection_requested', $a);
        return $this->approvals->submit('applicant_selection', $a->id, $by, __('recruitment.approval_title.selection', ['name' => "$a->first_name $a->last_name", 'title' => $v->title]), ['department_id' => $v->requisition->department_id]);
    }

    public function selectionOutcome(string $applicantId, string $outcome): void
    {
        $a = Applicant::withoutGlobalScopes()->find($applicantId);
        if (!$a || !in_array($this->stageCode($a), ['interview', 'assessment'], true)) {
            return;
        }
        if ($outcome === 'approved') {
            $v = $a->vacancy()->withoutGlobalScopes()->with('requisition')->first();
            if ($this->taken($v) >= $v->requisition->headcount) {                 // another selection filled the last place meanwhile
                AuditLogger::log('applicant.selection_blocked', $a, [], ['reason' => 'headcount_reached']);
                return;
            }
            $this->setStage($a, 'offer', __('recruitment.selection_approved'), null);
        }
        AuditLogger::log("applicant.selection_$outcome", $a);
    }

    // ---- offers -----------------------------------------------------------------------------------------------------------
    /** Salary against the grade's pay band. Returns true when outside the band (an exception reason is then mandatory). */
    private function outsideBand(array $d): bool
    {
        if (empty($d['grade_id'])) {
            return false;
        }
        $g = \App\Models\HrGrade::find($d['grade_id']);
        if (!$g || ($g->currency_code && $g->currency_code !== $d['salary_currency'])) {
            return false;
        }
        $amount = MoneyService::round((string) $d['salary_amount'], 2);
        return ($g->salary_min !== null && MoneyService::compare($amount, (string) $g->salary_min) < 0) || ($g->salary_max !== null && MoneyService::compare($amount, (string) $g->salary_max) > 0);
    }

    public function createOffer(Applicant $a, array $d, User $by): Offer
    {
        if ($this->stageCode($a) !== 'offer') {
            $this->fail('applicant', 'not_selected');
        }
        $active = array_map(fn ($c) => $this->id('offer_status', $c), ['draft', 'issued', 'accepted']);
        if (Offer::where('applicant_id', $a->id)->whereIn('status_id', $active)->exists()) {
            $this->fail('applicant', 'offer_exists');
        }
        $outside = $this->outsideBand($d);
        if ($outside && blank($d['band_exception_reason'] ?? null)) {
            $this->fail('salary_amount', 'outside_band');
        }
        $o = Offer::create(['applicant_id' => $a->id, 'status_id' => $this->id('offer_status', 'draft'), 'position_id' => $d['position_id'] ?? null, 'grade_id' => $d['grade_id'] ?? null,
            'department_id' => $d['department_id'] ?? null, 'category_id' => $d['category_id'], 'contract_type_id' => $d['contract_type_id'] ?? null, 'start_date' => $d['start_date'],
            'probation_months' => $d['probation_months'] ?? 0, 'contract_end_on' => $d['contract_end_on'] ?? null, 'salary_amount' => MoneyService::round((string) $d['salary_amount'], 2),
            'salary_currency' => $d['salary_currency'], 'salary_period' => $d['salary_period'] ?? 'monthly', 'terms' => $d['terms'] ?? null, 'band_exception_reason' => $outside ? $d['band_exception_reason'] : null, 'created_by' => $by->id]);
        AuditLogger::log('offer.created', $o, [], ['outside_band' => $outside]);
        return $o;
    }

    public function issueOffer(Offer $o, ?string $validUntil): void
    {
        if ($o->loadMissing('status')->status->code !== 'draft') {
            $this->fail('offer', 'offer_not_draft');
        }
        $o->update(['status_id' => $this->id('offer_status', 'issued'), 'issued_at' => now(),
            'valid_until' => $validUntil ?? now()->addDays(HrSettings::get($o->organization_id, 'offer_validity_days'))->toDateString()]);
        AuditLogger::log('offer.issued', $o);
    }

    public function respond(Offer $o, string $decision, string $on, User $by): void
    {
        if ($o->loadMissing('status')->status->code !== 'issued') {
            $this->fail('offer', 'offer_not_issued');
        }
        if ($o->valid_until && $on > $o->valid_until->toDateString()) {
            $this->fail('responded_on', 'offer_expired');
        }
        $o->update(['status_id' => $this->id('offer_status', $decision), 'responded_on' => $on]);
        AuditLogger::log("offer.$decision", $o);
        if ($decision === 'declined') {
            $this->reject($o->applicant()->first(), __('recruitment.offer_declined'), $by);
        }
    }

    public function withdrawOffer(Offer $o, User $by): void
    {
        $status = $o->loadMissing('status')->status->code;
        $a = $o->applicant()->first();
        if (!in_array($status, ['draft', 'issued', 'accepted'], true) || $a->converted_employee_id) {
            $this->fail('offer', 'cannot_withdraw');
        }
        $this->reject($a, __('recruitment.offer_withdrawn'), $by);                 // also marks the offer withdrawn
    }

    // ---- conversion ------------------------------------------------------------------------------------------------------
    /** One click: accepted offer -> employee (hire row with salary), onboarding checklist, applicant closed as hired. */
    public function convert(Applicant $a, array $d, User $by): Employee
    {
        if ($a->converted_employee_id) {
            $this->fail('applicant', 'already_converted');
        }
        if ($this->stageCode($a) !== 'offer') {
            $this->fail('applicant', 'not_selected');
        }
        $o = Offer::with('position')->where('applicant_id', $a->id)->where('status_id', $this->id('offer_status', 'accepted'))->first();
        if (!$o) {
            $this->fail('applicant', 'offer_not_accepted');
        }
        return DB::transaction(function () use ($a, $d, $by, $o) {
            $start = CarbonImmutable::parse($o->start_date->toDateString());
            $extras = ['salary_amount' => $o->salary_amount, 'salary_currency' => $o->salary_currency, 'contract_type_id' => $o->contract_type_id, 'reason' => __('recruitment.hired_reason', ['code' => $a->code])];
            $e = EmploymentService::withHireExtras($extras, fn () => Employee::create([
                'first_name' => $a->first_name, 'last_name' => $a->last_name, 'email' => $a->email, 'phone' => $a->phone ? mb_substr($a->phone, 0, 40) : null,
                'category_id' => $o->category_id, 'status_id' => $this->id('employee_status', 'active'), 'department_id' => $o->department_id, 'manager_employee_id' => $d['manager_employee_id'] ?? null,
                'position_title' => $o->position?->title, 'position_id' => $o->position_id, 'grade_id' => $o->grade_id, 'hired_on' => $start,
                'probation_end_on' => $o->probation_months > 0 ? $start->addMonthsNoOverflow($o->probation_months)->toDateString() : null, 'contract_end_on' => $o->contract_end_on?->toDateString(),
            ]));
            $a->update(['converted_employee_id' => $e->id, 'retention_until' => null, 'retention_flagged_at' => null]);
            $this->setStage($a, 'hired', null, $by);
            foreach (OnboardingTemplateItem::where('is_active', true)->orderBy('sort_order')->get() as $t) {
                OnboardingItem::create(['employee_id' => $e->id, 'title' => $t->title, 'due_on' => $start->addDays($t->offset_days)->toDateString()]);
            }
            $v = $a->vacancy()->with('requisition')->first();
            if (Applicant::where('vacancy_id', $v->id)->whereNotNull('converted_employee_id')->count() >= $v->requisition->headcount) {
                $v->requisition->update(['status_id' => $this->id('requisition_status', 'filled')]);
                $this->closeVacancy($v);
            }
            AuditLogger::log('applicant.converted', $a, [], ['employee_id' => $e->id]);
            return $e;
        });
    }

    // ---- personal data retention (reviewed, never automatic deletion) ------------------------------------------------------
    /** Flags each applicant record whose retention date has passed, once, and tells HR. Nothing is deleted here. */
    public function retentionReview(?string $today = null): int
    {
        $today ??= now()->toDateString();
        $due = Applicant::withoutGlobalScopes()->whereNull('anonymized_at')->whereNull('converted_employee_id')->whereNull('retention_flagged_at')
            ->whereNotNull('retention_until')->whereDate('retention_until', '<=', $today)->get();
        foreach ($due->groupBy('organization_id') as $org => $rows) {
            $roleIds = Role::withoutGlobalScopes()->where('organization_id', $org)->whereHas('permissions', fn ($q) => $q->where('code', 'hr.recruitment.manage'))->pluck('id');
            $userIds = Membership::where('organization_id', $org)->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))->pluck('user_id')->unique();
            foreach (User::with('language')->whereIn('id', $userIds)->get() as $u) {
                $locale = $u->language?->code ?? config('ops.default_language');
                AppNotification::create(['organization_id' => $org, 'user_id' => $u->id, 'title' => __('recruitment.retention.title', [], $locale),
                    'body' => __('recruitment.retention.body', ['count' => $rows->count()], $locale), 'link' => '/hr/recruitment/retention']);
            }
            Applicant::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->update(['retention_flagged_at' => now()]);
        }
        return $due->count();
    }

    public function extendRetention(Applicant $a, string $until, string $reason, User $by): void
    {
        if ($until <= now()->toDateString()) {
            $this->fail('until', 'retention_date_future');
        }
        $a->update(['retention_until' => $until, 'retention_flagged_at' => null]);
        AuditLogger::log('applicant.retention_extended', $a, [], ['until' => $until, 'reason' => $reason]);
    }

    /** Erase the personal data of a closed application. Stage history and scores stay for statistics, without identity. */
    public function anonymize(Applicant $a, User $by): void
    {
        if ($a->converted_employee_id) {
            $this->fail('applicant', 'anonymize_hired');
        }
        $closed = $this->stageCode($a) === 'rejected' || ($a->retention_until && $a->retention_until->toDateString() <= now()->toDateString());
        if (!$closed) {
            $this->fail('applicant', 'anonymize_not_allowed');
        }
        DB::transaction(function () use ($a) {
            $a->update(['first_name' => 'Anonymised', 'last_name' => '-', 'email' => 'anonymised-' . strtolower($a->id) . '@invalid.local', 'phone' => null, 'notes' => null,
                'rejection_reason' => null, 'source' => null, 'anonymized_at' => now(), 'retention_until' => null]);
            ReferenceCheck::where('applicant_id', $a->id)->delete();
            Scorecard::whereIn('interview_id', Interview::where('applicant_id', $a->id)->select('id'))->update(['strengths' => null, 'concerns' => null]);
            Interview::where('applicant_id', $a->id)->update(['notes' => null, 'location' => null]);
            Offer::where('applicant_id', $a->id)->update(['terms' => null, 'band_exception_reason' => null]);
            ApplicantStageHistory::where('applicant_id', $a->id)->update(['note' => null]);
        });
        AuditLogger::log('applicant.anonymized', $a);
    }
}
