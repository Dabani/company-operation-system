<?php

namespace App\Http\Middleware;

use App\Services\OrganizationContext;
use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('perm:user.view') or 'perm:a,b' (any of). Deny by default. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$codes)
    {
        $ctx = app(OrganizationContext::class);
        foreach ($codes as $code) {
            if ($ctx->can($code)) {
                return $next($request);
            }
        }
        abort(403, 'forbidden');
    }
}
