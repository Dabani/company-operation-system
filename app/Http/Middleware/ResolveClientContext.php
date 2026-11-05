<?php

namespace App\Http\Middleware;

use App\Services\ClientContext;
use App\Services\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Portal counterpart of ResolveOrganizationContext: derives tenant and client from the "client" guard only. */
class ResolveClientContext
{
    public function handle(Request $request, Closure $next)
    {
        $identity = Auth::guard('client')->user();
        abort_unless($identity, 401);

        $client = $identity->client()->withoutGlobalScopes()->first();
        abort_unless($identity->is_active && $client && $client->portal_enabled, 403, 'portal_disabled');

        app(OrganizationContext::class)->resolveForOrganization($identity->organization()->first());
        app(ClientContext::class)->activate($identity);
        return $next($request);
    }
}
