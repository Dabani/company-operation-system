<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['en', 'English', 'English', true, 1],
            ['fr', 'French', 'Français', false, 2],
            ['rw', 'Kinyarwanda', 'Ikinyarwanda', false, 3],
            ['sw', 'Swahili', 'Kiswahili', false, 4],
        ] as [$code, $name, $native, $default, $order]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'native_name' => $native, 'is_default' => $default, 'is_active' => true, 'sort_order' => $order]);
        }
    }
}
