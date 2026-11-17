<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Organization;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 7c seeds (idempotent): minutes statuses and visibilities, approval subject/step types, the chair-approval flow. rw / sw are first-pass labels. */
class MeetingsMinutesSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'minutes_status' => ['Minutes status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['approved', 'blue', 'Approved', 'Approuvé', 'Byemejwe', 'Imeidhinishwa'], ['published', 'green', 'Published', 'Publié', 'Byatangajwe', 'Imechapishwa'],
                ['superseded', 'gray', 'Superseded', 'Remplacé', 'Byasimbuwe', 'Imebadilishwa'],
            ]],
            'minutes_visibility' => ['Minutes visibility', [
                ['participants', 'gray', 'Participants only', 'Participants uniquement', 'Abitabiriye gusa', 'Washiriki pekee'], ['department', 'blue', 'Departments', 'Départements', 'Amashami', 'Idara'],
                ['organization', 'green', 'Whole organization', 'Toute l\'organisation', 'Ikigo cyose', 'Shirika zima'], ['named', 'violet', 'Named people', 'Personnes désignées', 'Abatoranyijwe', 'Watu waliotajwa'],
            ]],
            'approval_subject_type' => ['Approval subject type', [['meeting_minutes', 'gray', 'Meeting minutes', 'Procès-verbal', 'Raporo y\'inama', 'Muhtasari wa mkutano']]],
            'approval_step_type' => ['Approval step type', [['meeting_chair', 'blue', 'Meeting chair', 'Président de séance', 'Umuyobozi w\'inama', 'Mwenyekiti wa mkutano']]],
            'file_link_type' => ['File link type', [['meeting_minutes', 'gray', 'Meeting minutes', 'Procès-verbal', 'Raporo y\'inama', 'Muhtasari wa mkutano']]],
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
            MenuItem::updateOrCreate(['code' => 'meetings.minutes'], ['parent_id' => $parent->id, 'label_key' => 'menu.meetings.minutes', 'icon' => 'IconFileText', 'route' => '/minutes',
                'permission_id' => Permission::where('code', 'meeting.view')->value('id'), 'sort_order' => 3, 'is_active' => true]);
        }
        foreach (Organization::all() as $org) {
            $this->flow($org);
        }
    }

    /** The minutes are approved by the chair of the meeting; the requester can never approve, and an unresolved approver falls back to the top authority. */
    private function flow(Organization $org): void
    {
        $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'meeting_minutes', 'version' => 1], [
            'name' => 'Meeting minutes (chair approval)', 'subject_type_id' => Lookup::id('approval_subject_type', 'meeting_minutes'), 'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], [
            'organization_id' => $org->id, 'label' => 'Meeting chair', 'step_type_id' => Lookup::id('approval_step_type', 'meeting_chair'), 'escalate_after_hours' => 72,
        ]);
    }
}
