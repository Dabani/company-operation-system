<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 7b seeds (idempotent): attendance statuses and check-in methods. rw / sw are first-pass labels. */
class MeetingsAttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'meeting_attendance_status' => ['Meeting attendance status', [
                ['expected', 'gray', 'Expected', 'Attendu', 'Biteganyijwe', 'Anatarajiwa'], ['present', 'green', 'Present', 'Présent', 'Ahari', 'Yupo'],
                ['late', 'orange', 'Late', 'En retard', 'Yatinze', 'Amechelewa'], ['online', 'blue', 'Online', 'En ligne', 'Ku murongo', 'Mtandaoni'],
                ['absent', 'red', 'Absent', 'Absent', 'Ntahari', 'Hayupo'], ['excused', 'violet', 'Excused', 'Excusé', 'Yasabye imbabazi', 'Ameomba radhi'],
            ]],
            'meeting_checkin_method' => ['Meeting check-in method', [
                ['secretary', 'gray', 'Secretary', 'Secrétaire', 'Umunyamabanga', 'Katibu'], ['self', 'gray', 'Self check-in', 'Auto-enregistrement', 'Yiyandikishije', 'Amejisajili'],
                ['walk_in', 'gray', 'Walk-in', 'Sans invitation', 'Yaje atatumiwe', 'Bila mwaliko'],
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
