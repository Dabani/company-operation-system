<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * COPS Phase 6c seeds (idempotent): export lookups and two permissions. NO layout and NO account is seeded: the bank, RRA and RSSB layouts and the journal accounts come from
 * the accountant (a layout records who agreed it and a second person verifies it). Permissions go to admin ONLY. rw / sw labels are first-pass.
 */
class HrPayrollExportSeeder extends Seeder
{
    private const PERMS = [
        'hr.payroll.export' => 'Generate and download payroll export files (bank transfer, declarations, journal)',
        'hr.payroll.export.configure' => 'Define payroll export layouts and journal accounts (verification needs hr.payroll.verify)',
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'hr'), 'name' => $name]);
        }
        $ids = Permission::whereIn('code', array_keys(self::PERMS))->pluck('id')->all();
        foreach (Organization::all() as $org) {
            Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', 'admin')->first()?->permissions()->syncWithoutDetaching($ids);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'pay_export_kind' => ['Payroll export kind', [
                ['bank_transfer', 'blue', 'Bank transfer file', 'Fichier de virement bancaire', 'Dosiye y\'iyoherezwa muri banki', 'Faili ya uhamisho wa benki'],
                ['declaration', 'orange', 'Statutory declaration file', 'Fichier de déclaration légale', 'Dosiye y\'itangazo ry\'amategeko', 'Faili ya tamko la kisheria'],
                ['journal', 'violet', 'Accounting journal', 'Journal comptable', 'Ibitabo by\'ibaruramari', 'Jarida la uhasibu'],
            ]],
            'pay_export_layout' => ['Payroll export layout', [['delimited', 'blue', 'Delimited (CSV)', 'Délimité (CSV)', 'Ritandukanyijwe (CSV)', 'Iliyotenganishwa (CSV)'], ['fixed_width', 'gray', 'Fixed width', 'Largeur fixe', 'Ubugari buhoraho', 'Upana usiobadilika']]],
        ];
        foreach ($data as $typeCode => [$typeName, $rows]) {
            $type = LookupType::where('code', $typeCode)->first() ?? LookupType::create(['code' => $typeCode, 'name' => $typeName, 'is_system' => true]);
            $start = (int) (LookupValue::where('lookup_type_id', $type->id)->max('sort_order') ?? -1);
            $existing = LookupValue::where('lookup_type_id', $type->id)->whereNull('organization_id')->pluck('sort_order', 'code');
            foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c], ['color' => $color, 'icon' => null, 'sort_order' => $existing[$c] ?? ($start + 1 + $i), 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }
}
