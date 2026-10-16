<?php

namespace Database\Seeders;

use App\Models\DashboardWidget;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Applies Bundle 2 defaults to the standard roles of every organization that has them. Safe to re-run. */
class ServiceDeskRolesSeeder extends Seeder
{
    public function run(): void
    {
        $widgetIds = DashboardWidget::pluck('id', 'code');
        $perClass = [
            'guest' => ['stat_my_cases'],
            'staff' => ['stat_my_cases'],
            'team_leader' => ['stat_open_cases', 'chart_cases_by_status'],
            'manager' => ['stat_open_cases', 'chart_cases_by_status'],
            'admin' => ['stat_my_cases', 'stat_open_cases', 'chart_cases_by_status'],
        ];

        foreach (Role::withoutGlobalScopes()->whereIn('code', array_keys($perClass))->get() as $role) {
            $current = $role->widgets()->count();
            foreach ($perClass[$role->code] as $i => $code) {
                $role->widgets()->syncWithoutDetaching([$widgetIds[$code] => ['sort_order' => 20 + $i + $current]]);
            }
            if ($role->code === 'admin') {
                $role->permissions()->syncWithoutDetaching([Permission::where('code', 'case.notify_new')->value('id')]);
            }
        }

        // The admin who receives SMS alerts needs a phone number.
        if ($phone = config('ops.seed_admin_phone')) {
            User::where('email', config('ops.seed_admin_email'))->update(['phone' => $phone]);
        }
    }
}
