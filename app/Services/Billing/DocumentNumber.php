<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/** Gap-free sequential numbers per organization, document type and year. Call inside the transaction that issues the document. */
class DocumentNumber
{
    public function next(string $organizationId, string $prefix, ?int $year = null): string
    {
        $year ??= (int) date('Y');
        $key = "$prefix-$year";
        $row = DB::table('document_sequences')->where('organization_id', $organizationId)->where('seq_key', $key)->lockForUpdate()->first();
        if (!$row) {
            DB::table('document_sequences')->insert(['id' => (string) \Illuminate\Support\Str::ulid(), 'organization_id' => $organizationId, 'seq_key' => $key, 'last_value' => 1]);
            $n = 1;
        } else {
            $n = $row->last_value + 1;
            DB::table('document_sequences')->where('id', $row->id)->update(['last_value' => $n]);
        }
        return sprintf('%s-%d-%04d', $prefix, $year, $n);
    }
}
