<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DashboardWidget;
use App\Models\Language;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Generic demo tenant with the five standard roles. Organization-specific content (for example the
 * B-KELANA SOP templates) belongs in a separate, optional "starter template" seeder in a later bundle.
 */
class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(['slug' => 'demo'], [
            'name' => 'Demo Organization',
            'status_id' => Lookup::id('organization_status', 'active'),
            'default_language_id' => Language::where('code', 'en')->value('id'),
            'timezone_id' => Lookup::id('timezone', 'Africa/Kigali'),
            'currency_id' => Lookup::id('currency', 'RWF'),
            'date_format_id' => Lookup::id('date_format', 'd/m/Y'),
        ]);

        $all = Permission::pluck('id', 'code');
        $only = fn (array $codes) => collect($codes)->map(fn ($c) => $all[$c])->all();

        $guest = ['dashboard.view', 'case.create', 'case.view_own', 'case.comment', 'chat.use', 'file.upload', 'file.view', 'file.download'];
        $staff = array_merge($guest, ['department.view', 'location.view', 'file.replace', 'file.archive', 'file.delete_request', 'archive.view', 'asset.view']);
        $lead = array_merge($staff, ['user.view', 'case.view_all', 'case.assign', 'case.update_status', 'report.view', 'lookup.view']);
        $manager = array_merge($lead, ['user.create', 'user.update', 'case.close', 'file.restore', 'archive.manage', 'asset.manage',
            'asset.approve_movement', 'role.view', 'audit_log.view', 'report.export', 'data.export', 'chat.moderate', 'organization.view']);

        $roles = [
            // code, name, level, colour, permissions, widgets
            ['guest', 'Guest', 10, 'gray', $only($guest), ['welcome']],
            ['staff', 'Staff', 20, 'teal', $only($staff), ['welcome']],
            ['team_leader', 'Team leader', 40, 'orange', $only($lead), ['welcome', 'stat_users', 'stat_departments']],
            ['manager', 'Manager', 60, 'grape', $only($manager), ['welcome', 'stat_users', 'stat_roles', 'stat_departments', 'stat_audit_today', 'chart_users_by_role', 'chart_activity_7d']],
            ['admin', 'Administrator', 100, 'blue', $all->values()->all(), ['welcome', 'stat_users', 'stat_roles', 'stat_departments', 'stat_audit_today', 'chart_users_by_role', 'chart_activity_7d', 'list_recent_activity']],
        ];

        $roleModels = [];
        foreach ($roles as [$code, $name, $level, $color, $perms, $widgets]) {
            $r = Role::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $org->id, 'code' => $code],
                ['name' => $name, 'level' => $level, 'color' => $color, 'is_system' => true, 'is_active' => true]
            );
            // Only the admin role is re-synced to "all permissions" on every seed run, so new permissions reach admins automatically.
            if ($code === 'admin' || $r->wasRecentlyCreated) {
                $r->permissions()->sync($perms);
                $r->widgets()->sync(collect($widgets)->values()->mapWithKeys(fn ($w, $i) => [DashboardWidget::where('code', $w)->value('id') => ['sort_order' => $i]])->all());
            }
            $roleModels[$code] = $r;
        }

        $it = Department::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'IT'], ['name' => 'IT & Systems']);
        Department::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'ADM'], ['name' => 'Administration']);
        Location::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'HQ'],
            ['name' => 'Head office', 'location_type_id' => Lookup::id('location_type', 'site')]);

        // --- users ----------------------------------------------------------------------------
        $password = config('ops.seed_admin_password') ?: Str::random(16);
        $this->makeUser($org, $roleModels['admin'], config('ops.seed_admin_email'), 'System Administrator', $password, $it);

        if (app()->environment('local')) {            // demo accounts for each role, local only
            foreach (['manager' => 'Demo Manager', 'team_leader' => 'Demo Team Leader', 'staff' => 'Demo Staff', 'guest' => 'Demo Guest'] as $code => $name) {
                $this->makeUser($org, $roleModels[$code], "$code@example.com", $name, $password, $it);
            }
        }

        if (!config('ops.seed_admin_password')) {
            $this->command?->warn("Generated admin password (shown once): {$password}");
        }
    }

    private function makeUser(Organization $org, Role $role, string $email, string $name, string $password, Department $dept): void
    {
        $user = User::firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => $password, 'email_verified_at' => now(),
            'status_id' => Lookup::id('user_status', 'active'),
            'language_id' => Language::where('code', 'en')->value('id'),
        ]);
        $m = Membership::firstOrCreate(['organization_id' => $org->id, 'user_id' => $user->id], [
            'department_id' => $dept->id, 'status_id' => Lookup::id('membership_status', 'active'), 'is_default' => true,
        ]);
        $m->roles()->syncWithoutDetaching([$role->id]);
    }
}
