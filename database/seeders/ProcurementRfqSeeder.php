<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 8d: RFQ statuses and the menu entry (buyers use procurement.manage). Run after ProcurementOrdersSeeder. Idempotent. rw / sw labels need native review. */
class ProcurementRfqSeeder extends Seeder
{
    public function run(): void
    {
        $this->lookups();
        $parent = MenuItem::where('code', 'procurement')->first();      // created by ProcurementSeeder
        if ($parent) {
            MenuItem::updateOrCreate(['code' => 'procurement.rfqs'], ['parent_id' => $parent->id, 'label_key' => 'menu.procurement.rfqs', 'icon' => 'IconScale', 'route' => '/rfqs',
                'permission_id' => Permission::where('code', 'procurement.manage')->value('id'), 'sort_order' => 2, 'is_active' => true]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'rfq_status' => ['RFQ status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['open', 'blue', 'Open for quotes', 'Ouvert aux offres', 'Ifunguye guhabwa ibiciro', 'Wazi kwa nukuu'],
                ['closed', 'orange', 'Closed for quotes', 'Clôturé aux offres', 'Yafunze guhabwa ibiciro', 'Imefungwa kwa nukuu'], ['awarded', 'green', 'Awarded', 'Attribué', 'Yatanzwe', 'Imetolewa'],
                ['cancelled', 'red', 'Cancelled', 'Annulé', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
        ];
        foreach ($data as $typeCode => [$typeName, $rows]) {
            $type = LookupType::where('code', $typeCode)->first() ?? LookupType::create(['code' => $typeCode, 'name' => $typeName, 'is_system' => true]);
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
        }
        Lookup::flush();
    }
}
