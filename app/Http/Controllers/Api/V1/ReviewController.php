<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LookupValue;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Appraisal cycles and performance reviews (Guide 5.2). The reviewer writes, the employee acknowledges; HR sees all (audited). */
class ReviewController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }
    private function mine(Request $r): ?Employee { return Employee::where('user_id', $r->user()->id)->first(); }
    private function codes(string $type): array { return LookupValue::whereNull('organization_id')->whereHas('type', fn ($q) => $q->where('code', $type))->pluck('code')->all(); }

    private function cycleShape(ReviewCycle $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'period_start' => $c->period_start->toDateString(), 'period_end' => $c->period_end->toDateString(), 'status' => $c->status?->code];
    }

    private function shape(PerformanceReview $r): array
    {
        return ['id' => $r->id, 'cycle_id' => $r->cycle_id, 'cycle' => $r->cycle?->name, 'employee_id' => $r->employee_id, 'employee' => $r->employee?->full_name,
            'reviewer_employee_id' => $r->reviewer_employee_id, 'reviewer' => $r->reviewer?->full_name, 'status' => $r->status?->code,
            'rating' => $r->rating?->code, 'rating_level' => $r->rating?->sort_order, 'recommendation' => $r->recommendation?->code,
            'summary' => $r->summary, 'goals' => $r->goals, 'employee_comment' => $r->employee_comment,
            'submitted_at' => $r->submitted_at?->toIso8601String(), 'acknowledged_at' => $r->acknowledged_at?->toIso8601String()];
    }

    private function load(string $id): PerformanceReview
    {
        return PerformanceReview::with(['cycle', 'employee', 'reviewer', 'status', 'rating', 'recommendation'])->findOrFail($id);
    }

    private function isReviewer(Request $r, PerformanceReview $p): bool { return $p->reviewer?->user_id !== null && $p->reviewer->user_id === $r->user()->id; }
    private function isSubject(Request $r, PerformanceReview $p): bool { return $p->employee?->user_id !== null && $p->employee->user_id === $r->user()->id; }

    private function visible(Request $r, PerformanceReview $p): bool
    {
        return $this->ctx()->can('hr.reviews.manage') || $this->isReviewer($r, $p) || ($this->isSubject($r, $p) && $p->submitted_at !== null);
    }

    // ---- cycles (HR) ---------------------------------------------------------------------------------------------------
    public function cycles() { return ['data' => ReviewCycle::with('status')->orderByDesc('period_start')->get()->map(fn ($c) => $this->cycleShape($c))]; }

    public function storeCycle(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:150', 'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start']);
        $c = ReviewCycle::create($d + ['status_id' => Lookup::id('review_cycle_status', 'open')]);
        AuditLogger::log('review.cycle_created', $c);
        return response()->json(['data' => $this->cycleShape($c->load('status'))], 201);
    }

    public function closeCycle(string $id)
    {
        $c = ReviewCycle::findOrFail($id);
        $c->update(['status_id' => Lookup::id('review_cycle_status', 'closed')]);
        AuditLogger::log('review.cycle_closed', $c);
        return ['data' => $this->cycleShape($c->load('status'))];
    }

    /** HR assigns reviews: the reviewer defaults to the employee's line manager. Existing assignments are kept. */
    public function assign(Request $request, string $id)
    {
        $c = ReviewCycle::with('status')->findOrFail($id);
        if ($c->status?->code !== 'open') {
            throw ValidationException::withMessages(['cycle' => [__('hr.review.cycle_closed')]]);
        }
        $d = $request->validate(['employee_ids' => 'required|array|min:1|max:500', 'employee_ids.*' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->ctx()->organizationId())]]);
        $created = 0;
        foreach (Employee::whereIn('id', $d['employee_ids'])->get() as $e) {
            $reviewer = $e->manager_employee_id && $e->manager_employee_id !== $e->id ? $e->manager_employee_id : null;
            $r = PerformanceReview::firstOrCreate(['cycle_id' => $c->id, 'employee_id' => $e->id], ['reviewer_employee_id' => $reviewer, 'status_id' => Lookup::id('review_status', 'draft')]);
            $created += $r->wasRecentlyCreated ? 1 : 0;
        }
        AuditLogger::log('review.assigned', $c, [], ['created' => $created]);
        return response()->json(['data' => ['created' => $created]], 201);
    }

    // ---- reviews -------------------------------------------------------------------------------------------------------
    public function index(Request $request)
    {
        $q = PerformanceReview::with(['cycle', 'employee', 'reviewer', 'status', 'rating', 'recommendation'])->orderByDesc('created_at');
        if (!$this->ctx()->can('hr.reviews.manage')) {
            $me = $this->mine($request);
            $q->where(function ($w) use ($me) {
                $w->whereRaw('1 = 0');
                if ($me) {
                    $w->orWhere('reviewer_employee_id', $me->id)->orWhere(fn ($x) => $x->where('employee_id', $me->id)->whereNotNull('submitted_at'));
                }
            });
        }
        if ($c = $request->query('cycle_id')) {
            $q->where('cycle_id', $c);
        }
        return ['data' => $q->limit(500)->get()->map(fn ($r) => $this->shape($r))];
    }

    public function show(Request $request, string $id)
    {
        $p = $this->load($id);
        abort_unless($this->visible($request, $p), 404);
        if ($this->ctx()->can('hr.reviews.manage') && !$this->isReviewer($request, $p) && !$this->isSubject($request, $p)) {
            AuditLogger::log('review.viewed', $p);
        }
        return ['data' => $this->shape($p)];
    }

    /** Reviewer edits the draft. Rating and recommendation are lookup codes. */
    public function update(Request $request, string $id)
    {
        $p = $this->load($id);
        abort_unless($this->isReviewer($request, $p), 404);
        abort_unless($p->status?->code === 'draft' && $p->cycle?->status_id === Lookup::id('review_cycle_status', 'open'), 422);
        $d = $request->validate([
            'rating' => ['nullable', Rule::in($this->codes('performance_rating'))], 'recommendation' => ['nullable', Rule::in($this->codes('review_recommendation'))],
            'summary' => 'nullable|string|max:5000', 'goals' => 'nullable|string|max:5000',
        ]);
        $upd = array_intersect_key($d, array_flip(['summary', 'goals']));
        if (array_key_exists('rating', $d)) {
            $upd['rating_id'] = $d['rating'] ? Lookup::id('performance_rating', $d['rating']) : null;
        }
        if (array_key_exists('recommendation', $d)) {
            $upd['recommendation_id'] = $d['recommendation'] ? Lookup::id('review_recommendation', $d['recommendation']) : null;
        }
        $p->update($upd);
        return ['data' => $this->shape($this->load($id))];
    }

    public function submit(Request $request, string $id)
    {
        $p = $this->load($id);
        abort_unless($this->isReviewer($request, $p), 404);
        abort_unless($p->status?->code === 'draft' && $p->cycle?->status_id === Lookup::id('review_cycle_status', 'open'), 422);
        if (!$p->rating_id) {
            throw ValidationException::withMessages(['rating' => [__('hr.review.rating_required')]]);
        }
        $p->update(['status_id' => Lookup::id('review_status', 'submitted'), 'submitted_at' => now()]);
        AuditLogger::log('review.submitted', $p);
        return ['data' => $this->shape($this->load($id))];
    }

    public function acknowledge(Request $request, string $id)
    {
        $p = $this->load($id);
        abort_unless($this->isSubject($request, $p) && $p->submitted_at !== null, 404);
        abort_unless($p->status?->code === 'submitted', 422);
        $d = $request->validate(['employee_comment' => 'nullable|string|max:3000']);
        $p->update(['status_id' => Lookup::id('review_status', 'acknowledged'), 'acknowledged_at' => now(), 'employee_comment' => $d['employee_comment'] ?? null]);
        AuditLogger::log('review.acknowledged', $p);
        return ['data' => $this->shape($this->load($id))];
    }
}
