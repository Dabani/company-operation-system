<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientIdentity;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\Portal\ClientInvitationService;
use App\Services\Portal\PortalMfa;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Portal authentication boundary: password, invitation / reset, and two-factor. Uses the "client" session guard only. */
class PortalAuthController extends Controller
{
    public function __construct(private ClientInvitationService $invitations, private PortalMfa $mfa) {}

    private function org(string $slug): ?Organization { return Organization::where('slug', $slug)->first(); }

    /** "none" (not required), "setup" (must enrol an authenticator) or "verify" (must enter a code) for this session. */
    private function mfaState(Request $request, ClientIdentity $i): string
    {
        if (!PortalMfa::required($i->organization_id)) { $request->session()->put('portal_mfa_ok', $i->getKey()); return 'none'; }
        $request->session()->forget('portal_mfa_ok');
        return $i->hasMfa() ? 'verify' : 'setup';
    }

    public function login(Request $request)
    {
        $d = $request->validate(['organization' => 'required|string|max:120', 'email' => 'required|email', 'password' => 'required|string']);
        $org = $this->org($d['organization']);
        $ok = $org && Auth::guard('client')->attempt(['organization_id' => $org->id, 'email' => strtolower($d['email']), 'password' => $d['password'], 'is_active' => true]);
        abort_unless($ok, 422, __('api.invalid_credentials'));                      // same answer for unknown, inactive, invited or wrong password
        $request->session()->regenerate();
        $i = Auth::guard('client')->user();
        $i->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('portal.login', $i);
        return response()->json(['mfa' => $this->mfaState($request, $i)]);
    }

    public function logout(Request $request)
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->noContent();
    }

    public function status(Request $request)
    {
        $i = Auth::guard('client')->user();
        $required = PortalMfa::required($i->organization_id);
        return ['email' => $i->email, 'client' => $i->client()->withoutGlobalScopes()->first(['id', 'code', 'name']),
            'mfa' => ['required' => $required, 'enabled' => $i->hasMfa(), 'verified' => !$required || $request->session()->get('portal_mfa_ok') === $i->getKey()]];
    }

    // ---- invitation / password reset ---------------------------------------------------------------------------------------------
    public function activationCheck(Request $request)
    {
        $d = $request->validate(['organization' => 'required|string|max:120', 'email' => 'required|email', 'token' => 'required|string|max:100']);
        $org = $this->org($d['organization']);
        [$inv, $i, $problem] = $org ? $this->invitations->inspect($org, $d['email'], $d['token']) : [null, null, 'invalid'];
        if ($problem) return response()->json(['valid' => false, 'reason' => $problem, 'message' => __('portal.invitation_' . $problem)], 422);
        return ['valid' => true, 'kind' => $inv->kind, 'expires_at' => $inv->expires_at->toIso8601String()];
    }

    public function activate(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $d = $request->validate(['organization' => 'required|string|max:120', 'email' => 'required|email', 'token' => 'required|string|max:100',
            'password' => ['required', 'confirmed', ...PasswordRules::for($request->input('email'))]]);
        $org = $this->org($d['organization']);
        [$inv, $i, $problem] = $org ? $this->invitations->inspect($org, $d['email'], $d['token']) : [null, null, 'invalid'];
        if ($problem) throw ValidationException::withMessages(['email' => [__('portal.invitation_' . $problem)]]);

        $i = $this->invitations->accept($i, $inv, $d['password']);
        Auth::guard('client')->login($i);
        $request->session()->regenerate();
        $i->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log($inv->kind === 'reset' ? 'portal.password_reset' : 'portal.activated', $i);
        return response()->json(['mfa' => $this->mfaState($request, $i)]);
    }

    /** Always answers the same, so the form cannot be used to discover which e-mails have a portal account. */
    public function forgot(Request $request)
    {
        $d = $request->validate(['organization' => 'required|string|max:120', 'email' => 'required|email']);
        $org = $this->org($d['organization']);
        $i = $org ? ClientIdentity::withoutGlobalScopes()->where('organization_id', $org->id)->where('email', strtolower($d['email']))->where('is_active', true)->first() : null;
        if ($i && !$this->invitations->throttled($i)) $this->invitations->issue($i, null, 'reset');
        return response()->json(['message' => __('portal.reset_sent')]);
    }

    // ---- two-factor ----------------------------------------------------------------------------------------------------------------
    public function mfaSetup(Request $request)
    {
        $i = Auth::guard('client')->user();
        $org = Organization::find($i->organization_id);
        return $this->mfa->begin($i, $org?->name ?: config('app.name'));
    }

    public function mfaConfirm(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:12']);
        $i = Auth::guard('client')->user();
        $codes = $this->mfa->confirm($i, $d['code']);
        $request->session()->regenerate();
        $request->session()->put('portal_mfa_ok', $i->getKey());
        return response()->json(['recovery_codes' => $codes]);
    }

    public function mfaVerify(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:20']);
        $i = Auth::guard('client')->user();
        if (!$this->mfa->verify($i, $d['code'])) {
            AuditLogger::log('portal.mfa_failed', $i);
            throw ValidationException::withMessages(['code' => [__('portal.mfa.invalid_code')]]);
        }
        $request->session()->regenerate();
        $request->session()->put('portal_mfa_ok', $i->getKey());
        return response()->noContent();
    }
}
