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
 * COPS Phase 7e seeds (idempotent): event lookups, permissions, role grants and menu.
 * rw / sw labels are first-pass: have native speakers review them.
 */
class EventsSeeder extends Seeder
{
    private const PERMS = [
        'event.view' => 'See published events, register myself and see my own registrations',
        'event.create' => 'Create events and run the ones I organise (registrations, check-in, expenses)',
        'event.manage_all' => 'Run any event of the organization (organizer-level rights, including drafts)',
    ];

    private const GRANTS = [
        'staff' => ['event.view'],
        'team_leader' => ['event.view', 'event.create'],
        'manager' => ['event.view', 'event.create'],
        'hr_manager' => ['event.view', 'event.create', 'event.manage_all'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'events'), 'name' => $name]);
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
        $parent = MenuItem::where('code', 'meetings')->first();      // the Meetings group is created by the Phase 7a seeder
        if ($parent) {
            MenuItem::updateOrCreate(['code' => 'meetings.events'], ['parent_id' => $parent->id, 'label_key' => 'menu.meetings.events', 'icon' => 'IconConfetti', 'route' => '/events',
                'permission_id' => Permission::where('code', 'event.view')->value('id'), 'sort_order' => 5, 'is_active' => true]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'permission_group' => ['Permission group', [['events', 'violet', 'Events', 'Événements', 'Ibirori', 'Matukio']]],
            'event_type' => ['Event type', [
                ['training', 'blue', 'Training', 'Formation', 'Amahugurwa', 'Mafunzo'], ['conference', 'violet', 'Conference', 'Conférence', 'Inama mpuzamahanga', 'Kongamano'],
                ['workshop', 'teal', 'Workshop', 'Atelier', 'Ihuriro ry\'imyitozo', 'Warsha'], ['social', 'pink', 'Social event', 'Événement convivial', 'Ibirori by\'imyidagaduro', 'Tukio la kijamii'],
                ['open_day', 'orange', 'Open day', 'Journée portes ouvertes', 'Umunsi w\'imiryango ifunguye', 'Siku ya wazi'], ['other', 'gray', 'Other', 'Autre', 'Ikindi', 'Nyingine'],
            ]],
            'event_status' => ['Event status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['open', 'green', 'Open for registration', 'Inscriptions ouvertes', 'Kwiyandikisha birakinguye', 'Usajili umefunguliwa'],
                ['closed', 'orange', 'Registration closed', 'Inscriptions clôturées', 'Kwiyandikisha byafunzwe', 'Usajili umefungwa'], ['in_progress', 'blue', 'In progress', 'En cours', 'Birimo kuba', 'Linaendelea'],
                ['completed', 'teal', 'Completed', 'Terminé', 'Byarangiye', 'Limekamilika'], ['cancelled', 'red', 'Cancelled', 'Annulé', 'Byahagaritswe', 'Limeghairiwa'],
            ]],
            'event_registration_status' => ['Event registration status', [
                ['registered', 'green', 'Registered', 'Inscrit', 'Yiyandikishije', 'Amesajiliwa'], ['waitlisted', 'orange', 'Waitlisted', 'Liste d\'attente', 'Ku rutonde rw\'abategereje', 'Orodha ya kusubiri'],
                ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'event_checkin_method' => ['Event check-in method', [
                ['ticket', 'blue', 'Ticket scan', 'Billet scanné', 'Itike yasuzumwe', 'Tiketi imeskaniwa'], ['manual', 'gray', 'Marked by organizer', 'Pointé par l\'organisateur', 'Yashyizweho n\'uwateguye', 'Imewekwa na mwandalizi'],
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
