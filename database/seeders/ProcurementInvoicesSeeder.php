<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** COPS Phase 8c: invoice statuses, invoice permissions and the menu entry. Run after ProcurementOrdersSeeder. Idempotent. rw / sw labels need native review. */
class ProcurementInvoicesSeeder extends Seeder
{
    private const PERMS = [
        'procurement.invoice' => 'Record supplier invoices against purchase orders and follow their matching',
        'procurement.invoice.approve' => 'Decide invoice exceptions and approve matched invoices (never ones I recorded)',
        'procurement.invoice.pay' => 'Mark approved invoices as paid with a payment reference (never ones I approved)',
    ];
    private const GRANTS = ['manager' => ['procurement.invoice'], 'admin' => ['procurement.invoice', 'procurement.invoice.approve', 'procurement.invoice.pay']];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'procurement'), 'name' => $name]);
        }
        $parent = MenuItem::where('code', 'procurement')->first();      // created by ProcurementSeeder
        if ($parent) {
            MenuItem::updateOrCreate(['code' => 'procurement.invoices'], ['parent_id' => $parent->id, 'label_key' => 'menu.procurement.invoices', 'icon' => 'IconFileInvoice', 'route' => '/supplier-invoices',
                'permission_id' => Permission::where('code', 'procurement.invoice')->value('id'), 'sort_order' => 4, 'is_active' => true]);
        }
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            $role = fn (string $c) => Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $c)->first();
            foreach (self::GRANTS as $roleCode => $codes) {
                $role($roleCode)?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'supplier_invoice_status' => ['Supplier invoice status', [
                ['received', 'gray', 'Received', 'Reçue', 'Yakiriwe', 'Imepokelewa'], ['on_hold', 'orange', 'On hold', 'En attente', 'Ihagaritswe by\'agateganyo', 'Imesimamishwa'],
                ['matched', 'blue', 'Matched', 'Rapprochée', 'Yahuje', 'Imelingana'], ['approved', 'cyan', 'Approved for payment', 'Approuvée pour paiement', 'Yemerewe kwishyurwa', 'Imeidhinishwa kulipwa'],
                ['paid', 'green', 'Paid', 'Payée', 'Yishyuwe', 'Imelipwa'], ['rejected', 'red', 'Rejected', 'Rejetée', 'Yangiwe', 'Imekataliwa'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
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
