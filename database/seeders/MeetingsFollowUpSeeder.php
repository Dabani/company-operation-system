<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 7c seeds (idempotent): minutes statuses and visibilities, approval subject/step types, the chair-approval flow. rw / sw are first-pass labels. */
class MeetingsFollowUpSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'resolution_status' => ['Resolution status', [
                ['open', 'gray', 'Open', 'Ouverte', 'Ifunguye', 'Wazi'], ['in_progress', 'blue', 'In progress', 'En cours', 'Iratunganywa', 'Inaendelea'],
                ['completed', 'green', 'Completed', 'Réalisée', 'Yarangiye', 'Imekamilika'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'action_status' => ['Action status', [
                ['open', 'gray', 'Open', 'Ouverte', 'Ifunguye', 'Wazi'], ['in_progress', 'blue', 'In progress', 'En cours', 'Iratunganywa', 'Inaendelea'],
                ['done', 'teal', 'Done (to verify)', 'Faite (à vérifier)', 'Yakozwe (igomba kugenzurwa)', 'Imefanyika (kuthibitishwa)'],
                ['closed', 'green', 'Closed', 'Clôturée', 'Yafunzwe', 'Imefungwa'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'file_link_type' => ['File link type', [
                ['meeting_resolution', 'gray', 'Meeting resolution', 'Résolution de réunion', 'Umwanzuro w\'inama', 'Azimio la mkutano'],
                ['meeting_action', 'gray', 'Meeting action', 'Action de réunion', 'Igikorwa cy\'inama', 'Hatua ya mkutano'],
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
        $parent = MenuItem::where('code', 'meetings')->first();
        if ($parent) {
            MenuItem::updateOrCreate(['code' => 'meetings.followups'], ['parent_id' => $parent->id, 'label_key' => 'menu.meetings.followups', 'icon' => 'IconChecklist', 'route' => '/follow-ups',
                'permission_id' => Permission::where('code', 'meeting.view')->value('id'), 'sort_order' => 4, 'is_active' => true]);
        }
    }
}
