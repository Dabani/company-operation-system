<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\OnboardingTemplateItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * COPS Phase 4b seeds (idempotent): recruitment lookups (stages as in Guide 5.1), permissions, approval flows and a default onboarding checklist.
 * No salary, headcount or legal value is seeded. rw / sw labels are first-pass: have native speakers review them.
 */
class HrRecruitmentSeeder extends Seeder
{
    private const PERMS = [
        'hr.requisition.create' => 'Raise workforce requisitions',
        'hr.requisition.approve' => 'Approve workforce requisitions',
        'hr.recruitment.manage' => 'Manage vacancies, applicants, offers, conversion and applicant data retention (audited)',
        'hr.interview.score' => 'Sit on interview panels and submit scorecards',
        'hr.selection.approve' => 'Approve the selection of an applicant',
    ];

    private const GRANTS = [
        'manager' => ['hr.requisition.create', 'hr.requisition.approve', 'hr.interview.score', 'hr.selection.approve'],
        'team_leader' => ['hr.interview.score'],
    ];

    private const ONBOARDING = [['Sign the employment contract', 0], ['Hand in identification and bank details to HR', 0], ['Create the COPS account and mailbox', 0],
        ['Issue equipment and access', 1], ['Orientation and policy briefing', 3], ['Probation objectives agreed with the manager', 14]];

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
            foreach (['admin', 'hr_manager'] as $roleCode) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect(array_keys(self::PERMS))->map(fn ($c) => $ids[$c])->all());
            }
            $hr = $role('hr_manager');
            $flow = fn (string $code, string $name, string $subject, string $stepLabel, string $stepType, ?string $roleId) => ApprovalFlow::withoutGlobalScopes()
                ->updateOrCreate(['organization_id' => $org->id, 'code' => $code, 'version' => 1], ['name' => $name, 'subject_type_id' => Lookup::id('approval_subject_type', $subject), 'effective_from' => '2026-01-01', 'is_active' => true])
                ->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => $stepLabel, 'step_type_id' => Lookup::id('approval_step_type', $stepType), 'role_id' => $roleId, 'escalate_after_hours' => 72]);
            $flow('workforce_requisition_default', 'Workforce requisition (HR manager)', 'workforce_requisition', 'HR manager', 'role', $hr?->id);
            $flow('applicant_selection_default', 'Applicant selection (department head)', 'applicant_selection', 'Department head', 'department_head', null);
            if (!OnboardingTemplateItem::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                foreach (self::ONBOARDING as $i => [$title, $offset]) {
                    OnboardingTemplateItem::withoutGlobalScopes()->create(['organization_id' => $org->id, 'title' => $title, 'offset_days' => $offset, 'sort_order' => $i + 1, 'is_active' => true]);
                }
            }
        }
    }

    /** Menu entries (the screens ship with web bundle 15). */
    private function menu(): void
    {
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        foreach ([
            ['people.requisitions', 'IconClipboardList', '/hr/requisitions', 'hr.requisition.create', 6],
            ['people.recruitment', 'IconUserSearch', '/hr/recruitment', 'hr.recruitment.manage', 7],
            ['people.interviews', 'IconCalendarEvent', '/hr/interviews', 'hr.interview.score', 8],
        ] as [$code, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => MenuItem::where('code', 'people')->value('id'), 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'requisition_status' => ['Requisition status', [
                ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Utegereje kwemezwa', 'Inasubiri idhini'], ['approved', 'green', 'Approved', 'Approuvée', 'Yemejwe', 'Imeidhinishwa'],
                ['rejected', 'red', 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'], ['filled', 'teal', 'Filled', 'Pourvue', 'Yuzuye', 'Imejazwa'],
            ]],
            'vacancy_status' => ['Vacancy status', [['open', 'green', 'Open', 'Ouverte', 'Ifunguye', 'Wazi'], ['closed', 'gray', 'Closed', 'Clôturée', 'Ifunze', 'Imefungwa']]],
            'application_stage' => ['Application stage', [
                ['applied', 'gray', 'Applied', 'Candidature reçue', 'Yasabye', 'Ameomba'], ['screened', 'blue', 'Screened', 'Présélectionné', 'Yatoranyijwe', 'Amechujwa'], ['interview', 'blue', 'Interview', 'Entretien', 'Ikiganiro', 'Usaili'],
                ['assessment', 'violet', 'Assessment', 'Évaluation', 'Isuzuma', 'Tathmini'], ['offer', 'orange', 'Offer', 'Offre', 'Icyifuzo cy\'akazi', 'Ofa'], ['hired', 'green', 'Hired', 'Embauché', 'Yahawe akazi', 'Ameajiriwa'], ['rejected', 'red', 'Rejected', 'Rejeté', 'Yanzwe', 'Amekataliwa'],
            ]],
            'interview_type' => ['Interview type', [['interview', 'blue', 'Interview', 'Entretien', 'Ikiganiro', 'Usaili'], ['assessment', 'violet', 'Assessment', 'Évaluation', 'Isuzuma', 'Tathmini']]],
            'interview_status' => ['Interview status', [['scheduled', 'blue', 'Scheduled', 'Planifié', 'Giteganyijwe', 'Imepangwa'], ['completed', 'green', 'Completed', 'Terminé', 'Cyarangiye', 'Imekamilika'], ['cancelled', 'gray', 'Cancelled', 'Annulé', 'Cyahagaritswe', 'Imeghairiwa']]],
            'scorecard_recommendation' => ['Scorecard recommendation', [
                ['strong_yes', 'green', 'Strong yes', 'Oui sans réserve', 'Yego rwose', 'Ndiyo kabisa'], ['yes', 'teal', 'Yes', 'Oui', 'Yego', 'Ndiyo'], ['no', 'orange', 'No', 'Non', 'Oya', 'Hapana'], ['strong_no', 'red', 'Strong no', 'Non catégorique', 'Oya rwose', 'Hapana kabisa'],
            ]],
            'reference_result' => ['Reference check result', [['pending', 'gray', 'Pending', 'En attente', 'Utegereje', 'Inasubiri'], ['satisfactory', 'green', 'Satisfactory', 'Satisfaisant', 'Bishimishije', 'Inaridhisha'], ['concerns', 'orange', 'Concerns raised', 'Réserves émises', 'Hari impungenge', 'Wasiwasi umejitokeza']]],
            'offer_status' => ['Offer status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['issued', 'blue', 'Issued', 'Émise', 'Yatanzwe', 'Imetolewa'], ['accepted', 'green', 'Accepted', 'Acceptée', 'Yemewe', 'Imekubaliwa'],
                ['declined', 'red', 'Declined', 'Refusée', 'Yanzwe n\'umukandida', 'Imekataliwa'], ['withdrawn', 'gray', 'Withdrawn', 'Retirée', 'Yakuweho', 'Imeondolewa'],
            ]],
            'approval_subject_type' => ['Approval subject type', [
                ['workforce_requisition', 'gray', 'Workforce requisition', 'Demande de recrutement', 'Gusaba abakozi', 'Ombi la kuajiri'], ['applicant_selection', 'gray', 'Applicant selection', 'Sélection du candidat', 'Guhitamo umukandida', 'Uteuzi wa mwombaji'],
            ]],
        ];
        foreach ($data as $typeCode => [$typeName, $rows]) {
            $type = LookupType::where('code', $typeCode)->first() ?? LookupType::create(['code' => $typeCode, 'name' => $typeName, 'is_system' => true]);
            $start = (int) (LookupValue::where('lookup_type_id', $type->id)->max('sort_order') ?? -1);
            foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::firstOrNew(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c]);
                if (!$v->exists) {
                    $v->sort_order = $start + 1 + $i;                       // the order is set once; re-running never reshuffles it
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
