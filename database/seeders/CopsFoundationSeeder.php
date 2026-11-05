<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
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
 * COPS Phase 0 seeds (idempotent): lookups, permissions, menu, role grants, default approval flows.
 * Nothing legal or financial is seeded as authoritative: no statutory values, no thresholds. With no active rule every
 * request takes the safe default flow (explicit two-level approval). rw / sw labels are first-pass: have native speakers review them.
 */
class CopsFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $this->lookups();
        $this->permissions();
        $this->menu();
        foreach (Organization::all() as $org) {
            $this->grants($org);
            $this->flows($org);
        }
    }

    /** code => [group, name]. Grouped under their own permission_group lookups. */
    public static function permissions_list(): array
    {
        return [
            'approval.view' => ['approvals', 'View own approval requests'],
            'approval.act' => ['approvals', 'Decide approvals assigned to me'],
            'approval.view_all' => ['approvals', 'Oversight of all approval requests'],
            'approval.configure' => ['approvals', 'Configure approval flows'],
            'rules.view' => ['rules', 'View rules and legal parameters'],
            'rules.configure' => ['rules', 'Draft rules, run impact checks, submit for approval'],
            'rules.approve' => ['rules', 'Approve rule activation (must not be the author)'],
            'rules.signoff' => ['rules', 'Sign off legal profiles and statutory parameters'],
            'scope.manage' => ['access', 'Assign department scope and department heads'],
            'scope.organization' => ['access', 'Organization-wide data scope'],
            'employee.view' => ['hr', 'View employees'],
            'employee.manage' => ['hr', 'Create and update employees'],
            'employee.manage_sensitive' => ['hr', 'Edit national ID and bank account'],
            'employee.view_sensitive' => ['hr', 'View national ID and bank account (audited)'],
            'client.view' => ['clients', 'View clients'],
            'client.manage' => ['clients', 'Manage clients and contacts'],
        ];
    }

    private function permissions(): void
    {
        foreach (self::permissions_list() as $code => [$group, $name]) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', $group), 'name' => $name]);
        }
    }

    private function menu(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;
        // [code, parent, icon, route, permission, order]  label_key = "menu.<code>"
        foreach ([
            ['approvals', null, 'IconChecklist', '/approvals', 'approval.view', 12],
            ['people', null, 'IconUsersGroup', null, null, 40],
            ['people.employees', 'people', 'IconId', '/hr/employees', 'employee.view', 1],
            ['clients', null, 'IconBuildingStore', '/clients', 'client.view', 45],
            ['admin.rules', 'admin.system', 'IconScale', '/admin/rules', 'rules.view', 3],
            ['admin.approval_flows', 'admin.system', 'IconRoute', '/admin/approval-flows', 'approval.configure', 4],
            ['admin.dept_access', 'admin.access', 'IconSitemap', '/admin/department-access', 'scope.manage', 3],
        ] as [$code, $parent, $icon, $route, $p, $order]) {
            MenuItem::updateOrCreate(['code' => $code], [
                'parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null, 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $perm($p), 'sort_order' => $order, 'is_active' => true,
            ]);
        }
    }

    private function grants(Organization $org): void
    {
        $ids = Permission::pluck('id', 'code');
        $grants = [
            'staff' => ['approval.view'],
            'team_leader' => ['approval.view', 'approval.act', 'employee.view', 'client.view'],
            'manager' => ['approval.view', 'approval.act', 'approval.view_all', 'rules.view', 'rules.approve', 'employee.view', 'client.view', 'client.manage'],
            'admin' => array_keys(self::permissions_list()),
        ];
        foreach ($grants as $roleCode => $codes) {
            Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $roleCode)->first()
                ?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
        }
    }

    /** Safe defaults: department head then top authority; rule changes go to a manager (a different person than the author). */
    private function flows(Organization $org): void
    {
        $role = fn (string $code) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $code)->value('id');
        $mk = function (string $code, string $name, ?string $subject, array $steps) use ($org) {
            $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => $code, 'version' => 1], [
                'name' => $name, 'subject_type_id' => $subject ? Lookup::id('approval_subject_type', $subject) : null,
                'effective_from' => '2026-01-01', 'is_active' => true,
            ]);
            foreach ($steps as $i => [$label, $type, $roleId, $hours]) {
                $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => $i + 1], [
                    'organization_id' => $org->id, 'label' => $label, 'step_type_id' => Lookup::id('approval_step_type', $type), 'role_id' => $roleId, 'escalate_after_hours' => $hours,
                ]);
            }
        };
        $mk('default_two_level', 'Default explicit approval (department head, then top authority)', null, [
            ['Department head', 'department_head', null, 48],
            ['Top authority', 'role', $role('admin'), 48],
        ]);
        $mk('rule_activation', 'Rule activation (independent approver)', 'rule_activation', [
            ['Rule approver', 'role', $role('manager') ?? $role('admin'), 72],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------------
    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        foreach ($this->data() as $typeCode => [$typeName, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $typeName, 'is_system' => true]);
            foreach ($rows as $i => [$code, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(
                    ['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => null, 'sort_order' => $i, 'is_system' => true, 'is_active' => true]
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
        return [
            'permission_group' => ['Permission group', [
                ['approvals', 'orange', 'Approvals', 'Approbations', 'Kwemeza', 'Idhini'],
                ['rules', 'orange', 'Rules & policies', 'Règles et politiques', 'Amategeko n\'amabwiriza', 'Kanuni na sera'],
                ['hr', 'blue', 'People (HR)', 'Personnel (RH)', 'Abakozi (HR)', 'Watu (HR)'],
                ['clients', 'blue', 'Clients', 'Clients', 'Abakiriya', 'Wateja'],
            ]],
            'approval_status' => ['Approval status', [
                ['pending', 'orange', 'Pending', 'En attente', 'Birategereje', 'Inasubiri'],
                ['approved', 'green', 'Approved', 'Approuvé', 'Byemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', 'Rejected', 'Rejeté', 'Byanzwe', 'Imekataliwa'],
                ['returned', 'yellow', 'Returned for changes', 'Renvoyé pour modification', 'Byasubijwe ngo bikosorwe', 'Imerudishwa kwa marekebisho'],
                ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Byahagaritswe', 'Imeghairiwa'],
            ]],
            'approval_decision' => ['Approval decision', [
                ['approve', 'green', 'Approve', 'Approuver', 'Emeza', 'Idhinisha'],
                ['reject', 'red', 'Reject', 'Rejeter', 'Anga', 'Kataa'],
                ['return', 'yellow', 'Return for changes', 'Renvoyer pour modification', 'Subiza inyuma', 'Rudisha kwa marekebisho'],
            ]],
            'approval_step_type' => ['Approval step type', [
                ['role', 'blue', 'Role', 'Rôle', 'Uruhare', 'Jukumu'],
                ['department_head', 'blue', 'Department head', 'Chef de département', 'Umukuru w\'ishami', 'Mkuu wa idara'],
                ['line_manager', 'blue', 'Line manager', 'Responsable hiérarchique', 'Umuyobozi uhita', 'Msimamizi wa moja kwa moja'],
                ['user', 'blue', 'Named person', 'Personne nommée', 'Umuntu wihariye', 'Mtu maalum'],
            ]],
            'approval_subject_type' => ['Approval subject type', [
                ['task_review', 'gray', 'Task review', 'Revue de tâche', 'Isuzuma ry\'umurimo', 'Mapitio ya kazi'],
                ['timesheet', 'gray', 'Timesheet', 'Feuille de temps', 'Urupapuro rw\'amasaha', 'Karatasi ya saa'],
                ['leave', 'gray', 'Leave request', 'Demande de congé', 'Gusaba ikiruhuko', 'Ombi la likizo'],
                ['trip', 'gray', 'Business trip', 'Mission', 'Urugendo rw\'akazi', 'Safari ya kikazi'],
                ['recruitment', 'gray', 'Recruitment', 'Recrutement', 'Gutanga akazi', 'Uajiri'],
                ['promotion', 'gray', 'Promotion', 'Promotion', 'Kuzamurwa mu ntera', 'Kupandishwa cheo'],
                ['contract', 'gray', 'Contract', 'Contrat', 'Amasezerano', 'Mkataba'],
                ['invoice', 'gray', 'Invoice', 'Facture', 'Inyemezabwishyu', 'Ankara'],
                ['payroll', 'gray', 'Payroll', 'Paie', 'Umushahara', 'Mishahara'],
                ['deletion_request', 'gray', 'Deletion request', 'Demande de suppression', 'Gusaba gusiba', 'Ombi la kufuta'],
                ['report_publication', 'gray', 'Report publication', 'Publication de rapport', 'Gutangaza raporo', 'Kuchapisha ripoti'],
                ['minutes_publication', 'gray', 'Minutes publication', 'Publication de procès-verbal', 'Gutangaza inyandiko-mvugo', 'Kuchapisha muhtasari'],
                ['procurement', 'gray', 'Procurement', 'Achats', 'Amasoko', 'Manunuzi'],
                ['rule_activation', 'gray', 'Rule activation', 'Activation de règle', 'Gukoresha itegeko', 'Kuwasha kanuni'],
            ]],
            'scope_level' => ['Data scope level', [
                ['own', 'gray', 'Own records', 'Ses propres enregistrements', 'Ibye gusa', 'Rekodi zake'],
                ['team', 'blue', 'Team (own department)', 'Équipe (son département)', 'Itsinda (ishami rye)', 'Timu (idara yake)'],
                ['department', 'blue', 'Department and sub-departments', 'Département et sous-départements', 'Ishami n\'amashami arikorera', 'Idara na idara ndogo'],
                ['organization', 'red', 'Whole organization', 'Toute l\'organisation', 'Ikigo cyose', 'Shirika zima'],
            ]],
            'rule_domain' => ['Rule domain', [
                ['approval', 'blue', 'Approval chains', 'Chaînes d\'approbation', 'Uburyo bwo kwemeza', 'Minyororo ya idhini'],
                ['procurement', 'blue', 'Procurement policy', 'Politique d\'achats', 'Politiki y\'amasoko', 'Sera ya manunuzi'],
                ['leave', 'blue', 'Leave and attendance', 'Congés et présence', 'Ikiruhuko n\'ubwitabire', 'Likizo na mahudhurio'],
                ['payroll', 'blue', 'Payroll', 'Paie', 'Umushahara', 'Mishahara'],
                ['billing', 'blue', 'Billing', 'Facturation', 'Kwishyuza', 'Utozaji'],
                ['portal', 'blue', 'Client portal', 'Portail client', 'Urubuga rw\'abakiriya', 'Lango la wateja'],
                ['records', 'blue', 'Records and retention', 'Archives et conservation', 'Ububiko n\'ikurikiranwa', 'Rekodi na uhifadhi'],
                ['notification', 'blue', 'Notifications', 'Notifications', 'Ubutumwa', 'Arifa'],
            ]],
            'rule_status' => ['Rule status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'],
                ['impact_check', 'yellow', 'Impact check', 'Contrôle d\'impact', 'Isuzuma ry\'ingaruka', 'Ukaguzi wa athari'],
                ['pending_approval', 'orange', 'Pending approval', 'En attente d\'approbation', 'Rirategereje kwemezwa', 'Inasubiri idhini'],
                ['active', 'green', 'Active', 'Actif', 'Rikora', 'Inatumika'],
                ['superseded', 'gray', 'Superseded', 'Remplacé', 'Ryasimbuwe', 'Imebadilishwa'],
                ['expired', 'gray', 'Expired', 'Expiré', 'Ryarangiye', 'Imeisha muda'],
            ]],
            'rule_attribute' => ['Rule attribute', [
                ['amount', 'blue', 'Amount', 'Montant', 'Amafaranga', 'Kiasi'],
                ['subject_type', 'blue', 'Subject type', 'Type de sujet', 'Ubwoko bw\'icyemezwa', 'Aina ya mada'],
                ['department', 'blue', 'Department', 'Département', 'Ishami', 'Idara'],
                ['method', 'blue', 'Method', 'Méthode', 'Uburyo', 'Njia'],
                ['budget_status', 'blue', 'Budget status', 'Statut budgétaire', 'Imiterere y\'ingengo y\'imari', 'Hali ya bajeti'],
                ['employee_category', 'blue', 'Employee category', 'Catégorie d\'employé', 'Icyiciro cy\'umukozi', 'Kundi la mfanyakazi'],
            ]],
            'rule_operator' => ['Rule operator', [
                ['eq', 'gray', 'equals', 'égal à', 'angana na', 'sawa na'],
                ['ne', 'gray', 'not equal to', 'différent de', 'ntiyangana na', 'si sawa na'],
                ['gte', 'gray', 'at least', 'au moins', 'nibura', 'angalau'],
                ['gt', 'gray', 'more than', 'plus de', 'birenze', 'zaidi ya'],
                ['lte', 'gray', 'at most', 'au plus', 'ntibirenza', 'si zaidi ya'],
                ['lt', 'gray', 'less than', 'moins de', 'bitageze kuri', 'chini ya'],
                ['between', 'gray', 'between', 'entre', 'hagati ya', 'kati ya'],
                ['in', 'gray', 'is one of', 'fait partie de', 'ari muri', 'ni miongoni mwa'],
            ]],
            'rule_verification_status' => ['Rule verification status', [
                ['unverified', 'red', 'Unverified', 'Non vérifié', 'Ntibyemejwe n\'amategeko', 'Haijathibitishwa'],
                ['verified', 'green', 'Verified and signed off', 'Vérifié et validé', 'Byemejwe kandi byashyizweho umukono', 'Imethibitishwa na kusainiwa'],
                ['not_required', 'gray', 'Not required', 'Non requis', 'Ntibisabwa', 'Haihitajiki'],
            ]],
            'legal_bound' => ['Statutory bound', [
                ['floor', 'blue', 'Minimum (floor)', 'Minimum (plancher)', 'Ntarengwa ntoya', 'Kiwango cha chini'],
                ['ceiling', 'blue', 'Maximum (ceiling)', 'Maximum (plafond)', 'Ntarengwa nini', 'Kiwango cha juu'],
            ]],
            'employee_status' => ['Employee status', [
                ['active', 'green', 'Active', 'Actif', 'Ikora', 'Anatumika'],
                ['on_leave', 'blue', 'On leave', 'En congé', 'Ari mu kiruhuko', 'Likizoni'],
                ['suspended', 'orange', 'Suspended', 'Suspendu', 'Yahagaritswe', 'Amesimamishwa'],
                ['terminated', 'gray', 'Left the organization', 'A quitté l\'organisation', 'Yavuye mu kigo', 'Ameondoka shirikani'],
            ]],
            'employee_category' => ['Employee category', [
                ['intern', 'gray', 'Intern', 'Stagiaire', 'Umwimenyereza', 'Mwanafunzi wa vitendo'],
                ['probation', 'yellow', 'Probation', 'Période d\'essai', 'Igihe cy\'igeragezwa', 'Majaribio'],
                ['contracted', 'green', 'Contracted', 'Sous contrat', 'Ufite amasezerano', 'Mkataba'],
                ['casual', 'blue', 'Casual worker', 'Travailleur occasionnel', 'Umukozi w\'igihe gito', 'Mfanyakazi wa muda'],
                ['volunteer', 'teal', 'Volunteer', 'Bénévole', 'Umukorerabushake', 'Mtu wa kujitolea'],
            ]],
            'client_status' => ['Client status', [
                ['prospect', 'gray', 'Prospect', 'Prospect', 'Ushobora kuba umukiriya', 'Mtarajiwa'],
                ['active', 'green', 'Active', 'Actif', 'Ikora', 'Anatumika'],
                ['suspended', 'orange', 'Suspended', 'Suspendu', 'Yahagaritswe', 'Amesimamishwa'],
                ['archived', 'gray', 'Archived', 'Archivé', 'Yabitswe', 'Amehifadhiwa'],
            ]],
        ];
    }
}
