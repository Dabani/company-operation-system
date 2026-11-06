<?php

namespace App\Services\Approvals;

use App\Events\ApprovalFinalised;
use App\Models\ApprovalAction;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserDepartmentAssignment;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Services\Rules\RuleResolver;
use App\Support\Lookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One reusable approval engine for every subject type (Guide 3.1). Sequential steps resolved by role, department head,
 * line manager or named user; delegation; escalation; immutable decisions; transaction-safe, server-validated, audited.
 * Mandatory safeguards: nobody approves their own request, and nobody approves two different steps of one request.
 */
class ApprovalEngine
{
    public function __construct(private RuleResolver $rules) {}

    // ---------------------------------------------------------------------------------------------------------------------
    public function submit(string $subjectType, string $subjectId, User $requester, string $title, array $opts = []): ApprovalRequest
    {
        $orgId = app(OrganizationContext::class)->organizationId();
        $typeId = Lookup::id('approval_subject_type', $subjectType);

        if (ApprovalRequest::where('subject_type_id', $typeId)->where('subject_id', $subjectId)
            ->where('status_id', Lookup::id('approval_status', 'pending'))->exists()) {
            $this->fail('already_pending');
        }

        $attrs = array_filter([
            'subject_type' => $subjectType, 'amount' => $opts['amount'] ?? null, 'department' => $opts['department_id'] ?? null,
        ] + ($opts['attributes'] ?? []), fn ($v) => $v !== null && $v !== '');

        $resolved = $this->rules->resolve('approval', $attrs, null, null, $orgId);
        $flow = $this->usableFlow($resolved?->get('approval_flow'))
            ?? $this->flowForSubject($typeId)
            ?? $this->defaultFlow();
        $usedDefault = $resolved === null && $flow->code === 'default_two_level';     // no rule applied -> safe default: explicit approval

        return DB::transaction(function () use ($orgId, $typeId, $subjectId, $requester, $title, $opts, $flow, $resolved, $usedDefault) {
            $now = now();
            $req = ApprovalRequest::create([
                'organization_id' => $orgId, 'flow_id' => $flow->id, 'subject_type_id' => $typeId, 'subject_id' => $subjectId,
                'requester_id' => $requester->id, 'department_id' => $opts['department_id'] ?? null, 'title' => mb_substr($title, 0, 250),
                'amount' => $opts['amount'] ?? null, 'currency_code' => $opts['currency'] ?? null,
                'status_id' => Lookup::id('approval_status', 'pending'), 'current_step' => 1,
                'rule_version_id' => $resolved?->versionId, 'used_default_flow' => $usedDefault,
                'submitted_at' => $now, 'step_started_at' => $now, 'requester_comment' => $opts['comment'] ?? null,
            ]);
            $req->load(['flow.steps', 'subjectType']);
            $first = $this->approversFor($req, $req->currentStep());
            if ($first->isEmpty()) {
                $this->fail('no_approver');
            }
            AuditLogger::log('approval.submitted', $req, [], ['flow' => $flow->code, 'rule_version_id' => $resolved?->versionId, 'default_flow' => $usedDefault]);
            $this->notify($first, 'approval_requested', $req, ['requester' => $requester->name]);
            return $req;
        });
    }

