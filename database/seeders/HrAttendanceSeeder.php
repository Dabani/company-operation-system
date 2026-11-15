<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\AttendanceImportProfile;
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
 * COPS Phase 4c seeds (idempotent): attendance lookups, permissions and the correction approval flow.
 * No shift, schedule, holiday or legal value is seeded: working time and public holidays are decided and entered by the organization.
 * rw / sw labels are first-pass: have native speakers review them.
 */
class HrAttendanceSeeder extends Seeder
{
    private const PERMS = [
        'hr.attendance.clock' => 'Clock in and out, see my own attendance, request corrections for my own days',
        'hr.attendance.view' => 'See the attendance of the people in my scope',
        'hr.attendance.manage' => 'Manage shifts, schedules, public holidays, networks and attendance imports',
        'hr.attendance.correct' => 'Request attendance corrections for the people in my scope',
        'hr.attendance.approve' => 'Approve attendance corrections',
        'hr.attendance.validate' => 'Validate (lock) a month of attendance for payroll',
        'hr.attendance.reopen' => 'Reopen a validated month (audited, with a reason)',
    ];

    private const GRANTS = [
        'staff' => ['hr.attendance.clock'],
        'team_leader' => ['hr.attendance.clock', 'hr.attendance.view'],
        'manager' => ['hr.attendance.clock', 'hr.attendance.view', 'hr.attendance.correct', 'hr.attendance.approve'],
        'hr_manager' => ['hr.attendance.clock', 'hr.attendance.view', 'hr.attendance.manage', 'hr.attendance.correct', 'hr.attendance.approve', 'hr.attendance.validate'],
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
            ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'attendance_correction_default', 'version' => 1],
                ['name' => 'Attendance correction (line manager)', 'subject_type_id' => Lookup::id('approval_subject_type', 'attendance_correction'), 'effective_from' => '2026-01-01', 'is_active' => true])
                ->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Line manager',
                    'step_type_id' => Lookup::id('approval_step_type', 'line_manager'), 'role_id' => null, 'escalate_after_hours' => 72]);
            $this->profiles($org->id);
        }
    }

    /** Menu entries (the screens ship with web bundle 16). Created after the permissions exist. */
    private function menu(): void
    {
        $perm = fn (string $c) => \App\Models\Permission::where('code', $c)->value('id');
        foreach ([
            ['people.attendance_clock', 'IconClockHour4', '/attendance/clock', 'hr.attendance.clock', 9],
            ['people.attendance_team', 'IconCalendarStats', '/attendance/team', 'hr.attendance.view', 10],
            ['people.attendance_setup', 'IconAdjustmentsHorizontal', '/attendance/setup', 'hr.attendance.manage', 11],
            ['people.attendance_import', 'IconFileImport', '/attendance/import', 'hr.attendance.manage', 12],
        ] as [$code, $icon, $route, $permission, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => MenuItem::where('code', 'people')->value('id'), 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $perm($permission), 'sort_order' => $order, 'is_active' => true]);
        }
    }

    /** Shipped import profiles: read-only starting points for the usual device and spreadsheet layouts. Duplicate one to adapt it to a particular device. */
    private function profiles(string $orgId): void
    {
        $daily = [['In', 'in'], ['Out', 'out'], ['In 1', 'in'], ['Out 1', 'out'], ['In 2', 'in'], ['Out 2', 'out'], ['In 3', 'in'], ['Out 3', 'out']];
        $defs = [
            ['Automatic', 'Recognises the usual column names: employee number or device code, date and time (in one column or two), direction. Works for most exports and spreadsheets.', ['identify_by' => 'either']],
            ['Device badge number', 'For devices that export their own user or badge numbers. Map each number to an employee under Device codes first (unknown numbers are listed after an import).', ['identify_by' => 'device_code']],
            ['Numeric status 0 = in, 1 = out', 'For devices that write a number in the status column: 0 check-in, 1 check-out, 2 break-out, 3 break-in, 4 overtime-in, 5 overtime-out.', ['identify_by' => 'device_code', 'in_values' => '0,3,4', 'out_values' => '1,2,5']],
            ['Daily report with In / Out columns', 'One row per person and day with the times in separate columns (In, Out, In 1, Out 1, In 2, Out 2...). Set the header row if the report has title lines above it.', ['layout' => 'daily', 'identify_by' => 'either']],
        ];
        foreach ($defs as [$name, $description, $settings]) {
            $p = AttendanceImportProfile::withoutGlobalScopes()->updateOrCreate(['organization_id' => $orgId, 'name' => $name], ['description' => $description, 'is_system' => true] + $settings);
            if (($settings['layout'] ?? '') === 'daily') {
                $p->slots()->delete();
                foreach ($daily as $i => [$column, $direction]) {
                    $p->slots()->create(['position' => $i, 'column_name' => $column, 'direction' => $direction]);
                }
            }
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'attendance_status' => ['Attendance status', [
                ['present', 'green', 'Present', 'Présent', 'Ahari', 'Yupo'], ['late', 'orange', 'Late', 'En retard', 'Yatinze', 'Amechelewa'], ['absent', 'red', 'Absent', 'Absent', 'Ntahari', 'Hayupo'],
                ['holiday', 'blue', 'Public holiday', 'Jour férié', 'Umunsi mukuru', 'Sikukuu'], ['day_off', 'gray', 'Day off', 'Jour de repos', 'Ikiruhuko', 'Siku ya mapumziko'],
                ['leave', 'teal', 'On leave', 'En congé', 'Ari mu kiruhuko', 'Likizoni'], ['incomplete', 'yellow', 'Missing clock-out', 'Sortie non pointée', 'Ntiyasohotse', 'Hakutoka'],
            ]],
            'attendance_source' => ['Attendance source', [['web', 'blue', 'Web', 'Web', 'Urubuga', 'Wavuti'], ['import', 'gray', 'Import', 'Import', 'Kwinjiza dosiye', 'Kuagiza']]],
            'attendance_correction_status' => ['Attendance correction status', [
                ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Utegereje kwemezwa', 'Inasubiri idhini'], ['approved', 'green', 'Approved', 'Approuvée', 'Yemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'approval_subject_type' => ['Approval subject type', [['attendance_correction', 'gray', 'Attendance correction', 'Correction de présence', 'Gukosora ukwitaba', 'Marekebisho ya mahudhurio']]],
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
