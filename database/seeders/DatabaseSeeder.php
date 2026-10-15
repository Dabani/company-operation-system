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
            DemoOrganizationSeeder::class,   // generic demo tenant; remove for production installs
        ]);
    }
}
