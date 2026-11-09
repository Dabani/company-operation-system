<?php

namespace App\Support;

use App\Services\CodeGenerator;
use App\Services\OrganizationContext;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Add to a model to give it a system-generated identifier on creation. The model declares:
 *     protected static string $codeEntity = 'project';          // key in CodeGenerator::PREFIXES
 *     protected static string $codeColumn = 'employee_number';  // optional, default "code"
 * A code supplied explicitly (seeders, imports) is kept. Soft-deleted rows still reserve their code.
 */
trait HasGeneratedCode
{
    public static function bootHasGeneratedCode(): void
    {
        static::creating(function ($m) {
            $col = property_exists(static::class, 'codeColumn') ? static::$codeColumn : 'code';
            if (filled($m->{$col})) return;
            $org = $m->organization_id ?: app(OrganizationContext::class)->organizationId();
            $m->{$col} = CodeGenerator::next($org, static::$codeEntity, function (string $c) use ($org, $col) {
                $q = static::withoutGlobalScopes();
                if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) $q->withTrashed();
                return $q->where('organization_id', $org)->where($col, $c)->exists();
            });
        });
    }
}
