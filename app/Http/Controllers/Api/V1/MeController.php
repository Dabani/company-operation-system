<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function show(Request $request, OrganizationContext $ctx)
    {
        $user = $request->user()->load('language');
        $memberships = Membership::with('organization')->where('user_id', $user->id)->get();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'has_avatar' => (bool) $user->avatar_path,
            'must_change_password' => $user->must_change_password,
            'language' => $user->language?->code ?? $ctx->organization()->defaultLanguage?->code,
            'organization' => new OrganizationResource($ctx->organization()->load(['timezone', 'currency', 'dateFormat'])),
            'organizations' => $memberships->map(fn ($m) => ['id' => $m->organization_id, 'name' => $m->organization->name]),
            'roles' => $ctx->membership()->roles->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'level' => $r->level]),
            'level' => $ctx->level(),
            'permissions' => $ctx->permissions(),
        ]]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'phone' => 'nullable|string|max:40',
            'language_id' => 'nullable|exists:languages,id',
        ]);
        $old = $user->only(array_keys($data));
        $user->update($data);
        AuditLogger::log('profile.updated', $user, $old, $data);
        return $this->show($request, app(OrganizationContext::class));
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->symbols()],
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => [__('api.invalid_credentials')]]);
        }
        $user->update(['password' => $data['password'], 'must_change_password' => false]);
        AuditLogger::log('profile.password_changed', $user);
        return response()->json(['message' => __('api.saved')]);
    }

    public function avatar(Request $request)
    {
        $request->validate(['avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
        }
        $path = $request->file('avatar')->store('avatars', 'local');   // private disk, served via authenticated route
        $user->update(['avatar_path' => $path]);
        AuditLogger::log('profile.avatar_changed', $user);
        return response()->json(['message' => __('api.saved')]);
    }

    public function avatarFile(User $user)
    {
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);
        return Storage::disk('local')->response($user->avatar_path);
    }

    public function switchOrganization(Request $request)
    {
        $request->validate(['organization_id' => 'required|string']);
        $ok = Membership::where('user_id', $request->user()->id)->where('organization_id', $request->organization_id)->exists();
        abort_unless($ok, 403);
        $request->session()->put('active_organization_id', $request->organization_id);
        return response()->json(['message' => __('api.saved')]);
    }
}
