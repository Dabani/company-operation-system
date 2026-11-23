<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
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
 * COPS Phase 5b seeds (idempotent): trip lookups, permissions, menu, default approval flow. NO rate is seeded: per diem, accommodation and transport
 * rates are entered by HR/finance from the organization's own decision. rw / sw labels are first-pass.
 */
class HrTripSeeder extends Seeder
{
    private const PERMS = [
        'hr.trip.request' => 'Request business trips, record expenses and submit trip reports',
        'hr.trip.view' => 'See business trips of the people in my scope',
        'hr.trip.approve' => 'Approve business trips',
        'hr.trip.finance' => 'Finance: pay advances, review expenses, settle trips, see every trip',
        'hr.trip.configure' => 'Manage per diem, accommodation and transport rate tables',
    ];

    /** finance is deliberately NOT granted to hr_manager: create a finance role in the Roles screen and give it hr.trip.finance (separation of duties). */
    private const GRANTS = [
        'staff' => ['hr.trip.request'],
        'team_leader' => ['hr.trip.request', 'hr.trip.view'],
        'manager' => ['hr.trip.request', 'hr.trip.view', 'hr.trip.approve'],
        'hr_manager' => ['hr.trip.request', 'hr.trip.view', 'hr.trip.approve', 'hr.trip.configure'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'hr'), 'name' => $name]);
        }
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        foreach ([['people.trips', 'IconPlane', '/trips', 'hr.trip.request', 16], ['people.trips_finance', 'IconReceipt2', '/trips/finance', 'hr.trip.finance', 17], ['people.trip_rates', 'IconAdjustmentsHorizontal', '/trips/rates', 'hr.trip.configure', 18]] as [$code, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => MenuItem::where('code', 'people')->value('id'), 'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route,
                'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $role('admin')?->permissions()->syncWithoutDetaching(collect(array_keys(self::PERMS))->map(fn ($c) => $ids[$c])->all());
            ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'trip_default', 'version' => 1],
                ['name' => 'Business trip (line manager)', 'subject_type_id' => Lookup::id('approval_subject_type', 'trip'), 'effective_from' => '2026-01-01', 'is_active' => true])
                ->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Line manager',
                    'step_type_id' => Lookup::id('approval_step_type', 'line_manager'), 'role_id' => null, 'escalate_after_hours' => 72]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'trip_status' => ['Trip status', [
                ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Utegereje kwemezwa', 'Inasubiri idhini'], ['approved', 'green', 'Approved', 'Approuvé', 'Byemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', 'Rejected', 'Rejeté', 'Byanzwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Byahagaritswe', 'Imeghairiwa'],
                ['report_submitted', 'blue', 'Report submitted', 'Rapport soumis', 'Raporo yatanzwe', 'Ripoti imewasilishwa'], ['settled', 'teal', 'Settled', 'Soldé', 'Byarangiye (kwishyurana)', 'Imetatuliwa'],
            ]],
            'trip_advance_status' => ['Trip advance status', [
                ['requested', 'orange', 'Requested', 'Demandée', 'Yasabwe', 'Imeombwa'], ['paid', 'blue', 'Paid out', 'Versée', 'Yatanzwe', 'Imelipwa'], ['retired', 'green', 'Retired (justified)', 'Justifiée', 'Yarasobanuwe', 'Imehesabiwa'],
            ]],
            'trip_expense_status' => ['Trip expense status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igishushanyo', 'Rasimu'], ['submitted', 'orange', 'Submitted', 'Soumise', 'Yatanzwe', 'Imewasilishwa'],
                ['accepted', 'green', 'Accepted', 'Acceptée', 'Yemewe', 'Imekubaliwa'], ['rejected', 'red', 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa'],
            ]],
            'trip_expense_category' => ['Trip expense category', [
                ['transport', 'blue', 'Transport', 'Transport', 'Ingendo', 'Usafiri'], ['accommodation', 'violet', 'Accommodation', 'Hébergement', 'Icumbi', 'Malazi'], ['meals', 'orange', 'Meals', 'Repas', 'Ibiryo', 'Chakula'],
                ['per_diem', 'green', 'Per diem', 'Indemnité journalière', 'Amafaranga y\'urugendo ya buri munsi', 'Posho ya kila siku'], ['communication', 'cyan', 'Communication', 'Communication', 'Itumanaho', 'Mawasiliano'], ['other', 'gray', 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'trip_settlement_resolution' => ['Trip settlement resolution', [
                ['paid_to_employee', 'green', 'Paid to the traveller', 'Payé au voyageur', 'Yishyuwe umugenzi', 'Amelipwa msafiri'], ['refunded_by_employee', 'blue', 'Refunded by the traveller', 'Remboursé par le voyageur', 'Yagaruwe n\'umugenzi', 'Imerudishwa na msafiri'],
                ['payroll_deduction', 'orange', 'Payroll deduction (with consent)', 'Retenue sur salaire (avec consentement)', 'Gukata ku mushahara (hemejwe)', 'Makato ya mshahara (kwa ridhaa)'],
                ['waived', 'gray', 'Waived', 'Abandonné', 'Yasonewe', 'Imesamehewa'], ['closed_even', 'gray', 'Closed, nothing owed', 'Soldé, rien dû', 'Byarangiye, nta mwenda', 'Imefungwa, hakuna deni'],
            ]],
            'trip_zone' => ['Trip destination zone', [
                ['domestic', 'green', 'Domestic', 'National', 'Mu gihugu', 'Ndani ya nchi'], ['regional', 'blue', 'Regional', 'Régional', 'Mu karere', 'Kikanda'], ['international', 'violet', 'International', 'International', 'Mpuzamahanga', 'Kimataifa'],
            ]],
            'trip_rate_kind' => ['Trip rate kind', [
                ['per_diem', 'green', 'Per diem (per day)', 'Indemnité journalière (par jour)', 'Amafaranga ya buri munsi', 'Posho (kwa siku)'], ['accommodation', 'violet', 'Accommodation (per night)', 'Hébergement (par nuit)', 'Icumbi (ijoro)', 'Malazi (kwa usiku)'],
                ['transport', 'blue', 'Transport', 'Transport', 'Ingendo', 'Usafiri'],
            ]],
            'trip_booking_kind' => ['Trip booking kind', [
                ['flight', 'blue', 'Flight', 'Vol', 'Indege', 'Ndege'], ['bus', 'cyan', 'Bus / coach', 'Autocar', 'Bisi', 'Basi'], ['hotel', 'violet', 'Hotel', 'Hôtel', 'Hoteli', 'Hoteli'],
                ['vehicle', 'orange', 'Vehicle', 'Véhicule', 'Imodoka', 'Gari'], ['other', 'gray', 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'rule_attribute' => ['Rule attribute', [['trip_zone', 'blue', 'Trip zone', 'Zone du voyage', 'Akarere k\'urugendo', 'Eneo la safari']]],
            'file_link_type' => ['File link type', [['trip_expense', 'gray', 'Trip expense receipt', 'Justificatif de frais de mission', 'Inyemezabuguzi y\'urugendo', 'Risiti ya gharama za safari']]],
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
