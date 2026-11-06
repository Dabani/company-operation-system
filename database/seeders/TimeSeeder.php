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

/** COPS Phase 2a seeds (idempotent). No rates are seeded: rate cards are entered by authorised staff. rw/sw labels need native review. */
class TimeSeeder extends Seeder
{
    private const PERMS = [
        'time.track' => ['time', 'Record my own time and submit my timesheet'],
        'time.view_team' => ['time', 'See timesheets of people in my department scope'],
        'time.adjust' => ['time', 'Create adjustment entries for approved time'],
        'rates.view' => ['time', 'View billing rate cards (confidential)'],
        'rates.manage' => ['time', 'Create and change billing rate cards'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => [$group, $name]) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', $group), 'name' => $name]);
        }
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        foreach ([['time', null, 'IconClock', '/time', 'time.track', 13], ['admin.rate_cards', 'admin.system', 'IconCoin', '/admin/rate-cards', 'rates.view', 5]] as [$code, $parent, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null, 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            foreach (['staff' => ['time.track'], 'team_leader' => ['time.track', 'time.view_team'], 'manager' => ['time.track', 'time.view_team', 'time.adjust', 'rates.view'], 'admin' => array_keys(self::PERMS)] as $role => $codes) {
                Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $role)->first()
                    ?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            // Conservative default: the line manager approves a weekly timesheet (top authority when no line manager is recorded).
            $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'timesheet_default', 'version' => 1], [
                'name' => 'Weekly timesheet (line manager)', 'subject_type_id' => Lookup::id('approval_subject_type', 'timesheet'), 'effective_from' => '2026-01-01', 'is_active' => true]);
            $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Line manager',
                'step_type_id' => Lookup::id('approval_step_type', 'line_manager'), 'escalate_after_hours' => 72]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'permission_group' => [['time', 'blue', 'Time & billing', 'Temps et facturation', 'Amasaha n\'ubwishyu', 'Muda na utozaji']],
            'time_entry_status' => [['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['submitted', 'orange', 'Submitted', 'Soumis', 'Byatanzwe', 'Imewasilishwa'],
                ['approved', 'green', 'Approved', 'Approuvé', 'Byemejwe', 'Imeidhinishwa'], ['invoiced', 'blue', 'Invoiced', 'Facturé', 'Byatangiwe inyemezabwishyu', 'Imetozwa ankara']],
            'timesheet_status' => [['draft', 'gray', 'Open', 'Ouverte', 'Rufunguye', 'Wazi'], ['submitted', 'orange', 'Submitted', 'Soumise', 'Rwatanzwe', 'Imewasilishwa'],
                ['approved', 'green', 'Approved', 'Approuvée', 'Rwemejwe', 'Imeidhinishwa']],
            'rate_scope' => [['default', 'gray', 'Default rate', 'Taux par défaut', 'Igiciro gisanzwe', 'Kiwango cha msingi'], ['role', 'blue', 'By project role', 'Par rôle de projet', 'Ku ruhare mu mushinga', 'Kwa jukumu la mradi'],
                ['employee', 'blue', 'By person', 'Par personne', 'Ku muntu', 'Kwa mtu'], ['task', 'blue', 'By task', 'Par tâche', 'Ku murimo', 'Kwa kazi']],
        ];
        $names = ['time_entry_status' => 'Time entry status', 'timesheet_status' => 'Timesheet status', 'rate_scope' => 'Rate scope'];
        foreach ($data as $code => $rows) {
            $type = LookupType::where('code', $code)->first() ?? LookupType::create(['code' => $code, 'name' => $names[$code] ?? $code, 'is_system' => true]);
            $start = LookupValue::where('lookup_type_id', $type->id)->max('sort_order');
            foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c], ['color' => $color, 'icon' => null, 'sort_order' => ($start ?? -1) + 1 + $i, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
            }
        }
        Lookup::flush();
    }
}
