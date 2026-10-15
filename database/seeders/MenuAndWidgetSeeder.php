<?php

namespace Database\Seeders;

use App\Models\DashboardWidget;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

class MenuAndWidgetSeeder extends Seeder
{
    public function run(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;

        // [code, parent code, icon, route, permission, order]   label_key = "menu.<code>"
        $menu = [
            ['dashboard', null, 'IconLayoutDashboard', '/dashboard', 'dashboard.view', 1],
            ['admin', null, 'IconSettings', null, null, 90],
            ['admin.access', 'admin', 'IconShieldLock', null, null, 1],
            ['admin.users', 'admin.access', 'IconUsers', '/admin/users', 'user.view', 1],
            ['admin.roles', 'admin.access', 'IconKey', '/admin/roles', 'role.view', 2],
            ['admin.organization', 'admin', 'IconBuilding', null, null, 2],
            ['admin.org_profile', 'admin.organization', 'IconId', '/admin/organization', 'organization.view', 1],
            ['admin.departments', 'admin.organization', 'IconSitemap', '/admin/departments', 'department.view', 2],
            ['admin.locations', 'admin.organization', 'IconMapPin', '/admin/locations', 'location.view', 3],
            ['admin.system', 'admin', 'IconAdjustments', null, null, 3],
            ['admin.lookups', 'admin.system', 'IconListDetails', '/admin/lookups', 'lookup.view', 1],
            ['admin.audit', 'admin.system', 'IconHistory', '/admin/audit-log', 'audit_log.view', 2],
        ];
        foreach ($menu as [$code, $parent, $icon, $route, $p, $order]) {
            MenuItem::updateOrCreate(['code' => $code], [
                'parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null,
                'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route,
                'permission_id' => $perm($p), 'sort_order' => $order, 'is_active' => true,
            ]);
        }

        // [code, type, permission, width, icon, colour]  name_key = "widget.<code>"
        foreach ([
            ['welcome', 'welcome', null, 12, 'IconHandStop', 'blue'],
            ['stat_users', 'stat_card', 'user.view', 3, 'IconUsers', 'blue'],
            ['stat_roles', 'stat_card', 'role.view', 3, 'IconKey', 'teal'],
            ['stat_departments', 'stat_card', 'department.view', 3, 'IconSitemap', 'green'],
            ['stat_audit_today', 'stat_card', 'audit_log.view', 3, 'IconHistory', 'orange'],
            ['chart_users_by_role', 'donut_chart', 'user.view', 6, null, null],
            ['chart_activity_7d', 'bar_chart', 'audit_log.view', 6, null, null],
            ['list_recent_activity', 'list', 'audit_log.view', 12, null, null],
        ] as [$code, $type, $p, $w, $icon, $color]) {
            DashboardWidget::updateOrCreate(['code' => $code], [
                'name_key' => "widget.$code", 'widget_type_id' => Lookup::id('widget_type', $type),
                'permission_id' => $perm($p), 'default_width' => $w, 'icon' => $icon, 'color' => $color, 'is_active' => true,
            ]);
        }
    }
}
