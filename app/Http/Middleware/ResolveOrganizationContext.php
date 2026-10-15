<?php

namespace App\Http\Middleware;

use App\Services\OrganizationContext;
use Closure;
use Illuminate\Http\Request;

/** Derives the active organization from authenticated membership. X-Organization-Id is only a *request* validated against membership. */
class ResolveOrganizationContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $requested = $request->header('X-Organization-Id') ?: ($request->hasSession() ? $request->session()->get('active_organization_id') : null);
        $ctx = app(OrganizationContext::class);

        if (!$ctx->resolve($user, $requested) && !$ctx->resolve($user)) {
            abort(403, 'no_active_membership');
        }
        return $next($request);
    }
}
