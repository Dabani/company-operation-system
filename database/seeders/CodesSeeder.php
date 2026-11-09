<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\RuleSet;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * Rule sets the system itself reads keep a fixed code (a "system key"); every other set gets a generated RUL-0001 code.
 * invoice-approval: put versions here to let small invoices be issued without approval (outcome approval_required = false).
 */
class CodesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Organization::all() as $org) {
            RuleSet::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org->id, 'code' => 'invoice-approval'], [
                'domain_id' => Lookup::id('rule_domain', 'billing'), 'name' => 'Invoice approval', 'requires_full_coverage' => false,
                'description' => 'Conditions under which an invoice needs approval before it is issued (default: always).',
            ]);
        }
    }
}
