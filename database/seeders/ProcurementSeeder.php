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
 * COPS Phase 8a (Guide section 7): procurement lookups, permissions, menu and the two approval flows. Idempotent. rw / sw labels need native review.
 * A buyer is a person with procurement.manage. Bank details are restricted to procurement.supplier.bank, held by the admin role only until the organization
 * decides who in Finance should confirm them. Approval thresholds are set in the approval rules (amount, department), as for every other subject.
 */
class ProcurementSeeder extends Seeder
{
    private const PERMS = [
        'procurement.view' => 'See approved suppliers (name and category) and use them on requisitions',
        'procurement.requisition.create' => 'Raise purchase requisitions and follow my own',
        'procurement.manage' => 'Buyer: manage suppliers and see every purchase requisition',
        'procurement.supplier.bank' => 'See and confirm supplier bank details (cannot confirm what one entered oneself)',
    ];

    private const GRANTS = [
        'staff' => ['procurement.view', 'procurement.requisition.create'],
        'team_leader' => ['procurement.view', 'procurement.requisition.create'],
        'manager' => ['procurement.view', 'procurement.requisition.create', 'procurement.manage'],
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'procurement'), 'name' => $name]);
        }
        $this->menu();
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $role('admin')?->permissions()->syncWithoutDetaching(collect(array_keys(self::PERMS))->map(fn ($c) => $ids[$c])->all());
            $this->flows($org, $role('admin')?->id);
        }
    }

    private function menu(): void
    {
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        $parent = MenuItem::updateOrCreate(['code' => 'procurement'], ['parent_id' => null, 'label_key' => 'menu.procurement', 'icon' => 'IconShoppingCart', 'route' => null, 'permission_id' => $p('procurement.view'), 'sort_order' => 16, 'is_active' => true]);
        foreach ([['procurement.requisitions', 'IconClipboardList', '/requisitions', 'procurement.requisition.create', 1], ['procurement.suppliers', 'IconBuildingStore', '/suppliers', 'procurement.view', 2]] as [$code, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent->id, 'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route, 'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
    }

    private function flows(Organization $org, ?string $adminRole): void
    {
        $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'supplier_onboarding', 'version' => 1], [
            'name' => 'Supplier onboarding (managing director)', 'subject_type_id' => Lookup::id('approval_subject_type', 'supplier'), 'effective_from' => '2026-01-01', 'is_active' => true]);
        $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Managing director',
            'step_type_id' => Lookup::id('approval_step_type', 'role'), 'role_id' => $adminRole, 'escalate_after_hours' => 72]);
        $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'purchase_requisition', 'version' => 1], [
            'name' => 'Purchase requisition (department head)', 'subject_type_id' => Lookup::id('approval_subject_type', 'purchase_requisition'), 'effective_from' => '2026-01-01', 'is_active' => true]);
        $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Department head',
            'step_type_id' => Lookup::id('approval_step_type', 'department_head'), 'escalate_after_hours' => 72]);
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'permission_group' => ['Permission group', [['procurement', 'teal', 'Procurement', 'Achats', 'Gura', 'Manunuzi']]],
            'supplier_status' => ['Supplier status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['under_review', 'orange', 'Under review', 'En revue', 'Riri gusuzumwa', 'Inakaguliwa'],
                ['approved', 'green', 'Approved', 'Approuvé', 'Yemejwe', 'Ameidhinishwa'], ['suspended', 'yellow', 'Suspended', 'Suspendu', 'Yahagaritswe by\'agateganyo', 'Amesimamishwa'],
                ['blocked', 'red', 'Blocked', 'Bloqué', 'Yafunzwe', 'Amezuiwa'], ['archived', 'gray', 'Archived', 'Archivé', 'Yabitswe', 'Amehifadhiwa'],
            ]],
            'supplier_bank_status' => ['Supplier bank account status', [
                ['pending_verification', 'orange', 'Awaiting confirmation', 'En attente de confirmation', 'Itegereje kwemezwa', 'Inasubiri uthibitisho'], ['verified', 'green', 'Confirmed', 'Confirmé', 'Yemejwe', 'Imethibitishwa'],
                ['replaced', 'gray', 'Replaced', 'Remplacé', 'Yasimbuwe', 'Imebadilishwa'], ['rejected', 'red', 'Rejected', 'Rejeté', 'Yanzwe', 'Imekataliwa'],
            ]],
            'supplier_category' => ['Supplier category', [
                ['goods', 'blue', 'Goods', 'Fournitures', 'Ibicuruzwa', 'Bidhaa'], ['works', 'orange', 'Works', 'Travaux', 'Imirimo y\'ubwubatsi', 'Kazi za ujenzi'],
                ['consultancy', 'violet', 'Consultancy', 'Conseil', 'Ubujyanama', 'Ushauri'], ['services', 'teal', 'Services', 'Services', 'Serivisi', 'Huduma'], ['other', 'gray', 'Other', 'Autre', 'Ikindi', 'Nyingine'],
            ]],
            'procurement_type' => ['Procurement type', [
                ['goods', 'blue', 'Goods', 'Fournitures', 'Ibicuruzwa', 'Bidhaa'], ['works', 'orange', 'Works', 'Travaux', 'Imirimo y\'ubwubatsi', 'Kazi za ujenzi'],
                ['consultancy', 'violet', 'Consultancy services', 'Services de conseil', 'Serivisi z\'ubujyanama', 'Huduma za ushauri'], ['services', 'teal', 'Non-consultancy services', 'Services hors conseil', 'Izindi serivisi', 'Huduma zisizo za ushauri'],
            ]],
            'requisition_status' => ['Purchase requisition status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['approved', 'green', 'Approved', 'Approuvée', 'Yemejwe', 'Imeidhinishwa'], ['rejected', 'red', 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'approval_subject_type' => ['Approval subject type', [
                ['supplier', 'gray', 'Supplier onboarding', 'Référencement fournisseur', 'Kwemeza utanga serivisi', 'Kumsajili muuzaji'],
                ['purchase_requisition', 'gray', 'Purchase requisition', 'Demande d\'achat', 'Icyifuzo cyo kugura', 'Ombi la ununuzi'],
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
