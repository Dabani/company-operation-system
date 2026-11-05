<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApprovalRequestResource;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    use AppliesListQuery;

    public function __construct(private ApprovalEngine $engine) {}

    private function with(): array { return ['flow.steps', 'subjectType', 'status', 'requester']; }

    /** Requests waiting for the signed-in user's decision. perm: approval.act */
    public function inbox(Request $request)
    {
        return ApprovalRequestResource::collection($this->engine->inbox($request->user()));
    }

    /** Requests the signed-in user raised. perm: approval.view */
    public function mine(Request $request)
    {
        return $this->respondList($request, ApprovalRequest::with($this->with())->where('requester_id', $request->user()->id),
            ApprovalRequestResource::class, ['title'], ['submitted_at', 'title'], ['status_id', 'subject_type_id']);
    }

    /** Oversight list. perm: approval.view_all */
    public function index(Request $request)
    {
        return $this->respondList($request, ApprovalRequest::with($this->with()), ApprovalRequestResource::class,
            ['title'], ['submitted_at', 'title'], ['status_id', 'subject_type_id', 'requester_id']);
    }

    public function show(Request $request, string $id)
    {
        $r = ApprovalRequest::with($this->with() + [4 => 'actions.actor', 5 => 'actions.decision', 6 => 'actions.onBehalfOf'])->findOrFail($id);
        $u = $request->user();
        $allowed = $r->requester_id === $u->id
            || app(OrganizationContext::class)->can('approval.view_all')
            || ($r->isPending() && ($s = $r->currentStep()) && $this->engine->approversFor($r, $s)->has($u->id))
            || $r->actions->contains(fn ($a) => $a->actor_id === $u->id);
        abort_unless($allowed, 404);                                  // no existence leak
        return new ApprovalRequestResource($r);
    }

    public function act(Request $request, string $id)
    {
        $d = $request->validate(['decision' => 'required|in:approve,reject,return', 'comment' => 'nullable|string|max:2000']);
        $r = $this->engine->act($id, $request->user(), $d['decision'], $d['comment'] ?? null);
        return new ApprovalRequestResource($r->load($this->with()));
    }

    public function resubmit(Request $request, string $id)
    {
        $d = $request->validate(['comment' => 'nullable|string|max:2000']);
        return new ApprovalRequestResource($this->engine->resubmit($id, $request->user(), $d['comment'] ?? null)->load($this->with()));
    }

    public function cancel(Request $request, string $id)
    {
        return new ApprovalRequestResource($this->engine->cancel($id, $request->user())->load($this->with()));
    }

    // ---- delegation (a user hands their approvals to a substitute for a period) ------------------------------------------
    public function delegations(Request $request)
    {
        return ApprovalDelegation::with('delegate')->where('delegator_id', $request->user()->id)->orderByDesc('valid_from')->get()
            ->map(fn ($d) => ['id' => $d->id, 'delegate_id' => $d->delegate_id, 'delegate' => $d->delegate?->name, 'valid_from' => $d->valid_from->toDateString(), 'valid_to' => $d->valid_to->toDateString(), 'is_active' => $d->is_active]);
    }

    public function storeDelegation(Request $request)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $d = $request->validate([
            'delegate_id' => ['required', \Illuminate\Validation\Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'valid_from' => 'required|date', 'valid_to' => 'required|date|after_or_equal:valid_from',
        ]);
        abort_if($d['delegate_id'] === $request->user()->id, 422);
        $m = ApprovalDelegation::create($d + ['delegator_id' => $request->user()->id, 'is_active' => true]);
        AuditLogger::log('approval.delegation_created', $m, [], $d);
        return response()->json(['id' => $m->id], 201);
    }

    public function destroyDelegation(Request $request, string $id)
    {
        $m = ApprovalDelegation::where('delegator_id', $request->user()->id)->findOrFail($id);
        $m->update(['is_active' => false]);
        AuditLogger::log('approval.delegation_revoked', $m);
        return response()->noContent();
    }
}
