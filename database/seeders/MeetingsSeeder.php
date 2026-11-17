<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * COPS Phase 7a seeds (idempotent): meeting lookups, permissions, role grants and menu.
 * rw / sw labels are first-pass: have native speakers review them.
 */
class MeetingsSeeder extends Seeder
{
    private const PERMS = [
        'meeting.view' => 'See the meetings I organise, am invited to, or that my scope covers; answer invitations',
        'meeting.create' => 'Create, edit, schedule and cancel meetings I organise; manage their agenda and invitations',
        'meeting.view_all' => 'See every non-confidential meeting of the organization',
        'meeting.view_confidential' => 'See confidential meetings outside my own invitations (audited)',
        'meeting.manage_all' => 'Manage any meeting of the organization (organizer-level rights)',
        'meeting.room.manage' => 'Manage the meeting rooms catalogue',
    ];

    private const GRANTS = [
        'staff' => ['meeting.view'],
        'team_leader' => ['meeting.view', 'meeting.create'],
        'manager' => ['meeting.view', 'meeting.create'],
        'hr_manager' => ['meeting.view', 'meeting.create', 'meeting.view_all', 'meeting.room.manage'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'meetings'), 'name' => $name]);
        }
        $this->menu();
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $role('admin')?->permissions()->syncWithoutDetaching(collect(array_keys(self::PERMS))->map(fn ($c) => $ids[$c])->all());
        }
    }

    private function menu(): void
    {
        $perm = fn (string $c) => Permission::where('code', $c)->value('id');
        $parent = MenuItem::updateOrCreate(['code' => 'meetings'], ['parent_id' => null, 'label_key' => 'menu.meetings', 'icon' => 'IconCalendarEvent',
            'route' => null, 'permission_id' => null, 'sort_order' => 45, 'is_active' => true]);
        foreach ([
            ['meetings.list', 'IconCalendarEvent', '/meetings', 'meeting.view', 1],
            ['meetings.rooms', 'IconDoor', '/meetings/rooms', 'meeting.room.manage', 2],
        ] as [$code, $icon, $route, $permission, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent->id, 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $perm($permission), 'sort_order' => $order, 'is_active' => true]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'permission_group' => ['Permission group', [['meetings', 'violet', 'Meetings', 'Réunions', 'Inama', 'Mikutano']]],
            'meeting_status' => ['Meeting status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['scheduled', 'blue', 'Scheduled', 'Planifiée', 'Yateganyijwe', 'Imepangwa'],
                ['in_progress', 'orange', 'In progress', 'En cours', 'Irimo kuba', 'Inaendelea'], ['held', 'teal', 'Held', 'Tenue', 'Yabaye', 'Imefanyika'],
                ['closed', 'green', 'Closed', 'Clôturée', 'Yafunzwe', 'Imefungwa'], ['cancelled', 'red', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'meeting_type' => ['Meeting type', [
                ['board', 'violet', 'Board', 'Conseil', 'Inama y\'ubuyobozi', 'Bodi'], ['executive', 'violet', 'Executive', 'Direction', 'Ubuyobozi bukuru', 'Utendaji'],
                ['departmental', 'blue', 'Departmental', 'Départementale', 'Y\'ishami', 'Idara'], ['project', 'blue', 'Project', 'Projet', 'Y\'umushinga', 'Mradi'],
                ['training', 'teal', 'Training', 'Formation', 'Amahugurwa', 'Mafunzo'], ['event', 'orange', 'Event', 'Événement', 'Igikorwa', 'Tukio'],
                ['other', 'gray', 'Other', 'Autre', 'Indi', 'Nyingine'],
            ]],
            'meeting_confidentiality' => ['Meeting confidentiality', [
                ['normal', 'gray', 'Normal', 'Normale', 'Isanzwe', 'Kawaida'], ['confidential', 'red', 'Confidential', 'Confidentielle', 'Y\'ibanga', 'Siri'],
            ]],
            'agenda_status' => ['Agenda status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['circulated', 'blue', 'Circulated', 'Diffusé', 'Yoherejwe', 'Imesambazwa'],
                ['approved', 'green', 'Approved', 'Approuvé', 'Yemejwe', 'Imeidhinishwa'],
            ]],
            'agenda_priority' => ['Agenda item priority', [
                ['low', 'gray', 'Low', 'Faible', 'Gito', 'Chini'], ['normal', 'blue', 'Normal', 'Normale', 'Isanzwe', 'Kawaida'], ['high', 'orange', 'High', 'Élevée', 'Ikomeye', 'Juu'],
            ]],
            'rsvp_response' => ['RSVP response', [
                ['pending', 'gray', 'No answer', 'Sans réponse', 'Nta gisubizo', 'Hakuna jibu'], ['accepted', 'green', 'Accepted', 'Accepté', 'Yemeye', 'Amekubali'],
                ['declined', 'red', 'Declined', 'Refusé', 'Yanze', 'Amekataa'], ['proposed', 'orange', 'Proposed another time', 'Autre horaire proposé', 'Yatanze undi mwanya', 'Amependekeza wakati mwingine'],
            ]],
            'meeting_frequency' => ['Meeting frequency', [
                ['daily', 'gray', 'Daily', 'Quotidienne', 'Buri munsi', 'Kila siku'], ['weekly', 'gray', 'Weekly', 'Hebdomadaire', 'Buri cyumweru', 'Kila wiki'], ['monthly', 'gray', 'Monthly', 'Mensuelle', 'Buri kwezi', 'Kila mwezi'],
            ]],
            'file_link_type' => ['File link type', [
                ['meeting', 'gray', 'Meeting', 'Réunion', 'Inama', 'Mkutano'], ['meeting_agenda_item', 'gray', 'Agenda item', 'Point d\'ordre du jour', 'Ingingo y\'ibigomba kuganirwaho', 'Kipengele cha ajenda'],
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
