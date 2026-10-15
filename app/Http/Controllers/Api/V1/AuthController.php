<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $user = User::with('status')->where('email', $data['email'])->first();
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
        $request->validate(['email' => 'required|email']);
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);   // mail not configured yet: still answer neutrally
        }
        return response()->json(['message' => __('api.reset_link_sent')]);   // never reveal whether the account exists
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->letters()->numbers()->symbols()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
            AuditLogger::log('auth.password_reset', $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__('api.reset_failed')]]);
        }
        return response()->json(['message' => __('api.password_reset')]);
    }
}
