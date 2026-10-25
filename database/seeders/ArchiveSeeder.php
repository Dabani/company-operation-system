<?php

namespace Database\Seeders;

use App\Models\DashboardWidget;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\RetentionRule;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** Bundle 5 reference data. Idempotent. rw/sw labels are first-pass and need native review. */
class ArchiveSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        $this->lookups();
        $this->defaultRetention();
        $this->menuAndWidgets();
        $this->roles();
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'record_category' => ['Record category', [
                ['contract', 'blue', null, 'Contract / agreement', 'Contrat / accord', 'Amasezerano', 'Mkataba / makubaliano'],
                ['audit_working_paper', 'grape', null, 'Audit working paper', 'Dossier de travail d\'audit', 'Impapuro z\'akazi k\'igenzura', 'Karatasi za kazi za ukaguzi'],
                ['tax_record', 'orange', null, 'Tax record', 'Document fiscal', 'Inyandiko z\'imisoro', 'Rekodi ya kodi'],
                ['financial_record', 'green', null, 'Financial record', 'Document financier', 'Inyandiko z\'imari', 'Rekodi ya fedha'],
                ['hr_record', 'pink', null, 'HR record', 'Dossier RH', 'Inyandiko z\'abakozi', 'Rekodi ya rasilimali watu'],
                ['client_correspondence', 'cyan', null, 'Client correspondence', 'Correspondance client', 'Ibaruwa z\'abakiriya', 'Mawasiliano ya wateja'],
                ['policy_document', 'indigo', null, 'Policy / procedure', 'Politique / procédure', 'Politiki / uburyo', 'Sera / utaratibu'],
                ['other', 'gray', null, 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'record_format' => ['Record format', [
                ['physical', 'orange', null, 'Physical (paper)', 'Physique (papier)', 'Impapuro', 'Karatasi'],
                ['digital', 'blue', null, 'Digital', 'Numérique', 'Mu buryo bwa mudasobwa', 'Dijitali'],
                ['hybrid', 'teal', null, 'Physical and digital', 'Physique et numérique', 'Impapuro na mudasobwa', 'Karatasi na dijitali'],
            ]],
            'record_status' => ['Record status', [
                ['active', 'green', null, 'Active', 'Actif', 'Ikora', 'Inatumika'],
                ['inactive', 'yellow', null, 'Inactive (awaiting transfer)', 'Inactif (en attente de transfert)', 'Ntikora (itegereje kwimurirwa)', 'Haitumiki (inasubiri uhamisho)'],
                ['archived', 'blue', null, 'Archived', 'Archivé', 'Yabitswe mu bubiko', 'Imehifadhiwa'],
                ['disposal_pending', 'orange', null, 'Disposal pending', 'Élimination en cours', 'Gusibwa biratunganywa', 'Uharibifu unasubiri'],
                ['disposed', 'gray', null, 'Disposed', 'Éliminé', 'Yasibwe', 'Imeharibiwa'],
            ]],
            'record_event_type' => ['Record event type', [
                ['created', 'blue', null, 'Registered', 'Enregistré', 'Yanditswe', 'Imesajiliwa'],
                ['updated', 'gray', null, 'Updated', 'Mis à jour', 'Yavuguruwe', 'Imesasishwa'],
                ['marked_inactive', 'yellow', null, 'Marked inactive', 'Marqué inactif', 'Yashyizwe mu zidakora', 'Imewekwa kutotumika'],
                ['transferred', 'blue', null, 'Transferred to the archive', 'Transféré aux archives', 'Yimuriwe mu bubiko', 'Imehamishiwa hifadhini'],
                ['moved', 'cyan', null, 'Location changed', 'Emplacement modifié', 'Ahantu hahindutse', 'Eneo limebadilika'],
                ['retention_changed', 'orange', null, 'Retention date changed', 'Date de conservation modifiée', 'Itariki yo kubika yahindutse', 'Tarehe ya kuhifadhi imebadilika'],
                ['hold_placed', 'red', null, 'Legal hold placed', 'Conservation légale activée', 'Yashyizwe mu kubungabunga n\'amategeko', 'Zuio la kisheria limewekwa'],
                ['hold_released', 'green', null, 'Legal hold released', 'Conservation légale levée', 'Kubungabunga n\'amategeko byakuweho', 'Zuio la kisheria limeondolewa'],
                ['access_authorized', 'teal', null, 'Access authorised', 'Accès autorisé', 'Kwinjira byemejwe', 'Ufikiaji umeidhinishwa'],
                ['returned', 'gray', null, 'Returned to the archive', 'Retourné aux archives', 'Yagaruwe mu bubiko', 'Imerudishwa hifadhini'],
                ['disposal_proposed', 'orange', null, 'Proposed for disposal', 'Proposé pour élimination', 'Yatanzwe ngo isibwe', 'Imependekezwa kuharibiwa'],
                ['disposal_rejected', 'gray', null, 'Disposal rejected', 'Élimination rejetée', 'Gusiba byanzwe', 'Uharibifu umekataliwa'],
                ['disposed', 'gray', null, 'Disposed', 'Éliminé', 'Yasibwe', 'Imeharibiwa'],
            ]],
            'access_log_status' => ['Archive access status', [
                ['requested', 'orange', null, 'Requested', 'Demandé', 'Byasabwe', 'Imeombwa'],
                ['authorized', 'green', null, 'Authorised (out)', 'Autorisé (sorti)', 'Byemejwe (byasohotse)', 'Imeidhinishwa (nje)'],
                ['denied', 'red', null, 'Denied', 'Refusé', 'Byanzwe', 'Imekataliwa'],
                ['returned', 'gray', null, 'Returned', 'Retourné', 'Byagarutse', 'Imerudishwa'],
            ]],
            'inventory_status' => ['Inventory status', [
                ['in_progress', 'orange', null, 'In progress', 'En cours', 'Birimo gukorwa', 'Inaendelea'],
                ['completed', 'green', null, 'Completed', 'Terminé', 'Byarangiye', 'Imekamilika'],
            ]],
            'disposal_status' => ['Disposal batch status', [
                ['pending', 'orange', null, 'Awaiting approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['approved', 'green', null, 'Approved', 'Approuvé', 'Yemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', null, 'Rejected', 'Rejeté', 'Yanzwe', 'Imekataliwa'],
                ['executed', 'gray', null, 'Executed', 'Exécuté', 'Yarakozwe', 'Imetekelezwa'],
            ]],
            'record_disposal_action' => ['Disposal action', [
                ['destroy', 'red', null, 'Destroy', 'Détruire', 'Gusiba burundu', 'Haribu'],
                ['review', 'orange', null, 'Review before disposal', 'Réexaminer avant élimination', 'Suzuma mbere yo gusiba', 'Kagua kabla ya kuharibu'],
                ['transfer', 'blue', null, 'Transfer to permanent archive', 'Transférer aux archives permanentes', 'Imurira mu bubiko buhoraho', 'Hamishia hifadhi ya kudumu'],
            ]],
            'legal_status' => ['Legal reference status', [
                ['verified', 'green', null, 'Verified', 'Vérifié', 'Byemejwe', 'Imethibitishwa'],
                ['unreviewed', 'orange', null, 'Not yet verified', 'Non vérifié', 'Ntibirasuzumwa', 'Haijathibitishwa'],
                ['superseded', 'gray', null, 'Superseded', 'Remplacé', 'Byasimbuwe', 'Imebadilishwa'],
            ]],
            'file_link_type' => ['File link type', [
                ['record', 'teal', null, 'Archive record', 'Document d\'archive', 'Inyandiko y\'ububiko', 'Rekodi ya kumbukumbu'],
            ]],
        ];

        foreach ($data as $typeCode => [$name, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $name, 'is_system' => true]);
            $offset = $typeCode === 'file_link_type' ? 10 : 0;
            foreach ($rows as $i => [$code, $color, $icon, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => $icon, 'sort_order' => $offset + $i, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->firstOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }

    /** Shared default: keep every category 10 years, then destroy. Organizations override per category (and should confirm with counsel). */
    private function defaultRetention(): void
    {
        foreach (LookupValue::whereNull('organization_id')->whereHas('type', fn ($t) => $t->where('code', 'record_category'))->get() as $cat) {
            RetentionRule::firstOrCreate(['organization_id' => null, 'category_id' => $cat->id],
                ['retention_years' => 10, 'disposal_action_id' => Lookup::id('record_disposal_action', 'destroy'), 'notes' => 'Default - adjust to your legal and professional obligations']);
        }
    }

    private function menuAndWidgets(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;
        foreach ([
            ['archive', null, 'IconArchive', null, null, 12],
            ['archive.records', 'archive', 'IconFolders', '/archive/records', 'archive.view', 1],
            ['archive.access', 'archive', 'IconDoorEnter', '/archive/access', 'archive.view', 2],
            ['archive.inventory', 'archive', 'IconClipboardCheck', '/archive/inventory', 'archive.manage', 3],
            ['archive.disposal', 'archive', 'IconTrashX', '/archive/disposal', 'archive.view', 4],
            ['archive.retention', 'archive', 'IconCalendarTime', '/archive/retention', 'archive.view', 5],
            ['compliance', null, 'IconShieldCheck', null, null, 18],
            ['compliance.legal', 'compliance', 'IconScale', '/compliance/legal', 'archive.view', 1],
        ] as [$code, $parent, $icon, $route, $p, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null,
                'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route, 'permission_id' => $perm($p), 'sort_order' => $order, 'is_active' => true]);
        }

        foreach ([
            ['stat_transfer_due', 'stat_card', 'archive.manage', 3, 'IconDoorEnter', 'yellow'],
            ['stat_disposal_due', 'stat_card', 'archive.manage', 3, 'IconTrashX', 'orange'],
            ['chart_records_by_status', 'donut_chart', 'archive.view', 6, null, null],
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
        $archiveWidgets = fn () => [$widget['stat_transfer_due'] => ['sort_order' => 70], $widget['stat_disposal_due'] => ['sort_order' => 71], $widget['chart_records_by_status'] => ['sort_order' => 72]];

        foreach (Organization::all() as $org) {
            $role = fn (string $code) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $code)->first();
            $common = ['dashboard.view', 'case.create', 'case.view_own', 'case.comment', 'chat.use', 'file.upload', 'file.view', 'file.download', 'department.view', 'location.view', 'lookup.view'];

            $rm = Role::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'records_manager'], ['name' => 'Records manager', 'level' => 45, 'color' => 'teal', 'is_system' => true, 'is_active' => true]);
            if ($rm->wasRecentlyCreated) {
                $rm->permissions()->sync($ids(array_merge($common, ['archive.view', 'archive.manage', 'file.replace', 'file.archive', 'file.delete_request', 'location.manage', 'report.view', 'data.export', 'data.import', 'compliance.view'])));
                $rm->widgets()->sync($archiveWidgets());
            }
            $co = Role::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'compliance_officer'], ['name' => 'Compliance officer', 'level' => 55, 'color' => 'red', 'is_system' => true, 'is_active' => true]);
            if ($co->wasRecentlyCreated) {
                $co->permissions()->sync($ids(array_merge($common, ['compliance.view', 'compliance.manage', 'archive.view', 'archive.approve_disposal', 'audit_log.view', 'report.view', 'report.export', 'data.export',
                    'user.view', 'access.view', 'asset.view', 'case.view_all', 'incident.manage', 'chat.moderate'])));
                $co->widgets()->sync($archiveWidgets());
            }

            if ($m = $role('manager')) {
                $m->permissions()->syncWithoutDetaching($ids(['compliance.view']));
                $m->widgets()->syncWithoutDetaching($archiveWidgets());
            }
            if ($a = $role('admin')) {
                $a->permissions()->syncWithoutDetaching($ids(['compliance.view', 'compliance.manage', 'archive.view', 'archive.manage', 'archive.approve_disposal']));
                $a->widgets()->syncWithoutDetaching($archiveWidgets());
            }
        }
    }
}
