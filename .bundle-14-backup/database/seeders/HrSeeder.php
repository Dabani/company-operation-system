<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Hr\EmploymentService;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * COPS Phase 4a seeds (idempotent): HR lookups, permissions, menu, the hr_manager role, the employment-change approval flow,
 * and a "hire" history row for employees that existed before this phase. No salary, grade or legal value is seeded.
 * rw / sw labels are first-pass: have native speakers review them.
 */
class HrSeeder extends Seeder
{
    private const PERMS = [
        'hr.structure.manage' => 'Manage positions, grades and progression criteria',
        'hr.history.view' => 'View employment history (without salary)',
        'hr.history.manage' => 'Propose employment changes and receive HR reminders',
        'hr.salary.view' => 'View salary and pay bands (audited)',
        'hr.approve' => 'Approve employment changes (promotion, confirmation, salary, category)',
        'hr.reviews.manage' => 'Manage review cycles and see every performance review (audited)',
        'hr.reviews.write' => 'Write performance reviews of my direct reports',
        'hr.self.view' => 'See my own employment record and reviews',
    ];

    private const GRANTS = [
        'staff' => ['hr.self.view'],
        'team_leader' => ['hr.self.view', 'hr.reviews.write'],
        'manager' => ['hr.self.view', 'hr.reviews.write', 'hr.history.view'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'hr'), 'name' => $name]);
        }
        $this->menu();
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $role('admin')?->permissions()->syncWithoutDetaching(collect(array_keys(self::PERMS))->map(fn ($c) => $ids[$c])->all());

            // Separate HR role (Guide 5.7): HR permissions are not part of general management. Created once; later edits are never overwritten.
            $hr = Role::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'hr_manager'],
                ['name' => 'HR manager', 'level' => 55, 'color' => 'teal', 'is_system' => true, 'is_active' => true]);
            if ($hr->wasRecentlyCreated) {
                $hr->permissions()->sync(collect(array_merge(['dashboard.view', 'department.view', 'lookup.view', 'approval.view', 'approval.act', 'employee.view', 'employee.manage',
                    'employee.view_sensitive', 'employee.manage_sensitive', 'scope.organization'], array_keys(self::PERMS)))->map(fn ($c) => $ids[$c])->all());
            }

            $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'employment_change_default', 'version' => 1], [
                'name' => 'Employment change (HR manager)', 'subject_type_id' => Lookup::id('approval_subject_type', 'employment_change'), 'effective_from' => '2026-01-01', 'is_active' => true]);
            $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'HR manager',
                'step_type_id' => Lookup::id('approval_step_type', 'role'), 'role_id' => $hr->id, 'escalate_after_hours' => 72]);
        }
        $this->backfill();
    }

    private function menu(): void
    {
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        foreach ([
            ['people.positions', 'people', 'IconSitemap', '/hr/positions', 'employee.view', 2],
            ['people.employment_changes', 'people', 'IconTimeline', '/hr/employment-changes', 'hr.history.manage', 3],
            ['people.reviews', 'people', 'IconStars', '/hr/reviews', 'hr.self.view', 4],
            ['people.my_employment', 'people', 'IconUser', '/me/employment', 'hr.self.view', 5],
        ] as [$code, $parent, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => MenuItem::where('code', $parent)->value('id'), 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
    }

    private function backfill(): void
    {
        $svc = app(EmploymentService::class);
        Employee::withoutGlobalScopes()->whereNotIn('id', EmploymentRecord::withoutGlobalScopes()->select('employee_id'))->get()->each(fn ($e) => $svc->recordHire($e));
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'hr_change_type' => ['Employment change type', [
                ['hire', 'green', 'Hire', 'Embauche', 'Gutangira akazi', 'Kuajiriwa'], ['confirmation', 'green', 'Confirmation', 'Confirmation', 'Kwemezwa mu kazi', 'Kuthibitishwa kazini'],
                ['promotion', 'blue', 'Promotion', 'Promotion', 'Kuzamurwa mu ntera', 'Kupandishwa cheo'], ['transfer', 'blue', 'Transfer', 'Mutation', 'Kwimurirwa', 'Kuhamishwa'],
                ['category_change', 'orange', 'Category change', 'Changement de catégorie', 'Guhindura icyiciro', 'Mabadiliko ya kundi'],
                ['salary_change', 'orange', 'Salary change', 'Changement de salaire', 'Guhindura umushahara', 'Mabadiliko ya mshahara'],
                ['manager_change', 'gray', 'Manager change', 'Changement de responsable', 'Guhindura umuyobozi', 'Mabadiliko ya msimamizi'],
            ]],
            'employment_record_status' => ['Employment record status', [
                ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Utegereje kwemezwa', 'Inasubiri idhini'], ['effective', 'green', 'Effective', 'En vigueur', 'Burakurikizwa', 'Inatumika'],
                ['rejected', 'red', 'Rejected', 'Rejeté', 'Byanzwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Byahagaritswe', 'Imeghairiwa'],
            ]],
            'contract_type' => ['Contract type', [
                ['permanent', 'green', 'Permanent', 'Durée indéterminée', 'Igihoraho', 'Ya kudumu'], ['fixed_term', 'blue', 'Fixed term', 'Durée déterminée', 'Igihe kigenwe', 'Ya muda maalum'],
                ['casual', 'gray', 'Casual', 'Occasionnel', 'Igihe gito', 'Ya muda mfupi'], ['internship', 'gray', 'Internship', 'Stage', 'Umwimenyerezo', 'Mafunzo kwa vitendo'],
                ['consultancy', 'blue', 'Consultancy', 'Consultance', 'Ubujyanama', 'Ushauri'], ['volunteer', 'teal', 'Volunteer', 'Bénévolat', 'Ubukorerabushake', 'Kujitolea'],
            ]],
            'review_cycle_status' => ['Review cycle status', [
                ['open', 'green', 'Open', 'Ouvert', 'Rufunguye', 'Wazi'], ['closed', 'gray', 'Closed', 'Clos', 'Rufunze', 'Imefungwa'],
            ]],
            'review_status' => ['Review status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['submitted', 'orange', 'Submitted', 'Soumis', 'Byatanzwe', 'Imewasilishwa'], ['acknowledged', 'green', 'Acknowledged', 'Pris en compte', 'Byemewe n\'umukozi', 'Imekubaliwa na mfanyakazi'],
            ]],
            'performance_rating' => ['Performance rating', [
                ['unsatisfactory', 'red', 'Unsatisfactory', 'Insatisfaisant', 'Ntibishimishije', 'Haridhishi'], ['needs_improvement', 'orange', 'Needs improvement', 'À améliorer', 'Bisaba kunozwa', 'Linahitaji kuboreshwa'],
                ['meets_expectations', 'blue', 'Meets expectations', 'Répond aux attentes', 'Byujuje ibyitezwe', 'Linakidhi matarajio'], ['exceeds_expectations', 'green', 'Exceeds expectations', 'Dépasse les attentes', 'Birenze ibyitezwe', 'Linazidi matarajio'],
                ['outstanding', 'teal', 'Outstanding', 'Exceptionnel', 'Indashyikirwa', 'Bora kabisa'],
            ]],
            'review_recommendation' => ['Review recommendation', [
                ['none', 'gray', 'No recommendation', 'Aucune recommandation', 'Nta nama', 'Hakuna pendekezo'], ['confirm', 'green', 'Confirm in post', 'Confirmer au poste', 'Kwemeza mu kazi', 'Kuthibitisha kazini'],
                ['promote', 'blue', 'Promote', 'Promouvoir', 'Kuzamura mu ntera', 'Kupandisha cheo'], ['extend_probation', 'orange', 'Extend probation', 'Prolonger l\'essai', 'Kongera igeragezwa', 'Kuongeza majaribio'],
                ['salary_review', 'blue', 'Review salary', 'Revoir le salaire', 'Gusuzuma umushahara', 'Kupitia mshahara'], ['improvement_plan', 'red', 'Improvement plan', 'Plan d\'amélioration', 'Gahunda yo kunoza', 'Mpango wa kuboresha'],
            ]],
            'approval_subject_type' => ['Approval subject type', [
                ['employment_change', 'gray', 'Employment change', 'Changement d\'emploi', 'Impinduka mu kazi', 'Mabadiliko ya ajira'],
            ]],
        ];
        foreach ($data as $typeCode => [$typeName, $rows]) {
            $type = LookupType::where('code', $typeCode)->first() ?? LookupType::create(['code' => $typeCode, 'name' => $typeName, 'is_system' => true]);
            $start = (int) (LookupValue::where('lookup_type_id', $type->id)->max('sort_order') ?? -1);
            foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
                // performance_rating keeps its sort order 1..5: progression criteria compare against that level
                $order = $typeCode === 'performance_rating' ? $i + 1 : $start + 1 + $i;
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c], ['color' => $color, 'icon' => null, 'sort_order' => $order, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }
}
