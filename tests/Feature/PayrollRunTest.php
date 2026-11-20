<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\PayPayslip;
use App\Models\PayRun;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs bundles 20 and 21 (HrPayrollSeeder, HrPayrollRunSeeder).
 * Every rate and amount is a TEST INPUT (invented round numbers), never a statement of Rwandan law.
 */
class PayrollRunTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $prep;
    private User $rev;
    private User $fin;
    private User $md;
    private User $lock;
    private User $staffUser;
    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Carbon::setTestNow('2026-12-02 09:00:00');
        foreach (['prep', 'rev', 'fin', 'md', 'lock'] as $n) {
            $this->$n = $this->makeUser('admin');                      // admin holds every payroll permission: the SERVICE enforces separation of duties
        }
        $this->staffUser = $this->makeUser('staff');
        $this->emp = $this->employee($this->staffUser, '2025-01-06', '500000');
        $this->configure();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(?User $u, string $hired, ?string $salary): Employee
    {
        $e = Employee::create(['first_name' => 'Pay', 'last_name' => 'Tester' . uniqid(), 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $u?->id, 'hired_on' => $hired]);
        if ($salary !== null) {
            EmploymentRecord::create([
                'employee_id' => $e->id,
                'change_type_id' => Lookup::id('hr_change_type', 'hire'),
                'status_id' => Lookup::id('employment_record_status', 'effective'),
                'effective_from' => $hired,
                'category_id' => Lookup::id('employee_category', 'contracted'),
                'salary_amount' => $salary,
                'salary_currency' => 'RWF',
                'created_by' => $this->prep->id
            ]);
        }
        return $e;
    }

    private function as(User $u): void
    {
        Sanctum::actingAs($u);
    }

    /** basic component + one flat 5 % scheme, verified and assigned to the category (test numbers) + a variable component. */
    private function configure(): void
    {
        $this->as($this->prep);
        $this->postJson('/api/v1/payroll/components', ['code' => 'basic', 'name' => 'Basic salary', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'basic_salary'), 'is_taxable' => true, 'is_pensionable' => true])->assertCreated();
        $this->postJson('/api/v1/payroll/components', ['code' => 'overtime', 'name' => 'Overtime', 'kind_id' => Lookup::id('pay_component_kind', 'earning'), 'source_id' => Lookup::id('pay_component_source', 'variable'), 'is_taxable' => true, 'is_pensionable' => false])->assertCreated();
        $s = $this->postJson('/api/v1/payroll/schemes', [
            'code' => 'pen',
            'name' => 'Pension',
            'statutory_type_id' => Lookup::id('pay_statutory_type', 'pension'),
            'mode_id' => Lookup::id('pay_scheme_mode', 'flat_rate'),
            'calc_order' => 1,
            'base_kind_id' => Lookup::id('pay_scheme_base', 'pensionable_earnings'),
            'reduces_taxable_base' => false,
            'rounding_id' => Lookup::id('pay_rounding', 'nearest'),
            'decimals' => 0
        ])->assertCreated()->json('data.id');
        $v = $this->postJson("/api/v1/payroll/schemes/$s/versions", ['employee_rate' => 5, 'employer_rate' => 5, 'valid_from' => '2026-01-01', 'source_citation' => 'Test schedule'])->assertCreated()->json('data.id');
        $a = $this->postJson('/api/v1/payroll/assignments', ['scheme_id' => $s, 'category_id' => Lookup::id('employee_category', 'contracted'), 'applies' => true, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->rev);
        $this->postJson("/api/v1/payroll/scheme-versions/$v/verify")->assertOk();
        $this->postJson("/api/v1/payroll/assignments/$a/verify")->assertOk();
    }

    private function runPayroll(array $o = []): string
    {
        $this->as($this->prep);
        return $this->postJson('/api/v1/payroll/runs', $o + ['period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'currency_id' => Lookup::id('currency', 'RWF')])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    private function calc(string $id): array
    {
        $this->as($this->prep);
        return $this->postJson("/api/v1/payroll/runs/$id/calculate")->assertOk()->json('data');
    }
    private function step(User $u, string $id, string $action, array $body = [], int $status = 200)
    {
        $this->as($u);
        return $this->postJson("/api/v1/payroll/runs/$id/$action", $body)->assertStatus($status);
    }

    /** reviewed by rev, approved by fin and md, locked by lock. */
    private function toLocked(string $id): void
    {
        $this->step($this->rev, $id, 'review');
        $this->step($this->fin, $id, 'approve-finance');
        $this->step($this->md, $id, 'approve-md');
        $this->step($this->lock, $id, 'lock');
    }

    public function test_calculation_persists_payslips_with_every_input(): void
    {
        $id = $this->runPayroll();
        $d = $this->calc($id);
        $this->assertSame('calculated', $d['status']);
        $this->assertSame('500000.00', $d['totals']['gross']);
        $this->assertSame('475000.00', $d['totals']['net']);
        $slip = PayPayslip::where('run_id', $id)->firstOrFail();
        $this->as($this->prep);
        $detail = $this->getJson("/api/v1/payroll/payslips/{$slip->id}")->assertOk()->json('data');
        $this->assertSame('25000.00', $detail['contributions'][0]['amount']);
        $this->assertSame('5.000000', $detail['contributions'][0]['rate']);
        $this->assertNotEmpty($detail['contributions'][0]['version_id']);                        // reproducible: the scheme version used is stored
        $this->assertSame('25000.00', $detail['employer'][0]['amount']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.payslip_viewed']);
    }

    public function test_one_regular_run_per_period(): void
    {
        $this->runPayroll();
        $this->as($this->prep);
        $this->postJson('/api/v1/payroll/runs', ['period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'currency_id' => Lookup::id('currency', 'RWF')])->assertStatus(422);
    }

    public function test_the_full_lifecycle_with_different_people_at_each_stage(): void
    {
        $id = $this->runPayroll();
        $this->step($this->rev, $id, 'review', [], 422);                          // nothing is reviewed before it is calculated
        $this->calc($id);
        $this->step($this->prep, $id, 'review', [], 422);                         // the preparer cannot review
        $this->step($this->rev, $id, 'review')->assertJsonPath('data.status', 'reviewed');
        $this->step($this->rev, $id, 'approve-finance', [], 422);                 // the reviewer cannot approve
        $this->step($this->prep, $id, 'approve-finance', [], 422);
        $this->step($this->md, $id, 'approve-md', [], 422);                       // finance approval comes first
        $this->step($this->fin, $id, 'approve-finance')->assertJsonPath('data.status', 'reviewed');   // MD approval still required by default
        $this->step($this->fin, $id, 'approve-md', [], 422);                      // the MD is not the finance approver
        $this->step($this->md, $id, 'approve-md')->assertJsonPath('data.status', 'approved');
        $this->step($this->prep, $id, 'lock', [], 422);                           // the preparer does not release the money
        $this->step($this->lock, $id, 'lock')->assertJsonPath('data.status', 'locked');
        $this->step($this->lock, $id, 'declare', ['declared_on' => '2026-12-02'], 422);   // not paid yet
        $this->step($this->prep, $id, 'pay', ['paid_on' => '2026-12-01'], 422);
        $this->step($this->lock, $id, 'pay', ['paid_on' => '2026-12-01', 'reference' => 'BATCH-1'])->assertJsonPath('data.status', 'paid');
        $this->step($this->lock, $id, 'declare', ['declared_on' => '2026-12-02', 'reference' => 'DECL-1'])->assertJsonPath('data.status', 'declared');
    }

    public function test_a_blocked_payslip_stops_the_review_until_it_is_fixed_or_excluded(): void
    {
        $noSalary = $this->employee(null, '2025-02-03', null);
        $id = $this->runPayroll();
        $d = $this->calc($id);
        $this->assertSame(1, $d['totals']['blocked']);
        $this->step($this->rev, $id, 'review', [], 422);
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/runs/$id/exclusions", ['employee_id' => $noSalary->id, 'reason' => ''])->assertStatus(422);
        $this->postJson("/api/v1/payroll/runs/$id/exclusions", ['employee_id' => $noSalary->id, 'reason' => 'Unpaid leave all month'])->assertNoContent();
        $d = $this->calc($id);
        $this->assertSame([0, 1], [$d['totals']['blocked'], $d['totals']['excluded']]);
        $this->step($this->rev, $id, 'review')->assertJsonPath('data.status', 'reviewed');
    }

    public function test_return_to_draft_clears_every_approval_and_never_works_after_lock(): void
    {
        $id = $this->runPayroll();
        $this->calc($id);
        $this->step($this->rev, $id, 'review');
        $this->step($this->fin, $id, 'approve-finance');
        $this->step($this->md, $id, 'return', ['reason' => ''], 422);
        $this->step($this->md, $id, 'return', ['reason' => 'Overtime missing'])->assertJsonPath('data.status', 'draft');
        $run = PayRun::findOrFail($id);
        $this->assertNull($run->reviewed_by);
        $this->assertNull($run->finance_by);
        $this->calc($id);
        $this->toLocked($id);
        $this->step($this->md, $id, 'return', ['reason' => 'x'], 422);
        $this->step($this->prep, $id, 'cancel', ['reason' => 'x'], 422);
    }

    public function test_a_locked_run_is_immutable_even_for_the_code_itself(): void
    {
        $id = $this->runPayroll();
        $this->calc($id);
        $this->toLocked($id);
        $this->step($this->prep, $id, 'calculate', [], 422);
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/runs/$id/inputs", ['employee_id' => $this->emp->id, 'component_id' => \App\Models\PayComponent::where('code', 'overtime')->value('id'), 'amount' => 1000])->assertStatus(422);
        $slip = PayPayslip::where('run_id', $id)->firstOrFail();
        $this->expectException(RuntimeException::class);                            // the model itself refuses: a bug in a service cannot change a locked payslip
        $slip->update(['net' => '1.00']);
    }

    public function test_inputs_change_the_payslip_only_through_a_recalculation(): void
    {
        $id = $this->runPayroll();
        $this->calc($id);
        $ot = \App\Models\PayComponent::where('code', 'overtime')->value('id');
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/runs/$id/inputs", ['employee_id' => $this->emp->id, 'component_id' => \App\Models\PayComponent::where('code', 'basic')->value('id'), 'amount' => 1])->assertStatus(422);   // not a variable component
        $this->postJson("/api/v1/payroll/runs/$id/inputs", ['employee_id' => $this->emp->id, 'component_id' => $ot, 'amount' => 40000])->assertCreated();
        $d = $this->calc($id);
        $this->assertSame('540000.00', $d['totals']['gross']);                      // 500000 + 40000 overtime
        $this->assertSame('515000.00', $d['totals']['net']);                        // 5 % pension applies to basic only (overtime is not pensionable)
    }

    public function test_an_adjustment_run_records_the_difference_against_the_original(): void
    {
        $id = $this->runPayroll();
        $this->calc($id);
        $this->as($this->prep);
        $this->postJson("/api/v1/payroll/runs/$id/adjustments", ['employee_ids' => [$this->emp->id]])->assertStatus(422);       // the original is not locked yet
        $this->toLocked($id);
        $this->as($this->prep);
        $adj = $this->postJson("/api/v1/payroll/runs/$id/adjustments", ['employee_ids' => [$this->emp->id], 'note' => 'Overtime forgotten'])->assertCreated()->assertJsonPath('data.kind', 'adjustment')->json('data.id');
        $this->postJson("/api/v1/payroll/runs/$adj/inputs", ['employee_id' => $this->emp->id, 'component_id' => \App\Models\PayComponent::where('code', 'overtime')->value('id'), 'amount' => 10000])->assertCreated();
        $d = $this->calc($adj);
        $this->assertSame('10000.00', $d['totals']['net_difference']);
        $slip = PayPayslip::where('run_id', $adj)->firstOrFail();
        $this->assertSame(PayPayslip::where('run_id', $id)->value('id'), $slip->original_payslip_id);
    }

    public function test_a_mid_period_hire_is_flagged_when_no_proration_rule_is_set(): void
    {
        $late = $this->employee(null, '2026-11-16', '300000');
        $id = $this->runPayroll();
        $this->calc($id);
        $slip = PayPayslip::where('run_id', $id)->where('employee_id', $late->id)->firstOrFail();
        $this->as($this->prep);
        $flags = collect($this->getJson("/api/v1/payroll/payslips/{$slip->id}")->json('data.flags'))->pluck('code');
        $this->assertTrue($flags->contains('partial_period_unprorated'));
        $this->assertSame('300000.00', (string) $slip->gross);                      // the default rule is "none": the accountant must choose
    }

    public function test_employees_see_only_their_own_payslips_and_only_once_paid(): void
    {
        $other = $this->makeUser('staff');
        $this->employee($other, '2025-03-03', '400000');
        $id = $this->runPayroll();
        $this->calc($id);
        $this->as($this->staffUser);
        $this->getJson('/api/v1/payroll/me/payslips')->assertOk()->assertJsonCount(0, 'data');            // not before it is paid
        $this->toLocked($id);
        $this->as($this->staffUser);
        $this->getJson('/api/v1/payroll/me/payslips')->assertOk()->assertJsonCount(0, 'data');            // locked is still not visible
        $this->step($this->lock, $id, 'pay', ['paid_on' => '2026-12-01']);
        $this->as($this->staffUser);
        $mine = $this->getJson('/api/v1/payroll/me/payslips')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->getJson("/api/v1/payroll/me/payslips/$mine")->assertOk()->assertJsonPath('data.employee.name', $this->emp->full_name)->assertJsonPath('data.flags', []);
        $this->as($other);
        $this->getJson("/api/v1/payroll/me/payslips/$mine")->assertNotFound();                             // somebody else's payslip: 404
        $this->getJson("/api/v1/payroll/me/payslips/$mine/pdf")->assertNotFound();
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();                                         // no access to the payroll desk
    }

    public function test_the_payslip_pdf_is_a_pdf(): void
    {
        $id = $this->runPayroll();
        $this->calc($id);
        $slip = PayPayslip::where('run_id', $id)->firstOrFail();
        $this->as($this->prep);
        $r = $this->get("/api/v1/payroll/payslips/{$slip->id}/pdf?locale=fr")->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
    }
}
