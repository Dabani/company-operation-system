<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Imports\UsersImport;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    protected function base()
    {
        return User::query()
            ->whereHas('memberships', fn ($q) => $q->where('organization_id', $this->ctx->organizationId()))
            ->with(['status.translations', 'memberships' => fn ($q) => $q->where('organization_id', $this->ctx->organizationId())->with(['department', 'roles'])]);
    }

    public function index(Request $request)
    {
        return $this->respondList($request, $this->base(), UserResource::class,
            ['name', 'email', 'phone'], ['name', 'email', 'created_at', 'last_login_at'], ['status_id'],
            ['name', 'email', 'phone', 'status_label', 'department', 'job_title', 'last_login_at']);
    }

    public function show(string $id)
    {
        return new UserResource($this->base()->findOrFail($id));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $roles = $this->assignableRoles($data['role_ids']);

        $user = DB::transaction(function () use ($data, $roles) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'language_id' => $data['language_id'] ?? null,
                'password' => Str::random(40),                      // never known; user sets own via reset link
                'status_id' => Lookup::id('user_status', 'invited'),
                'must_change_password' => true,
            ]);
            $this->attachMembership($user, $data, $roles);
            return $user;
        });

        try { Password::sendResetLink(['email' => $user->email]); } catch (\Throwable $e) { report($e); }
        AuditLogger::log('user.created', $user, [], ['name' => $user->name, 'email' => $user->email, 'roles' => $roles->pluck('code')->implode(',')]);
        return (new UserResource($this->base()->findOrFail($user->id)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $user = $this->base()->findOrFail($id);
        $this->guardHierarchy($user);
        $data = $this->validated($request, $user);
        $roles = $this->assignableRoles($data['role_ids']);

        $old = ['name' => $user->name, 'phone' => $user->phone, 'roles' => $user->memberships->first()?->roles->pluck('code')->implode(',')];
        DB::transaction(function () use ($user, $data, $roles) {
            $user->update(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'language_id' => $data['language_id'] ?? null]);
            $this->attachMembership($user, $data, $roles);
        });
        AuditLogger::log('user.updated', $user, $old, ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'roles' => $roles->pluck('code')->implode(',')]);
        return new UserResource($this->base()->findOrFail($id));
    }

    public function setStatus(Request $request, string $id)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended', 'reason' => 'nullable|string|max:250']);
        $user = $this->base()->findOrFail($id);
        abort_if($user->id === $request->user()->id, 422, __('api.cannot_self_suspend'));
        $this->guardHierarchy($user);

        $old = ['status' => $user->status->code];
        $user->update(['status_id' => Lookup::id('user_status', $data['status'])]);
        if ($data['status'] === 'suspended') {
            DB::table('sessions')->where('user_id', $user->id)->delete();     // terminate live sessions
            $user->tokens()->delete();
        }
        AuditLogger::log('user.status_changed', $user, $old, ['status' => $data['status']], $data['reason'] ?? null);
        return new UserResource($this->base()->findOrFail($id));
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:5120']);
        $import = new UsersImport($this->ctx);
        Excel::import($import, $request->file('file'));
        AuditLogger::log('user.imported', null, [], ['created' => $import->created, 'skipped' => count($import->errors)]);
        return response()->json(['created' => $import->created, 'errors' => $import->errors]);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function validated(Request $request, ?User $user = null): array
    {
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
