<?php

namespace App\Http\Middleware;

use App\Support\Html;
use Closure;
use Illuminate\Http\Request;

/** Purifies configured rich-text fields on every write request, before validation and storage. */
class SanitizeRichText
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            foreach (config('ops.rich_text_fields', []) as $field) {
                if ($request->has($field) && is_string($request->input($field))) {
                    $request->merge([$field => Html::clean($request->input($field)) ?? '']);
                }
            }
        }
        return $next($request);
    }
}
