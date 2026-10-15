<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * Global lookup values. Row = [code, colour, icon, en, fr, rw, sw].
 * rw/sw labels are first-pass translations: have native speakers review them (Lookup admin screen edits them live).
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');

        foreach ($this->data() as $typeCode => [$typeName, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $typeName, 'is_system' => true]);
            foreach ($rows as $i => [$code, $color, $icon, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(
                    ['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => $icon, 'sort_order' => $i, 'is_system' => true, 'is_active' => true]
                );
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }

    private function data(): array
    {
        $same = fn ($code, $label) => [$code, 'gray', null, $label, $label, $label, $label];
        return [
            'organization_status' => ['Organization status', [
                ['active', 'green', null, 'Active', 'Actif', 'Ikora', 'Inatumika'],
                ['suspended', 'red', null, 'Suspended', 'Suspendu', 'Yahagaritswe', 'Imesimamishwa'],
            ]],
            'user_status' => ['User status', [
                ['active', 'green', null, 'Active', 'Actif', 'Ikora', 'Inatumika'],
                ['invited', 'orange', null, 'Invited', 'Invité', 'Yatumiwe', 'Amealikwa'],
                ['suspended', 'red', null, 'Suspended', 'Suspendu', 'Yahagaritswe', 'Amesimamishwa'],
            ]],
            'membership_status' => ['Membership status', [
                ['active', 'green', null, 'Active', 'Actif', 'Ikora', 'Inatumika'],
                ['inactive', 'gray', null, 'Inactive', 'Inactif', 'Ntikora', 'Haitumiki'],
            ]],
            'audit_outcome' => ['Audit outcome', [
                ['success', 'green', null, 'Success', 'Succès', 'Byagenze neza', 'Mafanikio'],
                ['failure', 'red', null, 'Failure', 'Échec', 'Ntibyagenze neza', 'Imeshindwa'],
            ]],
            'setting_value_type' => ['Setting value type', [
                ['string', 'gray', null, 'Text', 'Texte', 'Inyandiko', 'Maandishi'],
                ['integer', 'gray', null, 'Integer', 'Entier', 'Umubare wuzuye', 'Nambari kamili'],
                ['decimal', 'gray', null, 'Decimal', 'Décimal', 'Umubare w\'icumi', 'Desimali'],
                ['boolean', 'gray', null, 'Yes / No', 'Oui / Non', 'Yego / Oya', 'Ndiyo / Hapana'],
                ['date', 'gray', null, 'Date', 'Date', 'Itariki', 'Tarehe'],
            ]],
            'widget_type' => ['Dashboard widget type', [
                ['welcome', 'blue', null, 'Welcome', 'Bienvenue', 'Murakaza neza', 'Karibu'],
                ['stat_card', 'blue', null, 'Statistic card', 'Carte statistique', 'Ikarita y\'imibare', 'Kadi ya takwimu'],
                ['bar_chart', 'green', null, 'Bar chart', 'Histogramme', 'Igishushanyo cy\'inkingi', 'Chati ya safu'],
                ['line_chart', 'green', null, 'Line chart', 'Courbe', 'Igishushanyo cy\'umurongo', 'Chati ya mstari'],
                ['donut_chart', 'green', null, 'Donut chart', 'Anneau', 'Igishushanyo cy\'uruziga', 'Chati ya duara'],
                ['list', 'gray', null, 'List', 'Liste', 'Urutonde', 'Orodha'],
            ]],
            'location_type' => ['Location type', [
                ['site', 'blue', 'IconMapPin', 'Site', 'Site', 'Ahantu', 'Eneo'],
                ['building', 'blue', 'IconBuilding', 'Building', 'Bâtiment', 'Inyubako', 'Jengo'],
                ['floor', 'teal', 'IconStairs', 'Floor', 'Étage', 'Etaje', 'Ghorofa'],
                ['room', 'teal', 'IconDoor', 'Room', 'Salle', 'Icyumba', 'Chumba'],
                ['cabinet', 'orange', 'IconArchive', 'Cabinet', 'Armoire', 'Akabati', 'Kabati'],
                ['shelf', 'orange', 'IconBooks', 'Shelf', 'Étagère', 'Ikibaho', 'Rafu'],
            ]],
            'timezone' => ['Time zone', [
                $same('Africa/Kigali', 'Africa/Kigali (CAT)'), $same('Africa/Nairobi', 'Africa/Nairobi (EAT)'),
                $same('Africa/Dar_es_Salaam', 'Africa/Dar es Salaam (EAT)'), $same('Africa/Kampala', 'Africa/Kampala (EAT)'),
                $same('Europe/Paris', 'Europe/Paris (CET)'), $same('UTC', 'UTC'),
            ]],
            'currency' => ['Currency', [
                $same('RWF', 'RWF - Rwandan franc'), $same('USD', 'USD - US dollar'), $same('EUR', 'EUR - Euro'),
                $same('KES', 'KES - Kenyan shilling'), $same('TZS', 'TZS - Tanzanian shilling'), $same('UGX', 'UGX - Ugandan shilling'),
            ]],
            'date_format' => ['Date format', [
                $same('d/m/Y', 'DD/MM/YYYY'), $same('Y-m-d', 'YYYY-MM-DD'), $same('m/d/Y', 'MM/DD/YYYY'),
            ]],
            'permission_group' => ['Permission group', [
                ['dashboard', 'blue', null, 'Dashboard', 'Tableau de bord', 'Ikibaho', 'Dashibodi'],
                ['organization', 'blue', null, 'Organization', 'Organisation', 'Ikigo', 'Shirika'],
                ['access', 'blue', null, 'Users & roles', 'Utilisateurs et rôles', 'Abakoresha n\'uruhare', 'Watumiaji na majukumu'],
                ['reference', 'blue', null, 'Reference data', 'Données de référence', 'Amakuru y\'ibanze', 'Data rejea'],
                ['audit', 'blue', null, 'Audit & data', 'Audit et données', 'Igenzura n\'amakuru', 'Ukaguzi na data'],
                ['cases', 'green', null, 'Cases & activity', 'Dossiers et activités', 'Ibibazo n\'ibikorwa', 'Kesi na shughuli'],
                ['chat', 'green', null, 'Chat', 'Chat', 'Ikiganiro', 'Gumzo'],
                ['files', 'green', null, 'Files', 'Fichiers', 'Amadosiye', 'Faili'],
                ['archive', 'green', null, 'Archive', 'Archives', 'Ububiko', 'Hifadhi'],
                ['assets', 'green', null, 'IT assets', 'Actifs informatiques', 'Ibikoresho bya IT', 'Mali za TEHAMA'],
                ['reports', 'green', null, 'Reports', 'Rapports', 'Raporo', 'Ripoti'],
            ]],
        ];
    }
}
