<?php

namespace App\Support;

use App\Models\LookupValue;

/** Resolves global lookup codes to ids: Lookup::id('user_status', 'active'). */
class Lookup
{
    protected static array $cache = [];

    public static function id(string $type, string $code): string
    {
        $key = "$type:$code";
        if (!isset(static::$cache[$key])) {
            static::$cache[$key] = LookupValue::query()
                ->whereNull('organization_id')
                ->where('code', $code)
                ->whereHas('type', fn ($q) => $q->where('code', $type))
                ->valueOrFail('id');
        }
        return static::$cache[$key];
    }

    public static function flush(): void
    {
        static::$cache = [];
    }
}
