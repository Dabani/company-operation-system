<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalMfa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Portal resources are unreachable until this session passed two-factor verification (when the organization requires it). */
class EnsurePortalMfa
{
    public function handle(Request $request, Closure $next)
    {
        $i = Auth::guard('client')->user();
        abort_unless($i, 401);
        if (PortalMfa::required($i->organization_id) && ($request->hasSession() ? $request->session()->get('portal_mfa_ok') : null) !== $i->getKey()) {
            $code = $i->hasMfa() ? 'mfa_required' : 'mfa_setup_required';
            return response()->json(['error' => ['code' => $code, 'message' => __("portal.$code")]], 403);
        }
        return $next($request);
    }
}
