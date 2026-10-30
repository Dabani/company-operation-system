<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Bundle 7 - reports menu entry and report permissions per standard role. Idempotent.
 *
 *  report.view     opens the Reports hub (each report also needs its own module permission, see ReportService)
 *  report.receive  executive overview and management reports
 *  report.export   CSV / XLSX export (kept separate from viewing; every export is audited)
 *
 * Adjust in Administration > Roles > Permission matrix.
 */
class ReportsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $perm = fn (string $c) => Permission::where('code', $c)->value('id');
        MenuItem::updateOrCreate(['code' => 'reports'], [
            'parent_id' => null, 'label_key' => 'menu.reports', 'icon' => 'IconChartBar', 'route' => '/reports',
            'permission_id' => $perm('report.view'), 'sort_order' => 30, 'is_active' => true,
        ]);

        $all = Permission::pluck('id', 'code');
        $ids = fn (array $codes) => collect($codes)->map(fn ($c) => $all[$c])->all();

        $grants = [
            'team_leader' => ['report.view'],
            'records_manager' => ['report.view'],
            'it_manager' => ['report.view'],
            'manager' => ['report.view', 'report.receive', 'report.export'],
            'compliance_officer' => ['report.view', 'report.receive', 'report.export'],
            'admin' => ['report.view', 'report.receive', 'report.export'],
        ];

        foreach (Organization::all() as $org) {
            foreach ($grants as $code => $codes) {
                Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $code)->first()
                    ?->permissions()->syncWithoutDetaching($ids($codes));
            }
        }
    }
}
