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
 * COPS Phase 6b seeds (idempotent): run / payslip lookups, one permission per payroll stage, menu. Stage permissions go to admin ONLY: create separate roles for the
 * reviewer (HR), the finance approver, the Managing Director and whoever pays, so that no one person can prepare and approve (the service refuses it anyway).
 * Every employee role receives hr.payslip.own (their OWN payslips, once paid). rw / sw labels are first-pass.
 */
class HrPayrollRunSeeder extends Seeder
{
    private const PERMS = [
        'hr.payroll.review' => 'Review payroll runs (HR)',
        'hr.payroll.approve' => 'Approve payroll runs (Finance)',
        'hr.payroll.approve_md' => 'Approve payroll runs (Managing Director)',
        'hr.payroll.lock' => 'Lock approved payroll runs',
        'hr.payroll.pay' => 'Record the payment of payroll runs',
        'hr.payroll.declare' => 'Record the statutory declaration of payroll runs',
        'hr.payslip.own' => 'See my own payslips',
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'hr'), 'name' => $name]);
        }
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        $parent = MenuItem::where('code', 'people')->value('id');
        MenuItem::updateOrCreate(['code' => 'people.payroll_runs'], ['parent_id' => $parent, 'label_key' => 'menu.people.payroll_runs', 'icon' => 'IconCoins', 'route' => '/payroll/runs', 'permission_id' => $p('hr.payroll.view'), 'sort_order' => 20, 'is_active' => true]);
        MenuItem::updateOrCreate(['code' => 'people.my_payslips'], ['parent_id' => $parent, 'label_key' => 'menu.people.my_payslips', 'icon' => 'IconReceipt2', 'route' => '/payroll/me', 'permission_id' => $p('hr.payslip.own'), 'sort_order' => 21, 'is_active' => true]);
        $all = Permission::whereIn('code', array_keys(self::PERMS))->pluck('id')->all();
        $own = [$p('hr.payslip.own')];
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            $role('admin')?->permissions()->syncWithoutDetaching($all);
            foreach (['staff', 'team_leader', 'manager', 'hr_manager'] as $c) {
                $role($c)?->permissions()->syncWithoutDetaching($own);
            }
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'pay_run_kind' => ['Payroll run kind', [['regular', 'blue', 'Regular run', 'Paie normale', 'Ishyirwa ry\'umushahara risanzwe', 'Malipo ya kawaida'], ['adjustment', 'orange', 'Adjustment run', 'Paie de régularisation', 'Ishyirwa ry\'ukosora', 'Malipo ya marekebisho']]],
            'pay_run_status' => ['Payroll run status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igishushanyo', 'Rasimu'], ['calculated', 'blue', 'Calculated', 'Calculée', 'Yabazwe', 'Imehesabiwa'], ['reviewed', 'cyan', 'Reviewed (HR)', 'Vérifiée (RH)', 'Yasuzumwe (abakozi)', 'Imekaguliwa (HR)'],
                ['approved', 'green', 'Approved', 'Approuvée', 'Yemejwe', 'Imeidhinishwa'], ['locked', 'violet', 'Locked', 'Verrouillée', 'Yafunzwe', 'Imefungwa'], ['paid', 'teal', 'Paid', 'Payée', 'Yishyuwe', 'Imelipwa'],
                ['declared', 'teal', 'Declared', 'Déclarée', 'Yatangajwe', 'Imetangazwa'], ['cancelled', 'red', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'pay_payslip_status' => ['Payslip status', [['calculated', 'green', 'Calculated', 'Calculé', 'Yabazwe', 'Imehesabiwa'], ['blocked', 'red', 'Blocked', 'Bloqué', 'Yahagaritswe', 'Imezuiwa'], ['excluded', 'gray', 'Excluded', 'Exclu', 'Yakuweho', 'Imeondolewa']]],
            'pay_line_kind' => ['Payslip line kind', [
                ['earning', 'green', 'Earning', 'Gain', 'Amafaranga yinjira', 'Mapato'], ['scheme_employee', 'orange', 'Employee contribution / tax', 'Cotisation / impôt employé', 'Umusanzu / umusoro w\'umukozi', 'Mchango / kodi ya mfanyakazi'],
                ['scheme_employer', 'blue', 'Employer contribution', 'Cotisation employeur', 'Umusanzu w\'umukoresha', 'Mchango wa mwajiri'], ['deduction', 'red', 'Deduction', 'Retenue', 'Igikatwa', 'Makato'],
            ]],
            'pay_flag_severity' => ['Payslip flag severity', [['blocker', 'red', 'Blocks the run', 'Bloque la paie', 'Ihagarika ishyirwa', 'Inazuia malipo'], ['warning', 'orange', 'Warning', 'Avertissement', 'Iburira', 'Onyo'], ['info', 'blue', 'Information', 'Information', 'Amakuru', 'Taarifa']]],
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
