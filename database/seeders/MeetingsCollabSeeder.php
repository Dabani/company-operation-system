<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 7g: lookups of meeting polls (status). Idempotent. */
class MeetingsCollabSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'meeting_poll_status' => ['Meeting poll status', [
                ['open', 'green', 'Open', 'Ouvert', 'Rifunguye', 'Wazi'], ['closed', 'gray', 'Closed', 'Clos', 'Ryafunzwe', 'Imefungwa'],
            ]],
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
