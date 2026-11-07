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
 * COPS Phase 2b seeds (idempotent). No tax rate, no rounding policy beyond the safe defaults and no payment terms are seeded as authoritative:
 * the tax rate is entered by finance and must be signed off before any invoice can be issued. rw/sw labels need native review.
 */
class BillingSeeder extends Seeder
{
    private const PERMS = [
        'billing.view' => 'View invoices of projects I can see',
        'billing.view_all' => 'View every invoice in the organization',
        'billing.manage' => 'Generate and edit draft invoices',
        'billing.issue' => 'Submit an invoice for issue',
        'billing.payments' => 'Record and reverse payments',
        'billing.credit' => 'Raise credit notes',
        'billing.configure' => 'Manage tax rates and project billing set-up',
        'billing.close_exception' => 'Close a project with unissued invoices (exception)',
    ];

    public function run(): void
    {
        $this->lookups();
        foreach (self::PERMS as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', 'billing'), 'name' => $name]);
        }
        $p = fn ($c) => Permission::where('code', $c)->value('id');
        foreach ([['invoices', null, 'IconFileInvoice', '/invoices', 'billing.view', 14], ['admin.tax_rates', 'admin.system', 'IconPercentage', '/admin/tax-rates', 'billing.configure', 6]] as [$code, $parent, $icon, $route, $perm, $order]) {
            MenuItem::updateOrCreate(['code' => $code], ['parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null, 'label_key' => "menu.$code", 'icon' => $icon,
                'route' => $route, 'permission_id' => $p($perm), 'sort_order' => $order, 'is_active' => true]);
        }
        $ids = Permission::pluck('id', 'code');
        foreach (Organization::all() as $org) {
            foreach (['manager' => ['billing.view', 'billing.manage', 'billing.issue'], 'admin' => array_keys(self::PERMS)] as $role => $codes) {
                Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $role)->first()
                    ?->permissions()->syncWithoutDetaching(collect($codes)->map(fn ($c) => $ids[$c])->all());
            }
            $adminRole = Role::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', 'admin')->value('id');
            foreach (['invoice' => ['invoice_approval', 'Invoice approval (managing director)'], 'credit_note' => ['credit_note_approval', 'Credit note approval (managing director)']] as $subject => [$code, $name]) {
                $f = ApprovalFlow::withoutGlobalScopes()->updateOrCreate(['organization_id' => $org->id, 'code' => $code, 'version' => 1], [
                    'name' => $name, 'subject_type_id' => Lookup::id('approval_subject_type', $subject), 'effective_from' => '2026-01-01', 'is_active' => true]);
                $f->steps()->withoutGlobalScopes()->updateOrCreate(['step_order' => 1], ['organization_id' => $org->id, 'label' => 'Managing director',
                    'step_type_id' => Lookup::id('approval_step_type', 'role'), 'role_id' => $adminRole, 'escalate_after_hours' => 72]);
            }
        }
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'permission_group' => [['billing', 'blue', 'Billing', 'Facturation', 'Kwishyuza', 'Utozaji']],
            'approval_subject_type' => [['credit_note', 'gray', 'Credit note', 'Note de crédit', 'Inyemezabwishyu yo kugabanya', 'Hati ya mkopo']],
            'project_status' => [['delivered', 'teal', 'Delivered', 'Livré', 'Washyikirijwe', 'Umekabidhiwa'], ['closed', 'gray', 'Closed', 'Clôturé', 'Wafunzwe', 'Umefungwa']],
            'billing_model' => [['time_and_materials', 'blue', 'Time and materials', 'Régie (temps passé)', 'Amasaha n\'ibikoresho', 'Muda na vifaa'], ['fixed_price', 'blue', 'Fixed price', 'Prix fixe', 'Igiciro kidahinduka', 'Bei maalum'],
                ['milestone', 'blue', 'Milestone based', 'Par jalons', 'Ku ntambwe z\'ingenzi', 'Kwa hatua muhimu']],
            'invoice_status' => [['draft', 'gray', 'Draft', 'Brouillon', 'Igenamigambi', 'Rasimu'], ['pending_approval', 'orange', 'Pending approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'],
                ['issued', 'blue', 'Issued', 'Émise', 'Yatanzwe', 'Imetolewa'], ['partially_paid', 'yellow', 'Partially paid', 'Partiellement payée', 'Yishyuwe igice', 'Imelipwa kiasi'],
                ['paid', 'green', 'Paid', 'Payée', 'Yishyuwe', 'Imelipwa'], ['overdue', 'red', 'Overdue', 'En retard', 'Yarenze igihe', 'Imechelewa'], ['cancelled', 'gray', 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa']],
            'invoice_line_kind' => [['time', 'gray', 'Time', 'Temps', 'Amasaha', 'Muda'], ['fixed', 'gray', 'Fixed price', 'Prix fixe', 'Igiciro kidahinduka', 'Bei maalum'],
                ['milestone', 'gray', 'Milestone', 'Jalon', 'Intambwe', 'Hatua muhimu'], ['manual', 'gray', 'Manual line', 'Ligne manuelle', 'Umurongo wanditswe n\'intoki', 'Mstari wa mkono']],
            'payment_method' => [['bank_transfer', 'gray', 'Bank transfer', 'Virement bancaire', 'Kohereza kuri banki', 'Uhamisho wa benki'], ['mobile_money', 'gray', 'Mobile money', 'Mobile money', 'Mobile money', 'Pesa ya simu'],
                ['cash', 'gray', 'Cash', 'Espèces', 'Amafaranga mu ntoki', 'Pesa taslimu'], ['cheque', 'gray', 'Cheque', 'Chèque', 'Sheki', 'Hundi'], ['other', 'gray', 'Other', 'Autre', 'Ikindi', 'Nyingine']],
            'credit_note_status' => [['pending_approval', 'orange', 'Pending approval', 'En attente d\'approbation', 'Itegereje kwemezwa', 'Inasubiri idhini'], ['issued', 'green', 'Issued', 'Émise', 'Yatanzwe', 'Imetolewa'],
                ['rejected', 'red', 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa']],
        ];
        $names = ['billing_model' => 'Billing model', 'invoice_status' => 'Invoice status', 'invoice_line_kind' => 'Invoice line kind', 'payment_method' => 'Payment method', 'credit_note_status' => 'Credit note status'];
        foreach ($data as $code => $rows) {
            $type = LookupType::where('code', $code)->first() ?? LookupType::create(['code' => $code, 'name' => $names[$code] ?? $code, 'is_system' => true]);
            $start = LookupValue::where('lookup_type_id', $type->id)->max('sort_order');
            foreach ($rows as $i => [$c, $color, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $c], ['color' => $color, 'icon' => null, 'sort_order' => ($start ?? -1) + 1 + $i, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
            }
        }
        Lookup::flush();
    }
}
