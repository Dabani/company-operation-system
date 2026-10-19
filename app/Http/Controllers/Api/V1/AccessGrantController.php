<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccessGrantResource;
use App\Models\AccessGrant;
use App\Models\LookupType;
use App\Models\Membership;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Access Control Register: least-privilege evidence of who has which access, who authorised it and when it ended. */
class AccessGrantController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['user', 'resource.translations', 'level.translations', 'grantedBy', 'revokedBy']; }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    public function index(Request $request)
    {
        $q = AccessGrant::query()->with($this->with());
        match ($request->query('state')) {
            'active' => $q->whereNull('revoked_at')->where(fn ($w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', today())),
            'revoked' => $q->whereNotNull('revoked_at'),
            'expired' => $q->whereNull('revoked_at')->whereDate('expires_on', '<', today()),
            default => null,
        };
        if ($request->boolean('review_due')) {      // not reviewed in the last 12 months
            $q->whereNull('revoked_at')->where(fn ($w) => $w->whereNull('last_reviewed_at')->orWhere('last_reviewed_at', '<', now()->subYear()));
        }
        return $this->respondList($request, $q, AccessGrantResource::class, ['user.name', 'user.email'], ['granted_at', 'expires_on', 'created_at'],
            ['user_id', 'resource_id', 'access_level_id'], ['user_name', 'resource_label', 'level_label', 'granted_by_name', 'granted_at', 'expires_on', 'state', 'revoked_at', 'revocation_reason']);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'user_id' => ['required', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId())],
            'resource_id' => ['required', $this->lookupRule('system_resource')],
            'access_level_id' => ['required', $this->lookupRule('access_level')],
            'description' => 'nullable|string|max:20000',                       // justification (purified)
            'expires_on' => 'nullable|date|after:today',
        ]);
        $dupe = AccessGrant::where('user_id', $d['user_id'])->where('resource_id', $d['resource_id'])->where('access_level_id', $d['access_level_id'])->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))->exists();
        abort_if($dupe, 422, __('api.access_duplicate'));

        $g = AccessGrant::create([
            'user_id' => $d['user_id'], 'resource_id' => $d['resource_id'], 'access_level_id' => $d['access_level_id'],
            'justification' => $d['description'] ?? null, 'granted_by' => $request->user()->id, 'granted_at' => now(), 'expires_on' => $d['expires_on'] ?? null,
        ]);
        $g->load($this->with());
        AuditLogger::log('access.granted', $g, [], ['user' => $g->user->name, 'resource' => $g->resource->code, 'level' => $g->level->code]);
        Notifier::send($g->user->loadMissing('language'), 'access_granted', ['res' => $g->resource->label(), 'level' => $g->level->label(), 'user' => $request->user()->name], null, ['in_app', 'email'], null, $this->ctx->organizationId());
        return (new AccessGrantResource($g))->response()->setStatusCode(201);
    }

    public function revoke(Request $request, string $id)
    {
        $g = AccessGrant::with($this->with())->findOrFail($id);
        abort_if($g->revoked_at, 422, __('api.request_not_pending'));
        $d = $request->validate(['reason' => 'required|string|max:500']);
        $g->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'revocation_reason' => $d['reason']]);
        AuditLogger::log('access.revoked', $g, [], ['user' => $g->user->name, 'resource' => $g->resource->code], $d['reason']);
        Notifier::send($g->user->loadMissing('language'), 'access_revoked', ['res' => $g->resource->label(), 'user' => $request->user()->name], null, ['in_app', 'email'], null, $this->ctx->organizationId());
        return new AccessGrantResource($g->refresh()->load($this->with()));
    }

    /** Periodic recertification: "this access is still needed". */
    public function review(Request $request, string $id)
    {
        $g = AccessGrant::with($this->with())->findOrFail($id);
        abort_if($g->revoked_at, 422);
        $g->update(['last_reviewed_at' => now(), 'reviewed_by' => $request->user()->id]);
        AuditLogger::log('access.reviewed', $g, [], ['user' => $g->user->name, 'resource' => $g->resource->code]);
        return new AccessGrantResource($g->refresh()->load($this->with()));
    }
}
