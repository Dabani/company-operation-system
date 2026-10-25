<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            LookupSeeder::class,
            PermissionSeeder::class,
            MenuAndWidgetSeeder::class,
            ServiceDeskSeeder::class,        // Bundle 2: case/chat lookups, bot knowledge, menu, widgets
            DemoOrganizationSeeder::class,   // generic demo tenant; remove for production installs
            ServiceDeskRolesSeeder::class,   // Bundle 2: widgets per role + admin phone (runs after roles exist)
            FilesSeeder::class,              // Bundle 3: file lookups, classification levels, menu, widgets, role grants
            ITOperationsSeeder::class,       // Bundle 4: assets, movements, access register, incidents, IT manager role
            ArchiveSeeder::class,            // Bundle 5: records, retention, disposal, legal references, records manager + compliance officer roles
            ComplianceSeeder::class,         // Bundle 6: backups, training, compliance calendar, audits
        ]);
    }
}
