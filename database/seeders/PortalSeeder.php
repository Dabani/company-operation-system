<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/** COPS Phase 3 seeds (idempotent): the staff menu entry for portal administration. Portal switches stay OFF until enabled per client or project. */
class PortalSeeder extends Seeder
{
    public function run(): void
    {
        MenuItem::updateOrCreate(['code' => 'admin.client_portal'], ['parent_id' => MenuItem::where('code', 'admin.system')->value('id'), 'label_key' => 'menu.admin.client_portal', 'icon' => 'IconUsersGroup',
            'route' => '/admin/client-portal', 'permission_id' => Permission::where('code', 'client.manage')->value('id'), 'sort_order' => 7, 'is_active' => true]);
    }
}
