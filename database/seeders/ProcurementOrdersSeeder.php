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

/** COPS Phase 8b: purchase order statuses, the receiving permission, the order approval flow and the menu entry. Run after ProcurementSeeder. Idempotent. rw / sw labels need native review. */
class ProcurementOrdersSeeder extends Seeder
{
    private const GRANTS = ['staff' => ['procurement.receive'], 'team_leader' => ['procurement.receive'], 'manager' => ['procurement.receive']];

    public function run(): void
    {
        $this->lookups();
        Permission::updateOrCreate(['code' => 'procurement.receive'], ['group_id' => Lookup::id('permission_group', 'procurement'), 'name' => 'Follow purchase orders for my requisitions and record goods receipts (never on an order I raised myself)']);
        $parent = MenuItem::where('code', 'procurement')->first();      // created by ProcurementSeeder
        if ($parent) {
            MenuItem::updateOrCreate(['code' => 'procurement.orders'], ['parent_id' => $parent->id, 'label_key' => 'menu.procurement.orders', 'icon' => 'IconTruckDelivery', 'route' => '/purchase-orders',
                'permission_id' => Permission::where('code', 'procurement.receive')->value('id'), 'sort_order' => 3, 'is_active' => true]);
        }
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $admin = $role('admin');
            $admin?->permissions()->syncWithoutDetaching([$ids['procurement.receive']]);
            $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => 'purchase_order', 'version' => 1], [
                'name' => 'Purchase order (managing director)', 'subject_type_id' => Lookup::id('approval_subject_type', 'purchase_order'), 'effective_from' => '2026-01-01', 'is_active' => true]);
            $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Managing director',
                'step_type_id' => Lookup::id('approval_step_type', 'role'), 'role_id' => $admin?->id, 'escalate_after_hours' => 72]);
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'po_status' => ['Purchase order status', [
                ['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['pending_approval', 'orange', 'Awaiting approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['approved', 'blue', 'Approved', 'Approuvé', 'Yemejwe', 'Imeidhinishwa'], ['issued', 'cyan', 'Issued to supplier', 'Émis au fournisseur', 'Yohererejwe utanga serivisi', 'Imetumwa kwa muuzaji'],
                ['partially_received', 'yellow', 'Partly received', 'Partiellement reçu', 'Yakiriwe igice', 'Imepokelewa kwa sehemu'], ['received', 'green', 'Received', 'Reçu', 'Yakiriwe', 'Imepokelewa'],
                ['closed', 'gray', 'Closed', 'Clôturé', 'Yafunzwe', 'Imefungwa'], ['cancelled', 'red', 'Cancelled', 'Annulé', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
            'approval_subject_type' => ['Approval subject type', [['purchase_order', 'gray', 'Purchase order', 'Bon de commande', 'Iteka ry\'ubugure', 'Agizo la ununuzi']]],
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
