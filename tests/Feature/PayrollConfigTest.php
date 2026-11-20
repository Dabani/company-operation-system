<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs bundle 20 (HrPayrollSeeder).
 * Every rate and amount below is a TEST INPUT (invented round numbers), never a statement of Rwandan law.
 */
class PayrollConfigTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $prep;      // admin: holds every payroll permission, so it can prepare AND verify
    private User $second;
    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        $this->prep = $this->makeUser('admin');
        $this->second = $this->makeUser('admin');
        $u = $this->makeUser('staff');
        $this->emp = Employee::create(['first_name' => 'Pay', 'last_name' => 'Tester', 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $u->id, 'hired_on' => '2025-01-06']);
        EmploymentRecord::create(['employee_id' => $this->emp->id, 'change_type_id' => Lookup::id('hr_change_type', 'hire'), 'status_id' => Lookup::id('employment_record_status', 'effective'), 'effective_from' => '2025-01-06',
            'category_id' => Lookup::id('employee_category', 'contracted'), 'salary_amount' => '500000', 'salary_currency' => 'RWF', 'created_by' => $this->prep->id]);
    }

    private function as(User $u): void { Sanctum::actingAs($u); }

    private function basic(bool $taxable = true, bool $pensionable = true): string
    {
        $this->as($this->prep);
        return $this->postJson('/api/v1/payroll/components', ['code' => 'basic', 'name' => 'Basic salary', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'basic_salary'),
            'is_taxable' => $taxable, 'is_pensionable' => $pensionable])->assertCreated()->json('data.id');
    }

    private function flatScheme(string $code = 'pen', int $order = 1, string $base = 'pensionable_earnings'): string
    {
        $this->as($this->prep);
        return $this->postJson('/api/v1/payroll/schemes', ['code' => $code, 'name' => strtoupper($code), 'statutory_type_id' => Lookup::id('pay_statutory_type', 'pension'), 'mode_id' => Lookup::id('pay_scheme_mode', 'flat_rate'),
            'calc_order' => $order, 'base_kind_id' => Lookup::id('pay_scheme_base', $base), 'reduces_taxable_base' => false, 'rounding_id' => Lookup::id('pay_rounding', 'nearest'), 'decimals' => 0])->assertCreated()->json('data.id');
    }

    private function version(string $schemeId, array $o = []): string
    {
        $this->as($this->prep);
        return $this->postJson("/api/v1/payroll/schemes/$schemeId/versions", $o + ['employee_rate' => 5, 'employer_rate' => 5, 'valid_from' => '2026-01-01', 'source_citation' => 'Test schedule'])->assertCreated()->assertJsonPath('data.verification', 'unverified')->json('data.id');
    }

    private function verify(string $path, string $id): void
    {
        $this->as($this->second);
        $this->postJson("/api/v1/payroll/$path/$id/verify")->assertOk()->assertJsonPath('data.verification', 'verified');
    }

    private function assignCategory(string $schemeId): string
    {
        $this->as($this->prep);
        return $this->postJson('/api/v1/payroll/assignments', ['scheme_id' => $schemeId, 'category_id' => Lookup::id('employee_category', 'contracted'), 'applies' => true, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function dry(array $o = []): array
    {
        $this->as($this->prep);
        return $this->postJson('/api/v1/payroll/dry-run', $o + ['employee_id' => $this->emp->id, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertOk()->json('data');
    }

    private function codes(array $items): array { return array_column($items, 'code'); }

    public function test_nothing_is_prefilled_and_the_module_is_not_ready(): void
    {
        $this->as($this->prep);
        $this->getJson('/api/v1/payroll/components')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/payroll/schemes')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/payroll/setup-status')->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.has_single_basic_component', false);
    }

    public function test_staff_and_hr_managers_have_no_payroll_access_by_default(): void
    {
        foreach (['staff', 'hr_manager', 'manager'] as $role) {
            $this->as($this->makeUser($role));
            $this->getJson('/api/v1/payroll/components')->assertForbidden();
            $this->postJson('/api/v1/payroll/dry-run', ['employee_id' => $this->emp->id, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertForbidden();
        }
    }

    public function test_a_component_needs_explicit_tax_flags_and_they_are_fixed_afterwards(): void
    {
        $this->as($this->prep);
        $base = ['code' => 'basic', 'name' => 'Basic', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'basic_salary')];
        $this->postJson('/api/v1/payroll/components', $base)->assertStatus(422);                                    // no default: the accountant decides
        $id = $this->basic(true, false);
        $this->patchJson("/api/v1/payroll/components/$id", ['name' => 'Basic pay', 'is_taxable' => false])->assertOk()->assertJsonPath('data.name', 'Basic pay')->assertJsonPath('data.is_taxable', true);
        $this->postJson('/api/v1/payroll/components', ['code' => 'basic2'] + $base)->assertStatus(422);               // only one basic-salary component
    }

    public function test_bands_must_be_contiguous_and_rates_are_required(): void
    {
        $this->as($this->prep);
        $paye = $this->postJson('/api/v1/payroll/schemes', ['code' => 'tax', 'name' => 'Tax', 'statutory_type_id' => Lookup::id('pay_statutory_type', 'paye'), 'mode_id' => Lookup::id('pay_scheme_mode', 'brackets'), 'calc_order' => 2,
            'base_kind_id' => Lookup::id('pay_scheme_base', 'taxable_after_deductions'), 'reduces_taxable_base' => false, 'rounding_id' => Lookup::id('pay_rounding', 'nearest'), 'decimals' => 0])->assertCreated()->json('data.id');
        $v = fn (array $b) => $this->postJson("/api/v1/payroll/schemes/$paye/versions", ['valid_from' => '2026-01-01', 'source_citation' => 'Test', 'brackets' => $b]);
        $v([['lower' => 0, 'upper' => 100000, 'rate' => 0], ['lower' => 120000, 'upper' => null, 'rate' => 10]])->assertStatus(422);      // a gap leaves income untaxed
        $v([['lower' => 0, 'upper' => 100000, 'rate' => 0]])->assertStatus(422);                                                          // the last band must be open
        $v([['lower' => 0, 'upper' => 100000, 'rate' => 0], ['lower' => 100000, 'upper' => null, 'rate' => 10]])->assertCreated();
        $flat = $this->flatScheme();
        $this->postJson("/api/v1/payroll/schemes/$flat/versions", ['valid_from' => '2026-01-01', 'source_citation' => 'Test'])->assertStatus(422);   // flat needs a rate
    }

    public function test_versions_cannot_overlap(): void
    {
        $s = $this->flatScheme();
        $this->version($s);
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/schemes/$s/versions", ['employee_rate' => 6, 'valid_from' => '2026-06-01', 'source_citation' => 'x'])->assertStatus(422);
    }

    public function test_a_second_person_verifies_and_a_verified_version_is_final(): void
    {
        $s = $this->flatScheme();
        $v = $this->version($s);
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/scheme-versions/$v/verify")->assertStatus(422);                         // the preparer cannot verify their own value
        $this->verify('scheme-versions', $v);
        $this->as($this->second);
        $this->postJson("/api/v1/payroll/scheme-versions/$v/verify")->assertStatus(422);                         // already verified
        $this->as($this->prep);
        $this->deleteJson("/api/v1/payroll/scheme-versions/$v")->assertStatus(422);                              // verified = final
        $this->postJson("/api/v1/payroll/scheme-versions/$v/end", ['valid_to' => '2026-12-31'])->assertOk()->assertJsonPath('data.valid_to', '2026-12-31');
    }

    public function test_the_dry_run_applies_only_verified_configuration(): void
    {
        $this->basic();
        $s = $this->flatScheme();
        $v = $this->version($s);
        $a = $this->assignCategory($s);

        $r = $this->dry();
        $this->assertContains('unverified_version', $this->codes($r['warnings']));                               // shown, not applied
        $this->assertSame('500000.00', $r['result']['totals']['net']);
        $this->assertSame('0.00', $r['result']['totals']['employee_contributions']);

        $this->verify('scheme-versions', $v);
        $r = $this->dry();
        $this->assertContains('unverified_assignment', $this->codes($r['warnings']));                            // the version counts now, the assignment does not
        $this->assertSame('0.00', $r['result']['totals']['employee_contributions']);

        $this->verify('assignments', $a);
        $r = $this->dry();
        $this->assertSame('25000.00', $r['result']['totals']['employee_contributions']);                         // 5 % of 500000 (test rate)
        $this->assertSame('475000.00', $r['result']['totals']['net']);
        $this->assertSame('25000.00', $r['result']['totals']['employer_contributions']);
        $line = collect($r['result']['lines'])->firstWhere('kind', 'scheme_employee');
        $this->assertSame($r['meta']['applied'][0]['version_id'], $line['version_id']);                          // every line names the version it came from
    }

    public function test_no_assignment_means_the_scheme_does_not_apply_and_the_result_says_so(): void
    {
        $this->basic();
        $s = $this->flatScheme();
        $this->verify('scheme-versions', $this->version($s));
        $r = $this->dry();
        $this->assertContains('no_assignment', $this->codes($r['warnings']));
        $this->assertSame('0.00', $r['result']['totals']['employee_contributions']);
    }

    public function test_an_employee_exception_needs_a_reason_and_overrides_the_category(): void
    {
        $this->basic();
        $s = $this->flatScheme();
        $this->verify('scheme-versions', $this->version($s));
        $this->verify('assignments', $this->assignCategory($s));
        $this->as($this->prep);
        $this->postJson('/api/v1/payroll/assignments', ['scheme_id' => $s, 'employee_id' => $this->emp->id, 'applies' => false, 'valid_from' => '2026-01-01'])->assertStatus(422);         // no reason
        $this->postJson('/api/v1/payroll/assignments', ['scheme_id' => $s, 'category_id' => Lookup::id('employee_category', 'contracted'), 'employee_id' => $this->emp->id, 'applies' => false, 'valid_from' => '2026-01-01', 'reason' => 'x'])->assertStatus(422);   // both targets
        $ex = $this->postJson('/api/v1/payroll/assignments', ['scheme_id' => $s, 'employee_id' => $this->emp->id, 'applies' => false, 'valid_from' => '2026-01-01', 'reason' => 'Signed decision 12'])->assertCreated()->json('data.id');
        $this->assertSame('25000.00', $this->dry()['result']['totals']['employee_contributions']);                // unverified exception: not used
        $this->verify('assignments', $ex);
        $r = $this->dry();
        $this->assertSame('0.00', $r['result']['totals']['employee_contributions']);                             // verified exception wins
        $this->assertSame('employee', $r['meta']['excluded'][0]['by']);
    }

    public function test_blockers_when_basic_component_or_salary_is_missing(): void
    {
        $r = $this->dry();
        $this->assertContains('no_basic_component', $this->codes($r['blockers']));
        $this->assertNull($r['result']);
        $this->basic();
        DB::table('hr_employment_records')->where('employee_id', $this->emp->id)->delete();
        $this->assertContains('no_salary', $this->codes($this->dry()['blockers']));
    }

    public function test_recurring_items_and_variable_lines(): void
    {
        $this->basic();
        $this->as($this->prep);
        $rec = $this->postJson('/api/v1/payroll/components', ['code' => 'housing', 'name' => 'Housing', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'recurring'), 'is_taxable' => true, 'is_pensionable' => false])->json('data.id');
        $var = $this->postJson('/api/v1/payroll/components', ['code' => 'overtime', 'name' => 'Overtime', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'variable'), 'is_taxable' => true, 'is_pensionable' => false])->json('data.id');
        $this->postJson('/api/v1/payroll/items', ['employee_id' => $this->emp->id, 'component_id' => $var, 'amount' => 1000, 'valid_from' => '2026-01-01'])->assertStatus(422);       // only recurring components
        $item = $this->postJson('/api/v1/payroll/items', ['employee_id' => $this->emp->id, 'component_id' => $rec, 'amount' => 80000, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->assertContains('unverified_item', $this->codes($this->dry()['warnings']));
        $this->assertSame('500000.00', $this->dry()['result']['totals']['gross']);
        $this->verify('items', $item);
        $this->assertSame('580000.00', $this->dry()['result']['totals']['gross']);
        $this->assertSame('595000.00', $this->dry(['lines' => [['component_id' => $var, 'amount' => 15000]]])['result']['totals']['gross']);
        $this->assertContains('bad_extra_line', $this->codes($this->dry(['lines' => [['component_id' => $rec, 'amount' => 5]]])['blockers']));
    }

    public function test_the_dry_run_writes_nothing_to_a_payroll_table_and_is_audited_without_amounts(): void
    {
        $this->basic();
        $s = $this->flatScheme();
        $this->verify('scheme-versions', $this->version($s));
        $counts = fn () => [DB::table('pay_components')->count(), DB::table('pay_schemes')->count(), DB::table('pay_scheme_versions')->count(), DB::table('pay_scheme_assignments')->count(), DB::table('pay_employee_items')->count()];
        $before = $counts();
        $this->dry();
        $this->dry();
        $this->assertSame($before, $counts());
        $log = DB::table('audit_logs')->where('action', 'payroll.dry_run')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('500000', json_encode($log));
    }

    public function test_the_dry_run_respects_the_employee_scope(): void
    {
        $this->basic();
        $this->as($this->prep);
        $this->postJson('/api/v1/payroll/dry-run', ['employee_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertStatus(422);   // not an employee of this organization
    }
}
