<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\Language;
use App\Models\LeaveTypeSetting;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * COPS Phase 5a seeds (idempotent): leave lookups, permissions, menu, the default approval flow and one settings row per leave type.
 * NO legal value and NO day count is seeded: entitlements come from the company policy (rules engine) and statutory limits that HR/legal enter and a second person verifies.
 * Type settings are created once and never overwritten. Defaults are conservative and must be confirmed (see docs/PHASE-5A-LEAVE.md). rw / sw labels are first-pass.
 */
class HrLeaveSeeder extends Seeder
{
    private const PERMS = [
        'hr.leave.request' => 'Request leave, see my balances and my requests',
        'hr.leave.view' => 'See leave requests, balances and the team calendar of the people in my scope',
        'hr.leave.approve' => 'Approve leave requests',
        'hr.leave.manage' => 'HR: request on behalf, adjust balances, cancel approved leave, manage leave types and periods',
        'hr.leave.profile' => 'View and edit leave profiles: date of birth, sex, weekly hours (audited)',
        'hr.leave.legal.manage' => 'Enter and end statutory leave rules (verification needs rules.signoff)',
    ];

    private const GRANTS = [
        'staff' => ['hr.leave.request'],
        'team_leader' => ['hr.leave.request', 'hr.leave.view'],
        'manager' => ['hr.leave.request', 'hr.leave.view', 'hr.leave.approve'],
        'hr_manager' => ['hr.leave.request', 'hr.leave.view', 'hr.leave.approve', 'hr.leave.manage', 'hr.leave.profile', 'hr.leave.legal.manage'],
    ];

