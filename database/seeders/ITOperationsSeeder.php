<?php

namespace Database\Seeders;

use App\Models\DashboardWidget;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Bundle 4 reference data. Idempotent. rw/sw labels are first-pass and need native review. */
class ITOperationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        $this->lookups();
        $this->severityRules();
        $this->menuAndWidgets();
        $this->roles();
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $same = fn ($code, $icon, $en) => [$code, 'gray', $icon, $en, $en, $en, $en];
        $data = [
            'asset_type' => ['Asset type', [
                ['laptop', 'blue', null, 'Laptop', 'Ordinateur portable', 'Mudasobwa igendanwa', 'Kompyuta mpakato'],
                ['desktop', 'blue', null, 'Desktop computer', 'Ordinateur de bureau', 'Mudasobwa y\'ameza', 'Kompyuta ya mezani'],
                ['server', 'grape', null, 'Server', 'Serveur', 'Seriveri', 'Seva'],
                ['monitor', 'cyan', null, 'Monitor', 'Écran', 'Ekara', 'Skrini'],
                ['printer', 'teal', null, 'Printer / scanner', 'Imprimante / scanner', 'Printer / scanner', 'Printa / skana'],
                ['network_device', 'indigo', null, 'Network device', 'Équipement réseau', 'Igikoresho cya murandasi', 'Kifaa cha mtandao'],
                ['mobile_device', 'green', null, 'Phone / tablet', 'Téléphone / tablette', 'Telefoni / tablet', 'Simu / kompyuta kibao'],
                ['storage_device', 'orange', null, 'Storage / backup device', 'Support de stockage / sauvegarde', 'Igikoresho cyo kubika', 'Kifaa cha hifadhi'],
                ['peripheral', 'gray', null, 'Peripheral', 'Périphérique', 'Igikoresho cyunganira', 'Kifaa cha ziada'],
                ['other', 'gray', null, 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'asset_status' => ['Asset status', [
                ['in_stock', 'blue', null, 'In stock', 'En stock', 'Mu bubiko', 'Ghalani'],
                ['assigned', 'green', null, 'Assigned', 'Attribué', 'Cyahawe umuntu', 'Imekabidhiwa'],
                ['in_repair', 'orange', null, 'In repair', 'En réparation', 'Kirasanwa', 'Inarekebishwa'],
                ['off_site', 'cyan', null, 'Off site', 'Hors site', 'Hanze y\'ikigo', 'Nje ya eneo'],
                ['lost', 'red', null, 'Lost', 'Perdu', 'Cyabuze', 'Imepotea'],
                ['stolen', 'red', null, 'Stolen', 'Volé', 'Cyibwe', 'Imeibiwa'],
                ['retired', 'gray', null, 'Retired', 'Retiré', 'Cyakuwe mu mikoreshereze', 'Imestaafu'],
            ]],
            'asset_condition' => ['Asset condition', [
                ['new', 'green', null, 'New', 'Neuf', 'Gishya', 'Mpya'],
                ['good', 'teal', null, 'Good', 'Bon', 'Meza', 'Nzuri'],
                ['fair', 'yellow', null, 'Fair', 'Correct', 'Birashimishije', 'Wastani'],
                ['poor', 'orange', null, 'Poor', 'Médiocre', 'Bibi', 'Hafifu'],
                ['damaged', 'red', null, 'Damaged', 'Endommagé', 'Cyangiritse', 'Imeharibika'],
            ]],
            'asset_event_type' => ['Asset event type', [
                ['registered', 'blue', null, 'Registered', 'Enregistré', 'Cyanditswe', 'Imesajiliwa'],
                ['assigned', 'green', null, 'Assigned', 'Attribué', 'Cyahawe', 'Imekabidhiwa'],
                ['returned', 'gray', null, 'Returned to stock', 'Retourné en stock', 'Cyasubijwe mu bubiko', 'Imerudishwa ghalani'],
                ['location_changed', 'cyan', null, 'Location changed', 'Emplacement modifié', 'Ahantu hahindutse', 'Eneo limebadilika'],
                ['status_changed', 'orange', null, 'Status changed', 'Statut modifié', 'Imiterere yahindutse', 'Hali imebadilika'],
                ['condition_changed', 'yellow', null, 'Condition changed', 'État modifié', 'Imimerere yahindutse', 'Hali ya kifaa imebadilika'],
                ['movement_out', 'cyan', null, 'Left the premises', 'Sorti des locaux', 'Cyasohotse mu kigo', 'Kimetoka nje ya majengo'],
                ['movement_back', 'teal', null, 'Back on the premises', 'Retourné dans les locaux', 'Cyagarutse mu kigo', 'Kimerudi majengoni'],
                ['reported_lost', 'red', null, 'Reported lost / stolen', 'Signalé perdu / volé', 'Cyatangajwe ko cyabuze / cyibwe', 'Imeripotiwa kupotea / kuibwa'],
            ]],
            'movement_status' => ['Equipment movement status', [
                ['pending', 'orange', null, 'Awaiting approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['approved', 'green', null, 'Approved', 'Approuvé', 'Yemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', null, 'Rejected', 'Rejeté', 'Yanzwe', 'Imekataliwa'],
                ['checked_out', 'cyan', null, 'Out of the premises', 'Hors des locaux', 'Hanze y\'ikigo', 'Nje ya majengo'],
                ['returned', 'gray', null, 'Returned', 'Retourné', 'Byagarutse', 'Imerudishwa'],
                ['cancelled', 'gray', null, 'Cancelled', 'Annulé', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            // Examples only: every organization adds its own systems under Administration > Lookup tables.
            'system_resource' => ['System / resource', [
                ['email', 'blue', null, 'Email', 'Messagerie', 'Imeri', 'Barua pepe'],
                ['file_storage', 'teal', null, 'File storage', 'Stockage de fichiers', 'Ububiko bw\'amadosiye', 'Hifadhi ya faili'],
                ['accounting', 'green', null, 'Accounting / ERP', 'Comptabilité / ERP', 'Ibaruramari / ERP', 'Uhasibu / ERP'],
                ['vpn', 'indigo', null, 'VPN / remote access', 'VPN / accès à distance', 'VPN / kwinjira kure', 'VPN / ufikiaji wa mbali'],
                ['hr_system', 'grape', null, 'HR system', 'Système RH', 'Sisitemu y\'abakozi', 'Mfumo wa rasilimali watu'],
                ['network', 'orange', null, 'Network / servers', 'Réseau / serveurs', 'Murandasi / seriveri', 'Mtandao / seva'],
            ]],
            'access_level' => ['Access level', [
                ['read', 'blue', null, 'Read only', 'Lecture seule', 'Gusoma gusa', 'Kusoma tu'],
                ['write', 'teal', null, 'Read and write', 'Lecture et écriture', 'Gusoma no kwandika', 'Kusoma na kuandika'],
                ['admin', 'orange', null, 'Administrator', 'Administrateur', 'Umuyobozi wa sisitemu', 'Msimamizi'],
            ]],
            'incident_severity' => ['Incident severity', [
                ['low', 'gray', null, 'Low', 'Faible', 'Gito', 'Ndogo'],
                ['medium', 'yellow', null, 'Medium', 'Moyenne', 'Hagati', 'Wastani'],
                ['high', 'orange', null, 'High', 'Élevée', 'Hejuru', 'Juu'],
                ['critical', 'red', null, 'Critical', 'Critique', 'Ikomeye cyane', 'Muhimu sana'],
            ]],
        ];

        foreach ($data as $typeCode => [$name, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $name, 'is_system' => true]);
            foreach ($rows as $i => [$code, $color, $icon, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => $icon, 'sort_order' => $i, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->firstOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }

    /** Defaults mirror a typical policy (contain within 2 hours, root cause within 5 business days). Edit per severity. */
    private function severityRules(): void
    {
        foreach (['low', 'medium', 'high', 'critical'] as $code) {
            DB::table('incident_severity_rules')->updateOrInsert(['severity_id' => Lookup::id('incident_severity', $code)], ['containment_hours' => 2, 'rca_business_days' => 5]);
        }
    }

    private function menuAndWidgets(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;
        // [code, parent, icon, route, permission, order]  label_key = menu.<code>
        foreach ([
            ['it', null, 'IconServer2', null, null, 15],
            ['it.assets', 'it', 'IconDeviceLaptop', null, null, 1],
            ['it.assets.register', 'it.assets', 'IconListDetails', '/assets', 'asset.view', 1],
            ['it.assets.movements', 'it.assets', 'IconTruckDelivery', '/assets/movements', 'asset.request_movement', 2],
            ['it.access', 'it', 'IconUserShield', '/access-register', 'access.view', 2],
            ['it.incidents', 'it', 'IconAlertTriangle', '/incidents', 'case.view_own', 3],
        ] as [$code, $parent, $icon, $route, $p, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null,
                'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route, 'permission_id' => $perm($p), 'sort_order' => $order, 'is_active' => true]);
        }

        foreach ([
            ['stat_assets', 'stat_card', 'asset.manage', 3, 'IconDeviceLaptop', 'teal'],
            ['stat_overdue_equipment', 'stat_card', 'asset.manage', 3, 'IconClockExclamation', 'orange'],
            ['stat_open_incidents', 'stat_card', 'incident.manage', 3, 'IconAlertTriangle', 'red'],
            ['chart_assets_by_status', 'donut_chart', 'asset.manage', 6, null, null],
        ] as [$code, $type, $p, $w, $icon, $color]) {
            DashboardWidget::updateOrCreate(['code' => $code], ['name_key' => "widget.$code", 'widget_type_id' => Lookup::id('widget_type', $type),
                'permission_id' => $perm($p), 'default_width' => $w, 'icon' => $icon, 'color' => $color, 'is_active' => true]);
        }
    }

    private function roles(): void
    {
        $all = Permission::pluck('id', 'code');
        $ids = fn (array $codes) => collect($codes)->map(fn ($c) => $all[$c])->all();
        $widget = DashboardWidget::pluck('id', 'code');

        foreach (Organization::all() as $org) {
            $role = fn (string $code) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $code)->first();

            // New optional standard role. Created once; later edits by the organization are never overwritten.
            $it = Role::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'it_manager'],
                ['name' => 'IT manager', 'level' => 50, 'color' => 'indigo', 'is_system' => true, 'is_active' => true]);
            if ($it->wasRecentlyCreated) {
                $it->permissions()->sync($ids(['dashboard.view', 'case.create', 'case.view_own', 'case.view_all', 'case.comment', 'case.assign', 'case.update_status', 'case.notify_new',
                    'chat.use', 'chat.moderate', 'file.upload', 'file.view', 'file.download', 'file.replace', 'file.archive', 'file.delete_request',
                    'department.view', 'location.view', 'lookup.view', 'user.view', 'asset.view', 'asset.manage', 'asset.request_movement', 'access.view', 'access.manage',
                    'incident.manage', 'report.view', 'archive.view']));
                $it->widgets()->sync([$widget['stat_assets'] => ['sort_order' => 1], $widget['stat_open_incidents'] => ['sort_order' => 2], $widget['stat_overdue_equipment'] => ['sort_order' => 3], $widget['chart_assets_by_status'] => ['sort_order' => 4]]);
            }

            foreach (['staff', 'team_leader'] as $c) {
                $role($c)?->permissions()->syncWithoutDetaching($ids(['asset.request_movement']));
            }
            if ($m = $role('manager')) {
                $m->permissions()->syncWithoutDetaching($ids(['asset.request_movement', 'access.view', 'incident.manage']));
                $m->widgets()->syncWithoutDetaching([$widget['stat_assets'] => ['sort_order' => 60], $widget['stat_overdue_equipment'] => ['sort_order' => 61], $widget['stat_open_incidents'] => ['sort_order' => 62]]);
            }
            if ($a = $role('admin')) {
                $a->permissions()->syncWithoutDetaching($ids(['asset.request_movement', 'access.view', 'access.manage', 'incident.manage']));
                $a->widgets()->syncWithoutDetaching([$widget['stat_assets'] => ['sort_order' => 60], $widget['stat_overdue_equipment'] => ['sort_order' => 61],
                    $widget['stat_open_incidents'] => ['sort_order' => 62], $widget['chart_assets_by_status'] => ['sort_order' => 63]]);
            }
        }
    }
}
