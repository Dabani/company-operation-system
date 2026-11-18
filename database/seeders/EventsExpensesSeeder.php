<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Organization;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 7c seeds (idempotent): minutes statuses and visibilities, approval subject/step types, the chair-approval flow. rw / sw are first-pass labels. */
class EventsExpensesSeeder extends Seeder
{
    public function run(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'event_expense_category' => ['Event expense category', [
                ['venue', 'blue', 'Venue', 'Lieu', 'Ahantu', 'Ukumbi'], ['catering', 'orange', 'Catering', 'Restauration', 'Ibiryo n\'ibinyobwa', 'Chakula'], ['transport', 'teal', 'Transport', 'Transport', 'Ingendo', 'Usafiri'],
                ['accommodation', 'violet', 'Accommodation', 'Hébergement', 'Icumbi', 'Malazi'], ['materials', 'gray', 'Materials', 'Matériel', 'Ibikoresho', 'Vifaa'], ['speakers', 'pink', 'Speakers and trainers', 'Intervenants et formateurs', 'Abatanga ibiganiro', 'Wazungumzaji na wakufunzi'],
                ['marketing', 'grape', 'Marketing', 'Communication', 'Kwamamaza', 'Uuzaji'], ['equipment', 'cyan', 'Equipment hire', 'Location de matériel', 'Gukodesha ibikoresho', 'Kukodisha vifaa'], ['other', 'gray', 'Other', 'Autre', 'Ibindi', 'Nyingine'],
            ]],
            'event_expense_status' => ['Event expense status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['approved', 'green', 'Approved', 'Approuvée', 'Yemejwe', 'Imeidhinishwa'], ['rejected', 'red', 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa'],
            ]],
            'approval_subject_type' => ['Approval subject type', [['event_expense', 'gray', 'Event expense', 'Dépense d\'événement', 'Amafaranga y\'igikorwa', 'Gharama ya tukio']]],
            'file_link_type' => ['File link type', [['event_expense', 'gray', 'Event expense receipt', 'Justificatif de dépense d\'événement', 'Inyemezabwishyu y\'igikorwa', 'Risiti ya gharama ya tukio']]],
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
        foreach (Organization::all() as $org) {
            $this->flow($org);
        }
    }

    /** The event's department head approves; the requester can never approve, and an unresolved approver falls back to the top authority. */
    private function flow(Organization $org): void
    {
        $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'event_expense', 'version' => 1], [
            'name' => 'Event expense (department head, then top authority)', 'subject_type_id' => Lookup::id('approval_subject_type', 'event_expense'), 'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], [
            'organization_id' => $org->id, 'label' => 'Department head', 'step_type_id' => Lookup::id('approval_step_type', 'department_head'), 'escalate_after_hours' => 72,
        ]);
    }
}
