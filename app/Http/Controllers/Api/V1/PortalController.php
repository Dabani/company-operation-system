<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientIdentity;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\ClientContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * External portal boundary (Guide 3.4): own guard ("client"), own identity table, no roles, no internal permissions.
 * Every resource is client-scoped, so another client's identifier answers 404. Phase 3 adds invitations, MFA and the project views.
 */
class PortalController extends Controller
{
    public function login(Request $request)
    {
        $d = $request->validate(['organization' => 'required|string|max:120', 'email' => 'required|email', 'password' => 'required|string']);
        $org = Organization::where('slug', $d['organization'])->first();
        $ok = $org && Auth::guard('client')->attempt(['organization_id' => $org->id, 'email' => strtolower($d['email']), 'password' => $d['password'], 'is_active' => true]);
        abort_unless($ok, 422, __('api.invalid_credentials'));
        $request->session()->regenerate();
        $identity = Auth::guard('client')->user();
        $identity->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('portal.login', $identity);
        return response()->json(['message' => __('api.logged_in')]);
    }

    public function logout(Request $request)
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->noContent();
    }

    public function me()
    {
        $i = app(ClientContext::class)->identity();
        $c = Client::find($i->client_id);
        return ['email' => $i->email, 'client' => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name]];
    }

    public function contacts()
    {
        return ['data' => ClientContact::orderBy('name')->get(['id', 'name', 'email', 'phone', 'job_title'])];
    }

    public function contact(string $id)
    {
        return ['data' => ClientContact::findOrFail($id)->only(['id', 'name', 'email', 'phone', 'job_title'])];   // other client's id => 404
    }
}
