<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * System-generated identifiers (PRJ-0001, CLI-0001, DEP-0001...). People never type a code: it is assigned when the record is created,
 * is unique per organization, never reused, and cannot be edited afterwards. Counters live in reference_counters (same table as asset tags).
 * Override a prefix in config/cops.php: 'code_prefixes' => ['project' => 'PJ'].
 */
class CodeGenerator
{
    public const PREFIXES = [
        'project' => 'PRJ', 'client' => 'CLI', 'employee' => 'EMP', 'department' => 'DEP', 'location' => 'LOC', 'role' => 'ROL',
        'obligation' => 'OBL', 'legal_reference' => 'LEG', 'rule_set' => 'RUL', 'approval_flow' => 'FLW',
    ];

    public static function format(string $prefix, int $n): string { return sprintf('%s-%04d', $prefix, $n); }

    /** @param \Closure(string): bool $exists true when the candidate is already used (covers codes typed by hand before this change) */
    public static function next(string $organizationId, string $entity, \Closure $exists): string
    {
        $prefix = config("cops.code_prefixes.$entity") ?? self::PREFIXES[$entity] ?? strtoupper(substr($entity, 0, 3));
        return DB::transaction(function () use ($organizationId, $entity, $prefix, $exists) {
            $key = ['organization_id' => $organizationId, 'scope' => "code:$entity", 'year' => 0];
            DB::table('reference_counters')->insertOrIgnore($key + ['last_number' => 0]);
            $row = DB::table('reference_counters')->where($key)->lockForUpdate()->first();
            $n = (int) $row->last_number;
            do {
                $n++;
                $code = self::format($prefix, $n);
            } while ($exists($code));
            DB::table('reference_counters')->where('id', $row->id)->update(['last_number' => $n]);
            return $code;
        });
    }

    /** Machine key derived from a name (lookup values, legal parameters): "Kenyan shilling" -> kenyan_shilling, made unique with _2, _3... */
    public static function key(string $label, \Closure $exists): string
    {
        $base = Str::slug($label, '_') ?: 'value';
        $base = mb_substr($base, 0, 50);
        $code = $base;
        for ($i = 2; $exists($code); $i++) $code = "{$base}_$i";
        return $code;
    }
}
