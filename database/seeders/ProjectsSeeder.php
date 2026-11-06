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

/** COPS Phase 1 seeds (idempotent): lookups, permissions, menu, role grants, default task-review flow. rw/sw labels need native review. */
class ProjectsSeeder extends Seeder
{
    private const PERMS = [
        'project.view' => ['projects', 'View projects and tasks I belong to'],
        'project.create' => ['projects', 'Create projects'],
        'project.view_all' => ['projects', 'See every project in the organization'],
        'project.manage_all' => ['projects', 'Manage and delete any project'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => [$group, $name]) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', $group), 'name' => $name]);
        }
        MenuItem::updateOrCreate(['code' => 'projects'], ['parent_id' => null, 'label_key' => 'menu.projects', 'icon' => 'IconLayoutKanban', 'route' => '/projects',
            'permission_id' => Permission::where('code', 'project.view')->value('id'), 'sort_order' => 11, 'is_active' => true]);
        foreach (Organization::all() as $org) {
            $this->grants($org);
            $this->flow($org);
        }
    }

    private function grants(Organization $org): void
    {
        $ids = Permission::pluck('id', 'code');
        foreach (['staff' => ['project.view', 'approval.act'], 'team_leader' => ['project.view', 'project.create', 'approval.act'], 'manager' => ['project.view', 'project.create', 'project.view_all'], 'admin' => array_keys(self::PERMS)] as $role => $codes) {
            Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $role)->first()
                ?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
        }
    }

    /** Task review is decided by the project's reviewers/managers (and owner); unresolved -> top authority fallback. */
    private function flow(Organization $org): void
    {
        $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'task_review', 'version' => 1], [
            'name' => 'Task review (project reviewers)', 'subject_type_id' => Lookup::id('approval_subject_type', 'task_review'), 'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], [
            'organization_id' => $org->id, 'label' => 'Project reviewer', 'step_type_id' => Lookup::id('approval_step_type', 'project_reviewer'), 'escalate_after_hours' => 48,
        ]);
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'permission_group' => [['projects', 'blue', 'Projects', 'Projets', 'Imishinga', 'Miradi']],
            'file_link_type' => [['project', 'gray', 'Project', 'Projet', 'Umushinga', 'Mradi'], ['task', 'gray', 'Task', 'Tâche', 'Umurimo', 'Kazi']],
            'approval_step_type' => [['project_reviewer', 'blue', 'Project reviewer', 'Réviseur du projet', 'Usuzuma umushinga', 'Mkaguzi wa mradi']],
            'project_status' => [
                ['planning', 'gray', 'Planning', 'Planification', 'Igenamigambi', 'Mipango'], ['active', 'green', 'Active', 'Actif', 'Ukora', 'Unaendelea'],
                ['on_hold', 'orange', 'On hold', 'En pause', 'Wahagaritswe', 'Umesimamishwa'], ['completed', 'blue', 'Completed', 'Terminé', 'Warangiye', 'Umekamilika'],
                ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Wahagaritswe burundu', 'Umeghairiwa'],
            ],
            'project_role' => [
                ['manager', 'red', 'Project manager', 'Chef de projet', 'Umuyobozi w\'umushinga', 'Meneja wa mradi'], ['member', 'blue', 'Member', 'Membre', 'Umunyamuryango', 'Mwanachama'],
                ['reviewer', 'orange', 'Reviewer', 'Réviseur', 'Usuzuma', 'Mkaguzi'], ['viewer', 'gray', 'Viewer', 'Observateur', 'Ureba gusa', 'Mtazamaji'],
            ],
            'task_status' => [
                ['todo', 'gray', 'To do', 'À faire', 'Ibigomba gukorwa', 'Za kufanya'], ['in_progress', 'blue', 'In progress', 'En cours', 'Birakorwa', 'Zinaendelea'],
                ['in_review', 'orange', 'In review', 'En révision', 'Biri gusuzumwa', 'Zinakaguliwa'], ['done', 'green', 'Done', 'Terminé', 'Byarangiye', 'Zimekamilika'],
                ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Byahagaritswe', 'Zimeghairiwa'],
            ],
            'task_priority' => [
                ['low', 'gray', 'Low', 'Basse', 'Hasi', 'Chini'], ['normal', 'blue', 'Normal', 'Normale', 'Isanzwe', 'Kawaida'],
                ['high', 'orange', 'High', 'Haute', 'Hejuru', 'Juu'], ['urgent', 'red', 'Urgent', 'Urgente', 'Byihutirwa', 'Dharura'],
            ],
        ];
        $names = ['project_status' => 'Project status', 'project_role' => 'Project role', 'task_status' => 'Task status', 'task_priority' => 'Task priority'];
        foreach ($data as $typeCode => $rows) {
            $type = LookupType::where('code', $typeCode)->first() ?? LookupType::create(['code' => $typeCode, 'name' => $names[$typeCode] ?? $typeCode, 'is_system' => true]);
            $start = LookupValue::where('lookup_type_id', $type->id)->max('sort_order');
            foreach ($rows as $i => [$code, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => null, 'sort_order' => ($start ?? -1) + 1 + $i, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();
    }
}
