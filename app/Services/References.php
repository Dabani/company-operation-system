<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class References
{
    /** AST-000123 (never resets) or MOV-2026-000012 (resets yearly). Call inside the creating transaction. */
    public static function next(string $organizationId, string $scope, string $prefix, bool $yearly): string
    {
        $year = $yearly ? (int) now()->format('Y') : 0;
        DB::table('reference_counters')->insertOrIgnore(['organization_id' => $organizationId, 'scope' => $scope, 'year' => $year, 'last_number' => 0]);
        $row = DB::table('reference_counters')->where(['organization_id' => $organizationId, 'scope' => $scope, 'year' => $year])->lockForUpdate()->first();
        $n = $row->last_number + 1;
        DB::table('reference_counters')->where('id', $row->id)->update(['last_number' => $n]);

        return $yearly ? sprintf('%s-%d-%06d', $prefix, $year, $n) : sprintf('%s-%06d', $prefix, $n);
    }
}
