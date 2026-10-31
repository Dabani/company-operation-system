<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use App\Support\Lookup;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $user = User::with('status')->where('email', Str::lower(trim($data['email'])))->first();
        $ok = $user && $user->status?->code === 'active' && Hash::check($data['password'], $user->password);

        if (!$ok) {
            // Same message for unknown user, wrong password or inactive account (no enumeration).
            if ($user) {
                $request->setUserResolver(fn () => $user);
                AuditLogger::log('auth.login_failed', $user, outcome: 'failure');
            }
            throw ValidationException::withMessages(['email' => [__('api.invalid_credentials')]]);
        }

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('auth.login', $user);

        return response()->json(['message' => __('api.logged_in')]);
    }

    public function logout(Request $request)
    {
        AuditLogger::log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => __('api.logged_out')]);
    }

    public function forgotPassword(Request $request)
    {
        $email = Str::lower(trim((string) $request->validate(['email' => 'required|email'])['email']));
        try {
            $user = User::with(['status', 'memberships', 'language'])->where('email', $email)->first();
            $invitations = app(InvitationService::class);
            if ($user?->status?->code === 'invited' && $user->memberships->isNotEmpty() && !$invitations->throttled($user)) {
                // Never activated: the right e-mail is the activation link, not a "reset".
                $invitations->issue($user, null, $user->memberships->first()->organization_id);
            } elseif ($user?->status?->code === 'active') {
                Password::sendResetLink(['email' => $email]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return response()->json(['message' => __('api.reset_link_sent')]);   // never reveal whether the account exists
    }

    public function resetPassword(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', ...PasswordRules::for($request->input('email'))],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $code = $user->status?->code;
            // Suspended accounts cannot be re-opened by e-mail. Accounts still "invited" (links sent before the activation flow
            // existed) are activated here: the link proves control of the mailbox.
            if (!in_array($code, ['active', 'invited'], true)) {
                throw ValidationException::withMessages(['email' => [__('api.reset_failed')]]);
            }
            $fill = ['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false];
            if ($code === 'invited') {
                $fill += ['status_id' => Lookup::id('user_status', 'active'), 'email_verified_at' => $user->email_verified_at ?? now()];
            }
            $user->forceFill($fill)->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();      // other devices must sign in again
            AuditLogger::log($code === 'invited' ? 'user.activated' : 'auth.password_reset', $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__('api.reset_failed')]]);
        }
        return response()->json(['message' => __('api.password_reset')]);
    }

    /** Public: is this invitation link usable? (so the page can say "expired" before the person types a password) */
    public function activationCheck(Request $request)
    {
        $d = $request->validate(['email' => 'required|email', 'token' => 'required|string|max:100']);
        [$inv, $user, $problem] = app(InvitationService::class)->inspect($d['email'], $d['token']);
        if ($problem) {
            return response()->json(['valid' => false, 'reason' => $problem, 'message' => __('users.invitation_' . ($problem === 'invalid' ? 'invalid' : $problem))], 422);
        }
        return response()->json(['valid' => true, 'name' => $user->name, 'expires_at' => $inv->expires_at->toIso8601String()]);
    }

    /** Public: choose a password -> account becomes active and the person is signed in. */
    public function activate(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => 'required|string|max:100',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', ...PasswordRules::for($request->input('email'))],
        ]);

        $svc = app(InvitationService::class);
        [$inv, $user, $problem] = $svc->inspect($data['email'], $data['token']);
        if ($problem) {
            throw ValidationException::withMessages(['email' => [__('users.invitation_' . $problem)]]);
        }
        if (!$user->memberships()->whereHas('status', fn ($s) => $s->where('code', 'active'))->exists()) {
            throw ValidationException::withMessages(['email' => [__('users.no_membership')]]);
        }

        $user = $svc->accept($user, $inv, $data['password']);
        $request->setUserResolver(fn () => $user);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('user.activated', $user);

        return response()->json(['message' => __('users.activated')]);
    }
}
