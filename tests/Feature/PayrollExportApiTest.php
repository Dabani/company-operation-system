<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\PayExport;
use App\Models\PayRun;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs bundles 20, 21 and 22.
 * Every layout, account and amount is a TEST INPUT: no real bank, RRA or RSSB format is built in.
 */
class PayrollExportApiTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $prep;
    private User $rev;
    private User $fin;
    private User $md;
    private User $lock;
    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Carbon::setTestNow('2026-12-02 09:00:00');
        foreach (['prep', 'rev', 'fin', 'md', 'lock'] as $n) {
            $this->$n = $this->makeUser('admin');
        }
        $this->emp = $this->employee('2025-01-06', '500000', '0123456789');
        $this->configure();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $u): void { Sanctum::actingAs($u); }

    private function employee(string $hired, ?string $salary, ?string $bank): Employee
    {
        $e = Employee::create(['first_name' => 'Pay', 'last_name' => 'Tester' . uniqid(), 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'), 'hired_on' => $hired, 'bank_account' => $bank]);
        EmploymentRecord::create(['employee_id' => $e->id, 'change_type_id' => Lookup::id('hr_change_type', 'hire'), 'status_id' => Lookup::id('employment_record_status', 'effective'), 'effective_from' => $hired,
            'category_id' => Lookup::id('employee_category', 'contracted'), 'salary_amount' => $salary, 'salary_currency' => 'RWF', 'created_by' => $this->prep->id]);
        return $e;
    }

    private string $schemeId = '';

    private function configure(): void
    {
        $this->as($this->prep);
        $this->postJson('/api/v1/payroll/components', ['code' => 'basic', 'name' => 'Basic salary', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'basic_salary'), 'is_taxable' => true, 'is_pensionable' => true])->assertCreated();
        $this->schemeId = $this->postJson('/api/v1/payroll/schemes', ['code' => 'pen', 'name' => 'Pension', 'statutory_type_id' => Lookup::id('pay_statutory_type', 'pension'), 'mode_id' => Lookup::id('pay_scheme_mode', 'flat_rate'), 'calc_order' => 1,
            'base_kind_id' => Lookup::id('pay_scheme_base', 'pensionable_earnings'), 'reduces_taxable_base' => false, 'rounding_id' => Lookup::id('pay_rounding', 'nearest'), 'decimals' => 0])->assertCreated()->json('data.id');
        $v = $this->postJson("/api/v1/payroll/schemes/{$this->schemeId}/versions", ['employee_rate' => 5, 'employer_rate' => 5, 'valid_from' => '2026-01-01', 'source_citation' => 'Test schedule'])->assertCreated()->json('data.id');
        $a = $this->postJson('/api/v1/payroll/assignments', ['scheme_id' => $this->schemeId, 'category_id' => Lookup::id('employee_category', 'contracted'), 'applies' => true, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/scheme-versions/$v/verify")->assertOk();
        $this->postJson("/api/v1/payroll/assignments/$a/verify")->assertOk();
    }

    private function step(User $u, string $id, string $action, array $body = [], int $status = 200)
    {
        $this->as($u);
        return $this->postJson("/api/v1/payroll/runs/$id/$action", $body)->assertStatus($status);
    }

    /** A locked run; pass $pay = true to also mark it paid. */
    private function lockedRun(bool $pay = false): string
    {
        $this->as($this->prep);
        $id = $this->postJson('/api/v1/payroll/runs', ['period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'currency_id' => Lookup::id('currency', 'RWF')])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/$id/calculate")->assertOk();
        $this->step($this->rev, $id, 'review');
        $this->step($this->fin, $id, 'approve-finance');
        $this->step($this->md, $id, 'approve-md');
        $this->step($this->lock, $id, 'lock');
        if ($pay) {
            $this->step($this->lock, $id, 'pay', ['paid_on' => '2026-12-01']);
        }
        return $id;
    }

    private function bankProfile(array $o = []): array
    {
        return $o + ['code' => 'bank_csv', 'name' => 'Test bank CSV', 'kind_id' => Lookup::id('pay_export_kind', 'bank_transfer'), 'layout_id' => Lookup::id('pay_export_layout', 'delimited'), 'delimiter' => ';', 'quote_char' => '"', 'line_ending' => 'LF',
            'encoding' => 'UTF-8', 'decimal_separator' => '.', 'date_format' => 'Y-m-d', 'include_header' => true, 'file_extension' => 'csv', 'filename_template' => 'bank-{run_code}', 'agreed_with' => 'Test bank, ref T-1 (invented)',
            'header_template' => null, 'trailer_template' => 'TOTAL;{count};{total}', 'columns' => [
                ['header' => 'Account', 'source' => 'bank_account', 'format' => 'text'], ['header' => 'Name', 'source' => 'employee_name', 'format' => 'text'], ['header' => 'Amount', 'source' => 'pay_amount', 'format' => 'amount', 'decimals' => 0]]];
    }

    private function verifiedProfile(array $o = []): string
    {
        $this->as($this->prep);
        $id = $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile($o))->assertCreated()->assertJsonPath('data.verification', 'unverified')->json('data.id');
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/export-profiles/$id/verify")->assertOk();
        return $id;
    }

    public function test_nothing_is_prefilled_and_staff_have_no_access(): void
    {
        $this->as($this->prep);
        $this->getJson('/api/v1/payroll/export-profiles')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/payroll/journal-accounts')->assertOk()->assertJsonPath('data', []);
        $this->as($this->makeUser('staff'));
        $this->getJson('/api/v1/payroll/export-profiles')->assertForbidden();
    }

    public function test_a_layout_is_validated_when_it_is_defined(): void
    {
        $this->as($this->prep);
        $scheme = ['header' => 'Pension', 'source' => 'scheme_employee_amount', 'scheme_id' => $this->schemeId, 'format' => 'amount'];
        $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile(['columns' => [$scheme]]))->assertStatus(422);                                // a scheme field is not available for a bank file
        $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile(['agreed_with' => '']))->assertStatus(422);                                    // who agreed it is mandatory
        $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile(['layout_id' => Lookup::id('pay_export_layout', 'fixed_width')]))->assertStatus(422);   // fixed width needs widths
        $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile(['trailer_template' => 'X;{nope}']))->assertStatus(422);                      // unknown placeholder
        $this->assertSame(0, \App\Models\PayExportProfile::count());                                                                                          // a refused layout leaves nothing behind
    }

    public function test_a_layout_needs_a_second_person_and_a_verified_layout_is_final(): void
    {
        $this->as($this->prep);
        $id = $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/export-profiles/$id/verify")->assertStatus(422);                              // not the author
        $preview = $this->getJson("/api/v1/payroll/export-profiles/$id/preview")->assertOk()->json('data');
        $this->assertTrue($preview['sample']);
        $this->assertStringContainsString('SAMPLE EMPLOYEE 1', $preview['text']);                                      // invented rows only
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/export-profiles/$id/verify")->assertOk()->assertJsonPath('data.verification', 'verified');
        $this->as($this->prep);
        $this->deleteJson("/api/v1/payroll/export-profiles/$id")->assertStatus(422);
        $this->patchJson("/api/v1/payroll/export-profiles/$id/active", ['is_active' => false])->assertOk();
    }

    public function test_an_unverified_or_inactive_layout_generates_nothing(): void
    {
        $run = $this->lockedRun();
        $this->as($this->prep);
        $id = $this->postJson('/api/v1/payroll/export-profiles', $this->bankProfile())->assertCreated()->json('data.id');
        $this->as($this->lock);
        $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $id])->assertStatus(422);
    }

    public function test_the_bank_file_only_after_lock_by_someone_other_than_the_preparer(): void
    {
        $profile = $this->verifiedProfile();
        $this->as($this->prep);
        $draft = $this->postJson('/api/v1/payroll/runs', ['period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'currency_id' => Lookup::id('currency', 'RWF')])->json('data.id');
        $this->postJson("/api/v1/payroll/runs/$draft/exports", ['profile_id' => $profile])->assertStatus(422);              // a draft run produces no money file
        $run = $this->lockedRun();
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $profile])->assertStatus(422);                // the preparer does not release the money
        $this->as($this->lock);
        $e = $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $profile])->assertCreated()->assertJsonPath('data.rows', 1)->assertJsonPath('data.total_amount', '475000.00')->json('data');
        $r = $this->get("/api/v1/payroll/exports/{$e['id']}/download")->assertOk();
        $this->assertSame("Account;Name;Amount\n0123456789;{$this->emp->full_name};475000\nTOTAL;1;475000.00\n", $r->getContent());
        $this->assertSame($e['checksum'], hash('sha256', $r->getContent()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.export_generated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.export_downloaded']);
        $this->assertNotSame('0123456789', \DB::table('pay_exports')->value('content'));                                     // encrypted at rest
    }

    public function test_an_employee_without_a_bank_account_blocks_the_bank_file(): void
    {
        $noBank = $this->employee('2025-02-03', '300000', null);
        $profile = $this->verifiedProfile();
        $run = $this->lockedRun();
        $this->as($this->lock);
        $r = $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $profile])->assertStatus(422);
        $this->assertStringContainsString($noBank->full_name, json_encode($r->json()));
        $this->assertSame(0, PayExport::count());
    }

    public function test_the_journal_needs_every_account_and_balances(): void
    {
        $this->as($this->prep);
        $j = $this->postJson('/api/v1/payroll/export-profiles', ['code' => 'journal_csv', 'name' => 'Journal', 'kind_id' => Lookup::id('pay_export_kind', 'journal'), 'layout_id' => Lookup::id('pay_export_layout', 'delimited'), 'delimiter' => ';', 'quote_char' => '"',
            'line_ending' => 'LF', 'encoding' => 'UTF-8', 'decimal_separator' => '.', 'date_format' => 'Y-m-d', 'include_header' => true, 'file_extension' => 'csv', 'filename_template' => 'journal-{run_code}', 'agreed_with' => 'Test accountant (invented)',
            'columns' => [['header' => 'Account', 'source' => 'journal_account', 'format' => 'text'], ['header' => 'Debit', 'source' => 'journal_debit', 'format' => 'amount'], ['header' => 'Credit', 'source' => 'journal_credit', 'format' => 'amount']]])->assertCreated()->json('data.id');
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/export-profiles/$j/verify")->assertOk();
        $run = $this->lockedRun();
        $this->as($this->lock);
        $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $j])->assertStatus(422);                      // no account configured: nothing is guessed
        $keys = collect($this->getJson("/api/v1/payroll/runs/$run/journal-accounts")->assertOk()->json('data'))->pluck('key')->all();
        $this->assertContains('net_pay', $keys);
        $this->assertContains('component.basic', $keys);
        $this->as($this->prep);
        $this->putJson('/api/v1/payroll/journal-accounts', ['accounts' => array_map(fn ($k, $i) => ['account_key' => $k, 'account_code' => (string) (6000 + $i)], $keys, array_keys($keys))])->assertOk();
        $this->as($this->lock);
        $e = $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $j])->assertCreated()->json('data');
        $this->assertSame('525000.00', $e['total_amount']);                                                                 // debits = credits = gross + employer contribution
    }

    public function test_declarations_only_after_payment_and_never_for_adjustment_runs(): void
    {
        $this->as($this->prep);
        $d = $this->postJson('/api/v1/payroll/export-profiles', ['code' => 'decl', 'name' => 'Declaration', 'kind_id' => Lookup::id('pay_export_kind', 'declaration'), 'layout_id' => Lookup::id('pay_export_layout', 'delimited'), 'delimiter' => ';', 'quote_char' => '"',
            'line_ending' => 'LF', 'encoding' => 'UTF-8', 'decimal_separator' => '.', 'date_format' => 'Y-m-d', 'include_header' => false, 'file_extension' => 'csv', 'filename_template' => 'decl-{run_code}', 'agreed_with' => 'Test authority (invented)',
            'columns' => [['header' => 'Name', 'source' => 'employee_name', 'format' => 'text'], ['header' => 'Pension', 'source' => 'scheme_employee_amount', 'scheme_id' => $this->schemeId, 'format' => 'amount', 'decimals' => 0]]])->assertCreated()->json('data.id');
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/export-profiles/$d/verify")->assertOk();
        $run = $this->lockedRun();
        $this->as($this->lock);
        $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $d])->assertStatus(422);                      // only locked: not yet paid
        $this->step($this->lock, $run, 'pay', ['paid_on' => '2026-12-01']);
        $this->as($this->lock);
        $e = $this->postJson("/api/v1/payroll/runs/$run/exports", ['profile_id' => $d])->assertCreated()->json('data');
        $this->assertSame("{$this->emp->full_name};25000\n", $this->get("/api/v1/payroll/exports/{$e['id']}/download")->getContent());
    }

    public function test_an_adjustment_run_supports_the_bank_file_but_not_declarations(): void
    {
        $bank = $this->verifiedProfile();
        $this->as($this->prep);
        $decl = $this->postJson('/api/v1/payroll/export-profiles', ['code' => 'decl', 'name' => 'Declaration', 'kind_id' => Lookup::id('pay_export_kind', 'declaration'), 'layout_id' => Lookup::id('pay_export_layout', 'delimited'), 'delimiter' => ';', 'quote_char' => '"',
            'line_ending' => 'LF', 'encoding' => 'UTF-8', 'decimal_separator' => '.', 'date_format' => 'Y-m-d', 'include_header' => false, 'file_extension' => 'csv', 'filename_template' => 'decl-{run_code}', 'agreed_with' => 'Test authority (invented)',
            'columns' => [['header' => 'Name', 'source' => 'employee_name', 'format' => 'text']]])->assertCreated()->json('data.id');
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/export-profiles/$decl/verify")->assertOk();
        $run = $this->lockedRun(true);
        $this->as($this->prep);
        $adj = $this->postJson("/api/v1/payroll/runs/$run/adjustments", ['employee_ids' => [$this->emp->id]])->assertCreated()->json('data.id');
        $ot = $this->postJson('/api/v1/payroll/components', ['code' => 'overtime', 'name' => 'Overtime', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'variable'), 'is_taxable' => true, 'is_pensionable' => false])->json('data.id');
        $this->postJson("/api/v1/payroll/runs/$adj/inputs", ['employee_id' => $this->emp->id, 'component_id' => $ot, 'amount' => 10000])->assertCreated();
        $this->postJson("/api/v1/payroll/runs/$adj/calculate")->assertOk();
        $this->step($this->rev, $adj, 'review');
        $this->step($this->fin, $adj, 'approve-finance');
        $this->step($this->md, $adj, 'approve-md');
        $this->step($this->lock, $adj, 'lock');
        $this->as($this->lock);
        $this->postJson("/api/v1/payroll/runs/$adj/exports", ['profile_id' => $decl])->assertStatus(422);                    // declarations of corrections are handled with the accountant
        $e = $this->postJson("/api/v1/payroll/runs/$adj/exports", ['profile_id' => $bank])->assertCreated()->json('data');     // the bank file pays only the difference
        $this->assertSame('10000.00', $e['total_amount']);
    }
}
