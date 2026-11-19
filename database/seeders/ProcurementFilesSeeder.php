<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 8e: file link types for supplier quote documents and supplier invoice scans. Idempotent. */
class ProcurementFilesSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $rows = [
            ['rfq_quote', 'gray', 'Supplier quote document', 'Document de devis fournisseur', 'Inyandiko y\'igiciro cy\'utanga serivisi', 'Hati ya nukuu ya muuzaji'],
            ['supplier_invoice', 'gray', 'Supplier invoice document', 'Document de facture fournisseur', 'Inyandiko ya fagitire y\'utanga serivisi', 'Hati ya ankara ya muuzaji'],
        ];
        $type = LookupType::where('code', 'file_link_type')->first() ?? LookupType::create(['code' => 'file_link_type', 'name' => 'File link type', 'is_system' => true]);
        $start = (int) (LookupValue::where('lookup_type_id', $type->id)->max('sort_order') ?? -1);
        foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
            $v = LookupValue::firstOrNew(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c]);
            if (!$v->exists) {
                $v->sort_order = $start + 1 + $i;
            }
            $v->fill(['color' => $color, 'icon' => null, 'is_system' => true, 'is_active' => true])->save();
            foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
            }
        }
        Lookup::flush();
    }
}
