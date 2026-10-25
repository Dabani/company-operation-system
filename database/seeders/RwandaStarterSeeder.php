<?php

namespace Database\Seeders;

use App\Models\LegalReference;
use App\Models\LookupValue;
use App\Models\Organization;
use App\Models\RetentionRule;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * OPTIONAL starter pack for organizations operating in Rwanda (run manually):
 *   php artisan db:seed --class=RwandaStarterSeeder
 * Everything is created as "not yet verified": a qualified person must confirm each citation against the official text
 * (Compliance -> Legal references -> Verify). Existing organization edits are never overwritten.
 */
class RwandaStarterSeeder extends Seeder
{
    public function run(): void
    {
        $refs = [
            ['LAW-058-2021', 'Law No. 058/2021 on the Protection of Personal Data and Privacy', 'Data protection', 'Personal data processing, secure storage, breach notification. Corrects an earlier citation of Law No. 12/2014 found in a source policy; confirm title and date with counsel.'],
            ['LAW-027-2019', 'Law No. 027/2019 on the Rwanda Archives and Library Services Authority (RALSA)', 'Archives and records', 'Records management and preservation requirements; archival standards.'],
            ['ICPAR-STD', 'ICPAR professional standards (working papers, confidentiality, retention)', 'Audit and assurance', 'Retention and confidentiality of audit working papers.'],
            ['BNR-ICT', 'National Bank of Rwanda ICT governance and security directives', 'ICT security', 'Applies to regulated clients and ICT control requirements.'],
            ['RRA-DIR', 'Rwanda Revenue Authority record-keeping directives', 'Tax records', 'Tax record retention and availability for inspection.'],
        ];

        foreach (Organization::all() as $org) {
            $created = [];
            foreach ($refs as [$code, $title, $topic, $notes]) {
                $created[$code] = LegalReference::firstOrCreate(['organization_id' => $org->id, 'code' => $code],
                    ['title' => $title, 'jurisdiction' => 'Rwanda', 'topic' => $topic, 'notes' => $notes, 'status_id' => Lookup::id('legal_status', 'unreviewed')]);
            }

            // Link categories to the likely source of the obligation (10 years as in the common policy default). Only where the organization has no rule yet.
            $link = ['audit_working_paper' => 'ICPAR-STD', 'tax_record' => 'RRA-DIR'];
            foreach (LookupValue::whereNull('organization_id')->whereHas('type', fn ($t) => $t->where('code', 'record_category'))->get() as $cat) {
                RetentionRule::firstOrCreate(['organization_id' => $org->id, 'category_id' => $cat->id], [
                    'retention_years' => 10, 'disposal_action_id' => Lookup::id('record_disposal_action', 'destroy'),
                    'legal_reference_id' => $created[$link[$cat->code] ?? 'LAW-027-2019']->id, 'notes' => 'Starter value - confirm with legal counsel',
                ]);
            }
        }
    }
}
