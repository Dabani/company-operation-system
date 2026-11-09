<?php

namespace App\Support;

use App\Models\LookupType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Validation helper: the value must be the CODE of an active value of a lookup list (e.g. LookupRule::code('currency') accepts RWF, USD...). */
class LookupRule
{
    public static function code(string $typeCode): Exists
    {
        return Rule::exists('lookup_values', 'code')->where(fn ($q) => $q->where('is_active', true)->whereIn('lookup_type_id', LookupType::where('code', $typeCode)->pluck('id')));
    }
}