    public function act(string $requestId, User $actor, string $decision, ?string $comment = null): ApprovalRequest
    {
        abort_unless(in_array($decision, ['approve', 'reject', 'return'], true), 422);
        if ($decision !== 'approve' && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => [__('foundation.approval.comment_required')]]);
        }

        $outcome = null;
        $req = DB::transaction(function () use ($requestId, $actor, $decision, $comment, &$outcome) {
            $r = ApprovalRequest::lockForUpdate()->with(['flow.steps', 'subjectType', 'status', 'requester'])->findOrFail($requestId);
            if (!$r->isPending()) $this->fail('not_pending');
            if ($r->requester_id === $actor->id) $this->fail('self_approval');

            $step = $r->currentStep();
            $eligible = $this->approversFor($r, $step);
            abort_unless($eligible->has($actor->id), 403, 'forbidden');

            if ($decision === 'approve') {
                $earlier = ApprovalAction::where('request_id', $r->id)->where('step_order', '!=', $step->step_order)
                    ->whereHas('decision', fn ($q) => $q->where('code', 'approve'))
                    ->where(fn ($q) => $q->where('actor_id', $actor->id)->orWhere('on_behalf_of_id', $actor->id))->exists();
                if ($earlier) $this->fail('segregation');
            }

            ApprovalAction::create([
                'organization_id' => $r->organization_id, 'request_id' => $r->id, 'step_order' => $step->step_order,
                'actor_id' => $actor->id, 'on_behalf_of_id' => $eligible[$actor->id]['on_behalf_of'],
                'decision_id' => Lookup::id('approval_decision', $decision), 'comment' => $comment,
                'rule_version_number' => $r->ruleVersion?->version, 'acted_at' => now(),
            ]);

            if ($decision === 'approve') {
                $next = $r->flow->steps->firstWhere('step_order', '>', $step->step_order);
                if ($next) {
                    $r->update(['current_step' => $next->step_order, 'step_started_at' => now()]);
                    $approvers = $this->approversFor($r->refresh()->load('flow.steps'), $next);
                    if ($approvers->isEmpty()) $this->fail('no_approver');
                    $this->notify($approvers, 'approval_requested', $r, ['requester' => $r->requester->name]);
                } else {
                    $r->update(['status_id' => Lookup::id('approval_status', 'approved'), 'completed_at' => now()]);
                    $outcome = 'approved';
                }
            } else {
                $code = $decision === 'reject' ? 'rejected' : 'returned';
                $r->update(['status_id' => Lookup::id('approval_status', $code), 'completed_at' => $decision === 'reject' ? now() : null]);
                $outcome = $code;
            }
            AuditLogger::log("approval.$decision", $r, [], ['step' => $step->step_order, 'comment_given' => $comment !== null && $comment !== '']);
            return $r;
        });

        if ($outcome) {
            $key = ['approved' => 'approval_approved', 'rejected' => 'approval_rejected', 'returned' => 'approval_returned'][$outcome];
            Notifier::send($req->requester, $key, ['title' => $req->title, 'actor' => $actor->name, 'comment' => (string) $comment], null, ['in_app', 'email'], '/approvals', $req->organization_id);
            event(new ApprovalFinalised($req->refresh()->load('subjectType'), $outcome));
        }
        return $req->refresh();
    }

    public function resubmit(string $requestId, User $user, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $user, $comment) {
            $r = ApprovalRequest::lockForUpdate()->with(['flow.steps', 'subjectType', 'status'])->findOrFail($requestId);
            abort_unless($r->requester_id === $user->id, 403, 'forbidden');
            if ($r->status->code !== 'returned') $this->fail('not_returned');
            $r->update(['status_id' => Lookup::id('approval_status', 'pending'), 'current_step' => 1, 'step_started_at' => now(), 'requester_comment' => $comment ?? $r->requester_comment]);
            $approvers = $this->approversFor($r->refresh()->load('flow.steps'), $r->flow->steps->first());
            if ($approvers->isEmpty()) $this->fail('no_approver');
            $this->notify($approvers, 'approval_requested', $r, ['requester' => $user->name]);
            AuditLogger::log('approval.resubmitted', $r);
            return $r;
        });
    }

    public function cancel(string $requestId, User $user): ApprovalRequest
    {
        $r = DB::transaction(function () use ($requestId, $user) {
            $r = ApprovalRequest::lockForUpdate()->with(['status', 'subjectType'])->findOrFail($requestId);
            abort_unless($r->requester_id === $user->id, 403, 'forbidden');
            if (!in_array($r->status->code, ['pending', 'returned'], true)) $this->fail('not_pending');
            $r->update(['status_id' => Lookup::id('approval_status', 'cancelled'), 'completed_at' => now()]);
            AuditLogger::log('approval.cancelled', $r);
            return $r;
        });
        event(new ApprovalFinalised($r->refresh()->load('subjectType'), 'cancelled'));
        return $r;
    }

    // ---------------------------------------------------------------------------------------------------------------------
    /** Who may act on this step right now: [userId => ['user' => User, 'on_behalf_of' => ?userId]]. */
    public function approversFor(ApprovalRequest $r, ApprovalStep $step): Collection
    {
        $orgId = $r->organization_id;
        $set = $this->stepUsers($r, $step, $orgId);
        if ($set->filter(fn ($u) => $u->id !== $r->requester_id)->isEmpty()) {
            $set = $this->fallbackUsers($orgId);                       // nobody resolvable -> highest authority level
        }
        $perm = config('cops.approval_permissions.' . $r->subjectType->code);

        $out = collect();
        foreach ($set as $u) {
            $out[$u->id] = ['user' => $u, 'on_behalf_of' => null];
        }
        $today = now()->toDateString();
        $delegations = ApprovalDelegation::where('is_active', true)->whereIn('delegator_id', $set->pluck('id'))
            ->whereDate('valid_from', '<=', $today)->whereDate('valid_to', '>=', $today)->with('delegate')->get();
        foreach ($delegations as $d) {
            if (!$out->has($d->delegate_id) && $d->delegate) {
                $out[$d->delegate_id] = ['user' => $d->delegate, 'on_behalf_of' => $d->delegator_id];
            }
        }
        return $out->filter(fn ($row, $id) => $id !== $r->requester_id && (!$perm || $this->hasPermission($row['user'], $perm, $orgId)));
    }

    /** Pending requests whose current step the user may act on (oldest first). */
    public function inbox(User $user): Collection
    {
        return ApprovalRequest::with(['flow.steps', 'subjectType', 'status', 'requester'])
            ->whereHas('status', fn ($q) => $q->where('code', 'pending'))->where('requester_id', '!=', $user->id)
            ->orderBy('submitted_at')->limit(500)->get()
            ->filter(fn ($r) => ($s = $r->currentStep()) && $this->approversFor($r, $s)->has($user->id))->values();
    }

    /** Reminder + escalation for steps pending longer than their configured hours. Returns the number escalated. */
    public function escalateOverdue(): int
    {
        $n = 0;
        $pending = ApprovalRequest::with(['flow.steps', 'subjectType', 'requester'])->whereHas('status', fn ($q) => $q->where('code', 'pending'))->get();
        foreach ($pending as $r) {
            $step = $r->currentStep();
            if (!$step?->escalate_after_hours || $r->step_started_at->gt(now()->subHours($step->escalate_after_hours))) continue;
            if ($r->last_escalated_at && $r->last_escalated_at->gte($r->step_started_at)) continue;
            $recipients = $this->approversFor($r, $step)->pluck('user')->merge($this->fallbackUsers($r->organization_id))->unique('id')->values();
            $this->notify($recipients, 'approval_escalated', $r, ['requester' => $r->requester->name, 'hours' => $step->escalate_after_hours]);
            Notifier::send($r->requester, 'approval_escalated', ['title' => $r->title, 'requester' => $r->requester->name, 'hours' => $step->escalate_after_hours], null, ['in_app'], '/approvals', $r->organization_id);
            $r->update(['last_escalated_at' => now()]);
            AuditLogger::log('approval.escalated', $r);
            $n++;
        }
        return $n;
    }

    // ---------------------------------------------------------------------------------------------------------------------
    private function stepUsers(ApprovalRequest $r, ApprovalStep $step, string $orgId): Collection
    {
        $type = $step->type->code;
        $active = fn ($q) => $q->whereHas('status', fn ($s) => $s->where('code', 'active'));

        if ($type === 'role' && $step->role_id) {
            return User::query()->tap($active)->whereHas('memberships', fn ($m) => $m->where('organization_id', $orgId)
                ->whereHas('status', fn ($s) => $s->where('code', 'active'))->whereHas('roles', fn ($q) => $q->where('roles.id', $step->role_id)))->get();
        }
        if ($type === 'user' && $step->user_id) {
            return User::query()->tap($active)->whereKey($step->user_id)->get();
        }
        if ($type === 'line_manager') {
            $mgr = Employee::where('user_id', $r->requester_id)->first()?->manager;
            return $mgr?->user_id ? User::query()->tap($active)->whereKey($mgr->user_id)->get() : collect();
        }
        if ($type === 'department_head' && $r->department_id) {
            $parents = Department::pluck('parent_id', 'id');
            for ($dept = $r->department_id, $guard = 0; $dept && $guard < 20; $dept = $parents[$dept] ?? null, $guard++) {
                $ids = UserDepartmentAssignment::where('department_id', $dept)->where('is_head', true)->pluck('user_id');
                $users = $ids->isEmpty() ? collect() : User::query()->tap($active)->whereKey($ids)->get();
                if ($users->isNotEmpty()) return $users;
            }
        }
        if ($resolver = config("cops.step_resolvers.$type")) {
            return app($resolver)->users($r, $step);      // module-defined step types (Phase 1: project_reviewer)
        }
        return collect();
    }

    /** Safe fallback so a request can never be stranded: members holding the organization's highest authority level. */
    private function fallbackUsers(string $orgId): Collection
    {
        $max = DB::table('roles')->where('organization_id', $orgId)->where('is_active', true)->max('level');
        if ($max === null) return collect();
        return User::query()->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('memberships', fn ($m) => $m->where('organization_id', $orgId)->whereHas('roles', fn ($q) => $q->where('roles.level', $max)))->get();
    }

    private function hasPermission(User $u, string $code, string $orgId): bool
    {
        return DB::table('organization_memberships as m')
            ->join('membership_roles as mr', 'mr.membership_id', '=', 'm.id')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('m.user_id', $u->id)->where('m.organization_id', $orgId)->where('r.is_active', true)->where('p.code', $code)->exists();
    }

    private function usableFlow(?string $flowId): ?ApprovalFlow
    {
        if (!$flowId) return null;
        $today = now()->toDateString();
        return ApprovalFlow::where('id', $flowId)->where('is_active', true)->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->has('steps')->first();
    }

    private function flowForSubject(string $typeId): ?ApprovalFlow
    {
        $today = now()->toDateString();
        return ApprovalFlow::where('subject_type_id', $typeId)->where('is_active', true)->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->has('steps')
            ->orderByDesc('version')->first();
    }

    private function defaultFlow(): ApprovalFlow
    {
        $flow = ApprovalFlow::where('code', 'default_two_level')->where('is_active', true)->orderByDesc('version')->first();
        abort_unless($flow, 500, 'default approval flow missing - run CopsFoundationSeeder');
        return $flow;
    }

    private function notify(Collection $approvers, string $key, ApprovalRequest $r, array $params = []): void
    {
        $users = $approvers->map(fn ($a) => is_array($a) ? $a['user'] : $a)->values();
        Notifier::send($users, $key, ['title' => $r->title] + $params, null, ['in_app', 'email'], '/approvals', $r->organization_id);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['approval' => [__("foundation.approval.$key")]]);
    }
}
