<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Imports\UsersImport;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * User management. New users are INVITED: an activation e-mail (InvitationService) lets them choose their own password,
 * which also activates the account. Every state change is audited and respects the actor's hierarchy level.
 */
class UserController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx, private InvitationService $invitations) {}

    protected function base()
    {
        return User::query()
            ->whereHas('memberships', fn ($q) => $q->where('organization_id', $this->ctx->organizationId()))
            ->with(['status.translations', 'invitation', 'memberships' => fn ($q) => $q->where('organization_id', $this->ctx->organizationId())->with(['department', 'roles'])]);
    }

    private function statusIs($q, string $code)
    {
        return $q->whereHas('status', fn ($s) => $s->where('code', $code));
    }

    /** Quick views used by the summary chips: active | suspended | invited | expired | never | dormant */
    private function quick($q, ?string $quick): void
    {
        match ($quick) {
            'active' => $this->statusIs($q, 'active'),
            'suspended' => $this->statusIs($q, 'suspended'),
            'invited' => $this->statusIs($q, 'invited'),
            'expired' => $this->statusIs($q, 'invited')->whereDoesntHave('invitations', fn ($i) => $i->whereNull('accepted_at')->where('expires_at', '>', now())),
            'never' => $this->statusIs($q, 'active')->whereNull('last_login_at'),
            'dormant' => $this->statusIs($q, 'active')->where('last_login_at', '<', now()->subDays(90)),
            default => null,
        };
    }

    public function index(Request $request)
    {
        $q = $this->base();
        $this->quick($q, $request->query('quick'));
        $org = $this->ctx->organizationId();
        if ($role = $request->input('filter.role_id')) {
            $q->whereHas('memberships', fn ($m) => $m->where('organization_id', $org)->whereHas('roles', fn ($r) => $r->where('roles.id', $role)));
        }
        if ($dept = $request->input('filter.department_id')) {
            $q->whereHas('memberships', fn ($m) => $m->where('organization_id', $org)->where('department_id', $dept));
        }

        return $this->respondList($request, $q, UserResource::class,
            ['name', 'email', 'phone'], ['name', 'email', 'created_at', 'last_login_at'], ['status_id'],
            ['name', 'email', 'phone', 'status_label', 'department', 'job_title', 'last_login_at']);
    }

    /** Counts for the chips above the list. */
    public function summary()
    {
        $count = fn (?string $quick = null, ?string $status = null) => tap($this->base()->withoutEagerLoads(), function ($q) use ($quick, $status) {
            if ($status) $this->statusIs($q, $status);
            $this->quick($q, $quick);
        })->count();

        return response()->json(['data' => [
            'total' => $count(), 'active' => $count(null, 'active'), 'invited' => $count('invited'), 'invitation_expired' => $count('expired'),
            'suspended' => $count(null, 'suspended'), 'never_signed_in' => $count('never'), 'dormant' => $count('dormant'),
        ]]);
    }

    public function show(string $id)
    {
        return new UserResource($this->base()->findOrFail($id));
    }

    /** Recent audit entries about this person (as subject or actor). Route needs audit_log.view. */
    public function activity(string $id)
    {
        $user = $this->base()->findOrFail($id);
        $rows = AuditLog::query()->where('organization_id', $this->ctx->organizationId())
            ->where(fn ($q) => $q->where(fn ($s) => $s->where('entity_type', 'User')->where('entity_id', $user->id))->orWhere('user_id', $user->id))
            ->with('outcome.translations')->latest('created_at')->limit(25)->get();

        return response()->json(['data' => $rows->map(fn ($l) => [
            'id' => $l->id, 'action' => $l->action, 'created_at' => $l->created_at->toIso8601String(), 'ip_address' => $l->ip_address,
            'as_actor' => $l->user_id === $user->id, 'outcome' => $l->outcome?->code,
        ])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $roles = $this->assignableRoles($data['role_ids']);

        $user = DB::transaction(function () use ($data, $roles) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'language_id' => $data['language_id'] ?? null,
                'password' => Str::random(40),                      // never known; the user chooses their own on activation
                'status_id' => Lookup::id('user_status', 'invited'),
                'must_change_password' => true,
            ]);
            $this->attachMembership($user, $data, $roles);
            return $user;
        });

        [, $sent] = $this->invitations->issue($user->load('language'), $request->user(), $this->ctx->organizationId());
        AuditLogger::log('user.created', $user, [], ['name' => $user->name, 'email' => $user->email, 'roles' => $roles->pluck('code')->implode(',')]);
        AuditLogger::log($sent ? 'user.invitation_sent' : 'user.invitation_failed', $user, outcome: $sent ? 'success' : 'failure');

        return (new UserResource($this->base()->findOrFail($user->id)))->additional(['invitation_sent' => $sent])->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $user = $this->base()->findOrFail($id);
        $this->guardHierarchy($user);
        $data = $this->validated($request, $user);
        $roles = $this->assignableRoles($data['role_ids']);

        $old = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'roles' => $user->memberships->first()?->roles->pluck('code')->implode(',')];
        $emailChanged = $user->email !== $data['email'];

        DB::transaction(function () use ($user, $data, $roles) {
            $user->update(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'language_id' => $data['language_id'] ?? null]);
            $this->attachMembership($user, $data, $roles);
        });
        $sent = null;
        if ($emailChanged && $user->status?->code === 'invited') {          // the old link went to the old address: replace it
            [, $sent] = $this->invitations->issue($user->load('language'), $request->user(), $this->ctx->organizationId());
        }
        AuditLogger::log('user.updated', $user, $old, ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'roles' => $roles->pluck('code')->implode(',')]);
        return (new UserResource($this->base()->findOrFail($id)))->additional(['invitation_sent' => $sent]);
    }

    public function setStatus(Request $request, string $id)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended', 'reason' => 'nullable|string|max:250']);
        $user = $this->base()->findOrFail($id);
        $sent = $this->applyStatus($user, $data['status'], $data['reason'] ?? null);
        return (new UserResource($this->base()->findOrFail($id)))->additional(['invitation_sent' => $sent]);
    }

    /** Send the activation e-mail again (new link, old link stops working). */
    public function invite(Request $request, string $id)
    {
        $user = $this->base()->findOrFail($id);
        $sent = $this->resend($user, $request->user());
        return (new UserResource($this->base()->findOrFail($id)))->additional(['invitation_sent' => $sent]);
    }

    /** Cancel an invitation that was never accepted (the person has no history, so the account is removed). */
    public function destroy(Request $request, string $id)
    {
        $user = $this->base()->findOrFail($id);
        $this->cancel($user, $request->user());
        return response()->json(['message' => __('users.invitation_cancelled')]);
    }

    /** Several users at once. Every user is checked and audited on its own; failures are reported, not fatal. */
    public function bulk(Request $request)
    {
        $d = $request->validate([
            'action' => 'required|in:suspend,activate,resend_invite,cancel_invite',
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'string', 'reason' => 'nullable|string|max:250',
        ]);
        $perm = in_array($d['action'], ['suspend', 'activate'], true) ? 'user.suspend' : 'user.create';
        abort_unless($this->ctx->can($perm), 403, __('api.forbidden'));

        $done = 0; $failed = [];
        foreach (array_unique($d['ids']) as $id) {
            $user = $this->base()->find($id);
            if (!$user) { $failed[] = ['id' => $id, 'name' => null, 'reason' => __('users.user_not_found')]; continue; }
            try {
                match ($d['action']) {
                    'suspend' => $this->applyStatus($user, 'suspended', $d['reason'] ?? null),
                    'activate' => $this->applyStatus($user, 'active', $d['reason'] ?? null),
                    'resend_invite' => $this->resend($user, $request->user()),
                    'cancel_invite' => $this->cancel($user, $request->user()),
                };
                $done++;
            } catch (HttpException $e) {
                $failed[] = ['id' => $id, 'name' => $user->name, 'reason' => $e->getMessage()];
            }
        }
        AuditLogger::log('user.bulk_' . $d['action'], null, [], ['requested' => count($d['ids']), 'done' => $done, 'failed' => count($failed)]);
        return response()->json(['done' => $done, 'failed' => $failed, 'message' => __('users.bulk_done', ['count' => $done])]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:5120']);
        $import = new UsersImport($this->ctx);
        Excel::import($import, $request->file('file'));
        AuditLogger::log('user.imported', null, [], ['created' => $import->created, 'skipped' => count($import->errors), 'mail_failed' => $import->mail_failed]);
        return response()->json(['created' => $import->created, 'errors' => $import->errors, 'mail_failed' => $import->mail_failed]);
    }

    // ---- state changes shared by the single and bulk endpoints -------------------------------------

    /** @return ?bool whether an invitation e-mail was sent (only when reactivating someone who never activated) */
    private function applyStatus(User $user, string $target, ?string $reason): ?bool
    {
        abort_if($user->id === request()->user()->id, 422, __('api.cannot_self_suspend'));
        $this->guardHierarchy($user);

        $old = $user->status?->code;
        if ($old === $target) return null;
        $sent = null;

        if ($target === 'suspended') {
            $user->update(['status_id' => Lookup::id('user_status', 'suspended')]);
            DB::table('sessions')->where('user_id', $user->id)->delete();      // terminate live sessions
            $user->tokens()->delete();
            $this->invitations->revoke($user);
        } elseif (!$user->email_verified_at && !$user->last_login_at) {
            // Never activated: going back to "active" would give an account nobody can open. Invite again instead.
            $user->update(['status_id' => Lookup::id('user_status', 'invited'), 'must_change_password' => true]);
            [, $sent] = $this->invitations->issue($user->load('language'), request()->user(), $this->ctx->organizationId());
            $target = 'invited';
        } else {
            $user->update(['status_id' => Lookup::id('user_status', 'active')]);
        }
        AuditLogger::log('user.status_changed', $user, ['status' => $old], ['status' => $target], $reason);
        return $sent;
    }

    private function resend(User $user, User $actor): bool
    {
        $this->guardHierarchy($user);
        abort_unless($user->status?->code === 'invited', 422, __('users.not_invited'));
        abort_if($this->invitations->throttled($user), 429, __('users.resend_wait'));
        [, $sent] = $this->invitations->issue($user->load('language'), $actor, $this->ctx->organizationId());
        AuditLogger::log($sent ? 'user.invitation_resent' : 'user.invitation_failed', $user, outcome: $sent ? 'success' : 'failure');
        return $sent;
    }

    private function cancel(User $user, User $actor): void
    {
        $this->guardHierarchy($user);
        abort_if($user->id === $actor->id, 422, __('api.cannot_self_suspend'));
        abort_unless($user->status?->code === 'invited' && !$user->email_verified_at && !$user->last_login_at, 422, __('users.cannot_remove'));

        $snapshot = ['name' => $user->name, 'email' => $user->email];
        try {
            DB::transaction(function () use ($user) {
                foreach ($user->memberships as $m) { $m->roles()->detach(); $m->delete(); }
                $user->invitations()->delete();
                $user->forceDelete();
            });
        } catch (\Throwable $e) {
            report($e);
            abort(422, __('users.cannot_remove'));
        }
        AuditLogger::log('user.invitation_cancelled', null, [], $snapshot);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);   // one spelling per address
        return $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:190', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => 'nullable|string|max:40',
            'language_id' => 'nullable|exists:languages,id',
            'department_id' => 'nullable|exists:departments,id',
            'job_title' => 'nullable|string|max:150',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'string|exists:roles,id',
        ]);
    }

    /** Only roles of the active organization, with level <= the actor's level. */
    private function assignableRoles(array $ids)
    {
        $roles = Role::whereIn('id', $ids)->where('is_active', true)->get();   // org scope via trait
        abort_unless($roles->count() === count(array_unique($ids)), 422, __('api.invalid_role'));
        abort_if($roles->max('level') > $this->ctx->level(), 403, __('api.role_above_authority'));
        return $roles;
    }

    private function guardHierarchy(User $target): void
    {
        $targetLevel = (int) $target->memberships->first()?->roles->max('level');
        abort_if($targetLevel > $this->ctx->level(), 403, __('api.user_above_authority'));
    }

    private function attachMembership(User $user, array $data, $roles): void
    {
        $m = Membership::firstOrNew(['organization_id' => $this->ctx->organizationId(), 'user_id' => $user->id]);
        $m->fill([
            'department_id' => $data['department_id'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'status_id' => $m->status_id ?? Lookup::id('membership_status', 'active'),
            'is_default' => $m->exists ? $m->is_default : !Membership::where('user_id', $user->id)->exists(),
        ])->save();
        $m->roles()->sync($roles->mapWithKeys(fn ($r) => [$r->id => ['assigned_by' => request()->user()->id]])->all());
    }
}