    /** type => [day basis, uses_balance, accrues, paid, requires_document, allow_retroactive, ignores_blackout] */
    private const TYPES = [
        'annual' => ['working_days', true, true, true, false, false, false],
        'compensatory' => ['working_days', true, false, true, false, false, false],
        'sick' => ['working_days', false, false, true, true, true, true],
        'maternity' => ['calendar_days', false, false, true, true, false, true],
        'paternity' => ['calendar_days', false, false, true, false, false, true],
        'circumstantial' => ['working_days', false, false, true, false, true, true],
        'authorized_absence' => ['working_days', false, false, true, false, false, false],
        'unpaid' => ['working_days', false, false, false, false, false, false],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'hr'), 'name' => $name]);
        }
        $this->menu();
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $role('admin')?->permissions()->syncWithoutDetaching(collect(array_keys(self::PERMS))->map(fn ($c) => $ids[$c])->all());

            ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'leave_default', 'version' => 1],
                ['name' => 'Leave request (line manager)', 'subject_type_id' => Lookup::id('approval_subject_type', 'leave'), 'effective_from' => '2026-01-01', 'is_active' => true])
                ->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Line manager',
                    'step_type_id' => Lookup::id('approval_step_type', 'line_manager'), 'role_id' => null, 'escalate_after_hours' => 72]);

            foreach (self::TYPES as $code => [$basis, $bal, $acc, $paid, $doc, $retro, $blackout]) {
                LeaveTypeSetting::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'leave_type_id' => Lookup::id('leave_type', $code)], [
                    'day_basis_id' => Lookup::id('leave_day_basis', $basis), 'uses_balance' => $bal, 'accrues' => $acc, 'is_paid' => $paid, 'requires_document' => $doc,
                    'allow_retroactive' => $retro, 'ignores_blackout' => $blackout, 'allow_half_days' => true, 'is_requestable' => true, 'is_active' => true]);
            }
        }
    }

    private function menu(): void
    {
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        foreach ([
            ['people.leave_me', 'IconBeach', '/leave/me', 'hr.leave.request', 13],
            ['people.leave_team', 'IconUsers', '/leave/team', 'hr.leave.view', 14],
            ['people.leave_setup', 'IconAdjustmentsHorizontal', '/leave/setup', 'hr.leave.manage', 15],
        ] as [$code, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => MenuItem::where('code', 'people')->value('id'), 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'leave_type' => ['Leave type', [
                ['annual', 'green', 'Annual leave', 'Congé annuel', 'Ikiruhuko cy\'umwaka', 'Likizo ya mwaka'], ['sick', 'orange', 'Sick leave', 'Congé de maladie', 'Ikiruhuko cy\'uburwayi', 'Likizo ya ugonjwa'],
                ['maternity', 'pink', 'Maternity leave', 'Congé de maternité', 'Ikiruhuko cyo kubyara', 'Likizo ya uzazi'], ['paternity', 'blue', 'Paternity leave', 'Congé de paternité', 'Ikiruhuko cya se w\'umwana', 'Likizo ya baba'],
                ['circumstantial', 'teal', 'Circumstantial leave', 'Congé pour événement familial', 'Ikiruhuko cy\'impamvu zihariye', 'Likizo ya matukio maalum'],
                ['authorized_absence', 'gray', 'Authorized absence', 'Absence autorisée', 'Kubura byemewe', 'Kutokuwepo kwa idhini'], ['compensatory', 'cyan', 'Compensatory leave', 'Congé compensatoire', 'Ikiruhuko cy\'impinduranyo', 'Likizo ya fidia'],
                ['unpaid', 'gray', 'Unpaid leave', 'Congé sans solde', 'Ikiruhuko kitishyurwa', 'Likizo bila malipo'],
            ]],
            'leave_day_basis' => ['Leave day basis', [
                ['working_days', 'blue', 'Working days', 'Jours ouvrables', 'Iminsi y\'akazi', 'Siku za kazi'], ['calendar_days', 'blue', 'Calendar days', 'Jours calendaires', 'Iminsi yose', 'Siku za kalenda'],
            ]],
            'leave_request_status' => ['Leave request status', [
                ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Utegereje kwemezwa', 'Inasubiri idhini'], ['approved', 'green', 'Approved', 'Approuvé', 'Byemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', 'Rejected', 'Rejeté', 'Byanzwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Byahagaritswe', 'Imeghairiwa'],
            ]],
            'leave_ledger_kind' => ['Leave ledger entry kind', [
                ['opening', 'gray', 'Opening balance', 'Solde d\'ouverture', 'Amafaranga y\'intangiriro', 'Salio la kuanzia'], ['accrual', 'green', 'Accrual', 'Acquisition', 'Kongerwaho', 'Nyongeza'],
                ['usage', 'blue', 'Leave taken', 'Congé pris', 'Ikiruhuko cyafashwe', 'Likizo iliyochukuliwa'], ['reversal', 'orange', 'Reversal', 'Annulation', 'Gusubiza inyuma', 'Kurudisha'],
                ['adjustment', 'orange', 'Adjustment', 'Ajustement', 'Ihinduka', 'Marekebisho'], ['carry_forfeit', 'red', 'Forfeited at year end', 'Perdu en fin d\'année', 'Byatakaye ku mpera z\'umwaka', 'Imepotea mwisho wa mwaka'],
                ['carry_expiry', 'red', 'Carried-over days expired', 'Report expiré', 'Iminsi yimuriwe yarangiye', 'Siku zilizohamishiwa zimeisha'],
            ]],
            'employee_sex' => ['Sex (leave eligibility)', [
                ['female', 'gray', 'Female', 'Féminin', 'Gore', 'Mwanamke'], ['male', 'gray', 'Male', 'Masculin', 'Gabo', 'Mwanaume'],
            ]],
            'leave_period_kind' => ['Leave period kind', [
                ['recommended', 'green', 'Recommended period', 'Période recommandée', 'Igihe cyagirwa inama', 'Kipindi kinachopendekezwa'], ['blackout', 'red', 'Blackout period', 'Période d\'exclusion', 'Igihe kitemewe', 'Kipindi kisichoruhusiwa'],
            ]],
            'rule_attribute' => ['Rule attribute', [
                ['leave_type', 'blue', 'Leave type', 'Type de congé', 'Ubwoko bw\'ikiruhuko', 'Aina ya likizo'], ['leave_days', 'blue', 'Leave days', 'Jours de congé', 'Iminsi y\'ikiruhuko', 'Siku za likizo'],
            ]],
            'file_link_type' => ['File link type', [
                ['leave_request', 'gray', 'Leave request', 'Demande de congé', 'Gusaba ikiruhuko', 'Ombi la likizo'],
            ]],
        ];
        foreach ($data as $typeCode => [$typeName, $rows]) {
            $type = LookupType::where('code', $typeCode)->first() ?? LookupType::create(['code' => $typeCode, 'name' => $typeName, 'is_system' => true]);
            $start = (int) (LookupValue::where('lookup_type_id', $type->id)->max('sort_order') ?? -1);
            $existing = LookupValue::where('lookup_type_id', $type->id)->whereNull('organization_id')->pluck('sort_order', 'code');
            foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c],
                    ['color' => $color, 'icon' => null, 'sort_order' => $existing[$c] ?? ($start + 1 + $i), 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }
}
