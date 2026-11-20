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

/**
 * COPS Phase 6a seeds (idempotent): payroll lookups, permissions, menu. NO component, scheme, rate, threshold, band, assignment or exemption is seeded:
 * the company accountant's signed schedule and the official RRA/RSSB notices are entered through the screens and verified by a second person (Guide 5.6).
 * The payroll permissions go to admin ONLY: create separate roles for the preparer, the verifier and the approvers (separation of duties).
 * rw / sw labels are first-pass.
 */
class HrPayrollSeeder extends Seeder
{
    private const PERMS = [
        'hr.payroll.view' => 'View payroll configuration (components, schemes, assignments, recurring items)',
        'hr.payroll.configure' => 'Prepare payroll configuration: components, schemes, versions, assignments, recurring items',
        'hr.payroll.verify' => 'Verify payroll configuration prepared by someone else (second person)',
        'hr.payroll.prepare' => 'Run payroll calculations (dry-run)',
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'hr'), 'name' => $name]);
        }
        MenuItem::updateOrCreate(['code' => 'people.payroll_setup'], ['parent_id' => MenuItem::where('code', 'people')->value('id'), 'label_key' => 'menu.people.payroll_setup', 'icon' => 'IconCoins',
            'route' => '/payroll/setup', 'permission_id' => Permission::where('code', 'hr.payroll.view')->value('id'), 'sort_order' => 19, 'is_active' => true]);
        $ids = Permission::whereIn('code', array_keys(self::PERMS))->pluck('id')->all();
        foreach (Organization::all() as $org) {
            Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', 'admin')->first()?->permissions()->syncWithoutDetaching($ids);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'pay_component_kind' => ['Pay component kind', [['earning', 'green', 'Earning', 'Gain', 'Amafaranga yinjira', 'Mapato'], ['deduction', 'orange', 'Deduction', 'Retenue', 'Igikatwa', 'Makato']]],
            'pay_component_source' => ['Pay component source', [
                ['basic_salary', 'blue', 'Basic salary (from the employment record)', 'Salaire de base (fiche d\'emploi)', 'Umushahara fatizo (uva mu nyandiko y\'akazi)', 'Mshahara wa msingi (kutoka rekodi ya ajira)'],
                ['recurring', 'teal', 'Recurring (fixed per employee)', 'Récurrent (fixe par employé)', 'Gisubirwamo (kiriho kuri buri mukozi)', 'Hurudiwa (kiasi maalum kwa mfanyakazi)'],
                ['variable', 'orange', 'Variable (entered each run)', 'Variable (saisi à chaque paie)', 'Gihinduka (cyinjizwa buri nshuro)', 'Hubadilika (huingizwa kila malipo)'],
            ]],
            'pay_statutory_type' => ['Statutory scheme type', [
                ['paye', 'red', 'PAYE (income tax)', 'PAYE (impôt sur le revenu)', 'PAYE (umusoro ku musaruro)', 'PAYE (kodi ya mapato)'], ['pension', 'blue', 'Pension', 'Pension', 'Pansiyo', 'Pensheni'],
                ['maternity', 'pink', 'Maternity fund', 'Fonds de maternité', 'Ikigega cy\'ubabyeyi', 'Mfuko wa uzazi'], ['health', 'green', 'Health insurance', 'Assurance maladie', 'Ubwishingizi bw\'ubuzima', 'Bima ya afya'],
                ['occupational', 'orange', 'Occupational hazards', 'Risques professionnels', 'Impanuka z\'akazi', 'Hatari za kikazi'], ['other', 'gray', 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'pay_scheme_mode' => ['Scheme calculation mode', [['flat_rate', 'blue', 'Flat rate', 'Taux fixe', 'Igipimo kimwe', 'Kiwango kimoja'], ['brackets', 'violet', 'Progressive bands', 'Tranches progressives', 'Ibyiciro bigenda byiyongera', 'Madaraja yanayopanda']]],
            'pay_scheme_base' => ['Scheme calculation base', [
                ['gross_earnings', 'gray', 'Gross earnings', 'Gains bruts', 'Amafaranga yose yinjiye', 'Mapato ghafi'], ['taxable_earnings', 'orange', 'Taxable earnings', 'Gains imposables', 'Amafaranga asoreshwa', 'Mapato yanayotozwa kodi'],
                ['pensionable_earnings', 'blue', 'Pensionable earnings', 'Gains soumis à pension', 'Amafaranga atangirwa pansiyo', 'Mapato yanayochangia pensheni'],
                ['taxable_after_deductions', 'violet', 'Taxable earnings after deductible contributions', 'Gains imposables après cotisations déductibles', 'Asoreshwa nyuma y\'imisanzu ikurwaho', 'Mapato yanayotozwa kodi baada ya michango inayokatwa'],
            ]],
            'pay_rounding' => ['Pay rounding mode', [['nearest', 'blue', 'Nearest (half away from zero)', 'Au plus proche (0,5 vers le haut)', 'Hafi (kimwe cya kabiri hejuru)', 'Karibu zaidi (nusu juu)'], ['down', 'gray', 'Down', 'Vers le bas', 'Hasi', 'Chini'], ['up', 'gray', 'Up', 'Vers le haut', 'Hejuru', 'Juu']]],
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
