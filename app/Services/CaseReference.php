<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CaseReference
{
    /** Returns e.g. CASE-2026-000123. Call inside the transaction that creates the case. */
    public static function next(string $organizationId): string
    {
        $year = (int) now()->format('Y');

        DB::table('case_sequences')->insertOrIgnore(['organization_id' => $organizationId, 'year' => $year, 'last_number' => 0]);
        $row = DB::table('case_sequences')->where('organization_id', $organizationId)->where('year', $year)->lockForUpdate()->first();
        $n = $row->last_number + 1;
        DB::table('case_sequences')->where('id', $row->id)->update(['last_number' => $n]);

        return sprintf('%s-%d-%06d', config('ops.case_prefix'), $year, $n);
    }
}
