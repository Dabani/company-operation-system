<?php

namespace Database\Seeders;

use App\Models\ComplianceObligation;
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
use Illuminate\Support\Carbon;

/** Bundle 6 reference data. Idempotent. rw/sw labels are first-pass and need native review. */
class ComplianceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        $this->lookups();
        $this->menuAndWidgets();
        $this->roles();
        $this->obligations();
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'backup_location' => ['Backup location', [
                ['primary', 'blue', null, 'Primary site', 'Site principal', 'Ahabitse ibikuru', 'Eneo kuu'],
                ['offsite', 'teal', null, 'Secondary / off-site', 'Secondaire / hors site', 'Ahandi / hanze y\'ikigo', 'Pili / nje ya eneo'],
            ]],
            'backup_result' => ['Backup verification result', [
                ['verified_ok', 'green', null, 'Verified - OK', 'Vérifiée - OK', 'Byagenzuwe - byose ni byiza', 'Imethibitishwa - sawa'],
                ['verified_issues', 'orange', null, 'Verified - issues found', 'Vérifiée - anomalies', 'Byagenzuwe - hari ibibazo', 'Imethibitishwa - kuna matatizo'],
                ['failed', 'red', null, 'Failed / not restorable', 'Échec / non restaurable', 'Ntibyagenze neza', 'Imeshindwa / haiwezi kurejeshwa'],
            ]],
            'training_topic' => ['Training topic', [
                ['records_management', 'teal', null, 'Records management', 'Gestion des documents', 'Gucunga inyandiko', 'Usimamizi wa rekodi'],
                ['data_protection', 'red', null, 'Data protection and privacy', 'Protection des données', 'Kurinda amakuru bwite', 'Ulinzi wa data na faragha'],
                ['it_usage', 'blue', null, 'IT usage and security', 'Usage et sécurité informatique', 'Gukoresha IT n\'umutekano', 'Matumizi na usalama wa TEHAMA'],
                ['access_security', 'indigo', null, 'Access control and passwords', 'Contrôle d\'accès et mots de passe', 'Kugenzura uburenganzira n\'amagambobanga', 'Udhibiti wa ufikiaji na nenosiri'],
                ['incident_reporting', 'orange', null, 'Incident reporting', 'Signalement des incidents', 'Gutanga raporo z\'ibyabaye', 'Kuripoti matukio'],
                ['compliance_overview', 'grape', null, 'Compliance and enforcement', 'Conformité et sanctions', 'Kubahiriza amategeko', 'Utiifu na utekelezaji'],
                ['onboarding', 'green', null, 'Onboarding', 'Intégration', 'Kwakira abashya', 'Kuwakaribisha wapya'],
                ['other', 'gray', null, 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'training_status' => ['Training status', [
                ['planned', 'blue', null, 'Planned', 'Planifiée', 'Giteganyijwe', 'Imepangwa'],
                ['held', 'green', null, 'Held', 'Tenue', 'Cyabaye', 'Imefanyika'],
                ['cancelled', 'gray', null, 'Cancelled', 'Annulée', 'Cyahagaritswe', 'Imeghairiwa'],
            ]],
            'obligation_frequency' => ['Frequency', [
                ['weekly', 'blue', null, 'Weekly', 'Hebdomadaire', 'Buri cyumweru', 'Kila wiki'],
                ['monthly', 'cyan', null, 'Monthly', 'Mensuelle', 'Buri kwezi', 'Kila mwezi'],
                ['quarterly', 'teal', null, 'Quarterly', 'Trimestrielle', 'Buri gihembwe', 'Kila robo mwaka'],
                ['semi_annual', 'orange', null, 'Every six months', 'Semestrielle', 'Buri mezi atandatu', 'Kila miezi sita'],
                ['annual', 'grape', null, 'Yearly', 'Annuelle', 'Buri mwaka', 'Kila mwaka'],
            ]],
            'audit_status' => ['Audit status', [
                ['planned', 'blue', null, 'Planned', 'Planifié', 'Giteganyijwe', 'Imepangwa'],
                ['in_progress', 'orange', null, 'In progress', 'En cours', 'Kirimo gukorwa', 'Inaendelea'],
                ['completed', 'teal', null, 'Completed', 'Terminé', 'Cyarangiye', 'Imekamilika'],
                ['reported', 'green', null, 'Reported to management', 'Rapporté à la direction', 'Cyagejejwe ku buyobozi', 'Imeripotiwa kwa uongozi'],
            ]],
            'finding_status' => ['Finding status', [
                ['open', 'red', null, 'Open', 'Ouvert', 'Gifunguye', 'Wazi'],
                ['in_progress', 'orange', null, 'In progress', 'En cours', 'Kirimo gukemurwa', 'Inaendelea'],
                ['resolved', 'green', null, 'Resolved', 'Résolu', 'Cyakemutse', 'Imetatuliwa'],
                ['accepted_risk', 'gray', null, 'Risk accepted', 'Risque accepté', 'Ibyago byemewe', 'Hatari imekubaliwa'],
            ]],
            'report_kind' => ['Management report kind', [
                ['training', 'teal', null, 'Training report', 'Rapport de formation', 'Raporo y\'amahugurwa', 'Ripoti ya mafunzo'],
                ['audit', 'red', null, 'Audit report', 'Rapport d\'audit', 'Raporo y\'igenzura', 'Ripoti ya ukaguzi'],
                ['other', 'gray', null, 'Other', 'Autre', 'Indi', 'Nyingine'],
            ]],
            'file_link_type' => ['File link type', [
                ['backup_verification', 'teal', null, 'Backup verification evidence', 'Preuve de vérification de sauvegarde', 'Gihamya cyo kugenzura kopi', 'Ushahidi wa uhakiki wa nakala rudufu'],
            ]],
        ];

        foreach ($data as $typeCode => [$name, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $name, 'is_system' => true]);
            $offset = $typeCode === 'file_link_type' ? 20 : 0;
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

    private function menuAndWidgets(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;
        $parent = MenuItem::where('code', 'compliance')->value('id');
        foreach ([
            ['compliance.calendar', 'IconCalendarEvent', '/compliance/calendar', 'compliance.view', 0],
            ['compliance.backups', 'IconDatabase', '/compliance/backups', 'compliance.view', 2],
            ['compliance.training', 'IconSchool', '/compliance/training', 'training.view', 3],
            ['compliance.audits', 'IconChecklist', '/compliance/audits', 'compliance.view', 4],
            ['compliance.reports', 'IconReportAnalytics', '/compliance/reports', 'compliance.view', 5],
        ] as [$code, $icon, $route, $p, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent, 'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route, 'permission_id' => $perm($p), 'sort_order' => $order, 'is_active' => true]);
        }

        foreach ([
            ['stat_obligations_overdue', 'stat_card', 'compliance.view', 3, 'IconCalendarEvent', 'red'],
            ['stat_open_findings', 'stat_card', 'compliance.view', 3, 'IconChecklist', 'orange'],
            ['stat_backup_age', 'stat_card', 'backup.manage', 3, 'IconDatabase', 'teal'],
        ] as [$code, $type, $p, $w, $icon, $color]) {
            DashboardWidget::updateOrCreate(['code' => $code], ['name_key' => "widget.$code", 'widget_type_id' => Lookup::id('widget_type', $type),
                'permission_id' => $perm($p), 'default_width' => $w, 'icon' => $icon, 'color' => $color, 'is_active' => true]);
        }
    }

    private function roles(): void
    {
        $all = Permission::pluck('id', 'code');
        $ids = fn (array $codes) => collect($codes)->map(fn ($c) => $all[$c])->all();
        $w = DashboardWidget::pluck('id', 'code');
        $sync = fn ($role, array $codes) => $role?->widgets()->syncWithoutDetaching(collect($codes)->values()->mapWithKeys(fn ($c, $i) => [$w[$c] => ['sort_order' => 80 + $i]])->all());

        foreach (Organization::all() as $org) {
            $role = fn (string $code) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $code)->first();

            foreach (['staff', 'team_leader', 'records_manager'] as $c) $role($c)?->permissions()->syncWithoutDetaching($ids(['training.view']));
            if ($m = $role('manager')) { $m->permissions()->syncWithoutDetaching($ids(['training.view', 'report.receive'])); $sync($m, ['stat_obligations_overdue', 'stat_open_findings']); }
            if ($it = $role('it_manager')) { $it->permissions()->syncWithoutDetaching($ids(['backup.manage', 'training.view', 'compliance.view'])); $sync($it, ['stat_backup_age', 'stat_obligations_overdue']); }
            if ($co = $role('compliance_officer')) { $co->permissions()->syncWithoutDetaching($ids(['training.view', 'training.manage', 'report.receive'])); $sync($co, ['stat_obligations_overdue', 'stat_open_findings', 'stat_backup_age']); }
            if ($a = $role('admin')) { $a->permissions()->syncWithoutDetaching($ids(['backup.manage', 'training.view', 'training.manage', 'report.receive', 'compliance.view', 'compliance.manage'])); $sync($a, ['stat_obligations_overdue', 'stat_open_findings', 'stat_backup_age']); }
        }
    }

    /** Generic recurring duties. Each organization edits titles, owners and dates; nothing here is law-specific. */
    private function obligations(): void
    {
        $quarterEnd = today()->endOfQuarter()->startOfDay();
        $halfEnd = today()->month <= 6 ? Carbon::create(today()->year, 6, 30) : Carbon::create(today()->year, 12, 31);
        $yearEnd = Carbon::create(today()->year, 12, 31);

        $defs = [
            ['backup_verification', 'Verify the latest full backup', 'weekly', 'it_manager', today()->next(Carbon::MONDAY), '/compliance/backups', 'Check that the most recent full backup completed and can be restored; record it in the Backup Verification Register.'],
            ['records_inventory', 'Inventory of physical records', 'quarterly', 'records_manager', $quarterEnd, '/archive/inventory', 'Count the physical archive, document discrepancies and report them to the compliance role.'],
            ['training_quarterly', 'Mandatory staff awareness training', 'quarterly', 'compliance_officer', $quarterEnd, '/compliance/training', 'Hold the quarterly training (records management, data protection, IT usage), record attendance and report to senior management.'],
            ['compliance_audit', 'Compliance audit of archiving and IT practices', 'semi_annual', 'compliance_officer', $halfEnd, '/compliance/audits', 'Audit adherence to the archiving and IT policies and report the findings to senior management.'],
            ['policy_review', 'Review policies and procedures', 'annual', 'compliance_officer', $yearEnd, null, 'Review the policies for changes in law, operations or systems; communicate updates and archive superseded versions.'],
            ['access_review', 'Review of system access rights', 'annual', 'it_manager', $yearEnd, '/access-register', 'Confirm every access grant is still needed (least privilege); revoke what is not.'],
        ];

        foreach (Organization::all() as $org) {
            foreach ($defs as [$code, $title, $freq, $roleCode, $due, $route, $desc]) {
                ComplianceObligation::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => $code], [
                    'title' => $title, 'description' => '<p>' . e($desc) . '</p>', 'frequency_id' => Lookup::id('obligation_frequency', $freq),
                    'responsible_role_id' => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $roleCode)->value('id'),
                    'link_route' => $route, 'next_due_on' => $due->toDateString(), 'is_active' => true,
                ]);
            }
        }
    }
}
