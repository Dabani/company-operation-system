<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;

/** Locale precedence: user preference -> Accept-Language -> default language. */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $active = Language::where('is_active', true)->pluck('code')->all();
        $code = $request->user()?->language?->code
            ?? $request->getPreferredLanguage($active)
            ?? config('ops.default_language');
        app()->setLocale(in_array($code, $active, true) ? $code : config('ops.default_language'));
        return $next($request);
    }
}
