<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 7f: lookups of online meetings (kinds and statuses of interventions). Idempotent. */
class MeetingsOnlineSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'meeting_intervention_kind' => ['Meeting intervention kind', [
                ['speak', 'blue', 'Wish to speak', 'Demande de parole', 'Nshaka kuvuga', 'Ninataka kuzungumza'], ['question', 'cyan', 'Question', 'Question', 'Ikibazo', 'Swali'],
                ['point_of_order', 'red', 'Point of order', 'Motion d\'ordre', 'Ingingo y\'imigendekere', 'Hoja ya utaratibu'], ['reply', 'violet', 'Right of reply', 'Droit de réponse', 'Uburenganzira bwo gusubiza', 'Haki ya kujibu'],
            ]],
            'meeting_intervention_status' => ['Meeting intervention status', [
                ['requested', 'blue', 'Waiting', 'En attente', 'Itegereje', 'Inasubiri'], ['speaking', 'green', 'Speaking', 'A la parole', 'Arimo kuvuga', 'Anazungumza'],
                ['done', 'gray', 'Done', 'Terminée', 'Byarangiye', 'Imekamilika'], ['dismissed', 'orange', 'Dismissed', 'Écartée', 'Yanzwe', 'Imekataliwa'], ['withdrawn', 'gray', 'Withdrawn', 'Retirée', 'Yakuweho', 'Imeondolewa'],
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
