<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Trip;
use App\Models\TripAdvance;
use App\Models\TripDeductionConsent;
use App\Models\TripExpense;
use App\Models\User;
use App\Services\FileService;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs bundles 17 and 18. Rates and amounts are TEST INPUTS. */
class TripTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $admin;      // admin holds every permission, including hr.trip.finance
    private User $hr;
    private User $mu;
    private User $u;
    private Employee $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Storage::fake(FileService::disk());
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->admin = $this->makeUser('admin');
        $this->hr = $this->makeUser('hr_manager');
        [$this->mu, $manager] = $this->person('manager');
        [$this->u, $this->e] = $this->person('staff', $manager);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $role, ?Employee $manager = null): array
    {
        $u = $this->makeUser($role);
        $e = Employee::create(['first_name' => ucfirst($role), 'last_name' => 'Tester', 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'),
            'user_id' => $u->id, 'hired_on' => '2025-01-06', 'manager_employee_id' => $manager?->id]);
        return [$u, $e];
    }

    private function body(array $o = []): array
    {
        return $o + ['purpose' => 'Partner meeting', 'destination' => 'Nairobi', 'zone_id' => Lookup::id('trip_zone', 'regional'), 'start_date' => '2026-12-02', 'end_date' => '2026-12-05',
            'currency_id' => Lookup::id('currency', 'RWF'), 'budget_estimate' => 500000, 'advances' => [['employee_id' => $this->e->id, 'amount' => 200000]]];
    }

    private function approvedTrip(array $o = []): string
    {
        Sanctum::actingAs($this->u);
        $id = $this->postJson('/api/v1/trips', $this->body($o))->assertCreated()->json('data.id');
        $approval = Trip::findOrFail($id)->approval_request_id;
        Sanctum::actingAs($this->mu);
        $this->postJson("/api/v1/approvals/$approval/act", ['decision' => 'approve'])->assertOk();
        return $id;
    }

    private function rate(string $amount = '30000'): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/trips/rates', ['kind_id' => Lookup::id('trip_rate_kind', 'per_diem'), 'zone_id' => Lookup::id('trip_zone', 'regional'), 'currency_id' => Lookup::id('currency', 'RWF'),
            'amount' => $amount, 'valid_from' => '2026-01-01', 'source_note' => 'Test decision'])->assertCreated();
    }

    private function expense(string $id, array $o = []): string
    {
        return $this->postJson("/api/v1/trips/$id/expenses", $o + ['category_id' => Lookup::id('trip_expense_category', 'transport'), 'expense_date' => '2026-12-03', 'description' => 'Bus', 'amount' => 40000])->assertCreated()->json('data.id');
    }

    private function receipt(string $expenseId): void
    {
        $this->post("/api/v1/trips/expenses/$expenseId/files", ['file' => UploadedFile::fake()->create('r.pdf', 5, 'application/pdf')])->assertCreated();
    }

    public function test_request_rules(): void
    {
        Sanctum::actingAs($this->u);
        $this->postJson('/api/v1/trips', $this->body(['end_date' => '2026-12-01']))->assertStatus(422);
        $this->postJson('/api/v1/trips', $this->body(['advances' => [['employee_id' => $this->e->id, 'amount' => 600000]]]))->assertStatus(422);       // above the budget
        $id = $this->postJson('/api/v1/trips', $this->body())->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->postJson('/api/v1/trips', $this->body(['start_date' => '2026-12-04', 'end_date' => '2026-12-08']))->assertStatus(422);                         // the traveller is already away
        $this->assertStringStartsWith('TRP', Trip::findOrFail($id)->code);
        $this->assertNull(Trip::findOrFail($id)->authorization_number);                                                                                      // no mission authorization before approval
    }

    public function test_a_traveller_outside_my_scope_cannot_be_added(): void
    {
        [, $other] = $this->person('staff');
        Sanctum::actingAs($this->u);
        $this->postJson('/api/v1/trips', $this->body(['traveler_ids' => [$other->id], 'advances' => []]))->assertStatus(422);
    }

    public function test_approval_issues_the_mission_authorization(): void
    {
        $id = $this->approvedTrip();
        $t = Trip::findOrFail($id);
        $this->assertSame('approved', $t->status->code);
        $this->assertStringStartsWith('MA', $t->authorization_number);
        Sanctum::actingAs($this->u);
        $this->getJson("/api/v1/trips/$id/authorization")->assertOk()->assertJsonPath('data.number', $t->authorization_number)->assertJsonPath('data.destination', 'Nairobi');
    }

    public function test_the_requester_cannot_approve_and_outsiders_get_404(): void
    {
        Sanctum::actingAs($this->u);
        $id = $this->postJson('/api/v1/trips', $this->body())->assertCreated()->json('data.id');
        $this->assertContains($this->postJson('/api/v1/approvals/' . Trip::findOrFail($id)->approval_request_id . '/act', ['decision' => 'approve'])->status(), [403, 422]);
        [$ou] = $this->person('staff');
        Sanctum::actingAs($ou);
        $this->getJson("/api/v1/trips/$id")->assertNotFound();
    }

    public function test_per_diem_comes_from_the_rate_table_and_never_from_a_guess(): void
    {
        $id = $this->approvedTrip();
        Sanctum::actingAs($this->u);
        $r = $this->postJson("/api/v1/trips/$id/per-diem")->assertOk()->json('data');
        $this->assertSame(0, $r['created']);
        $this->assertCount(1, $r['missing_rate']);                                          // no rate yet: no line, no invented amount
        $this->rate('30000');
        Sanctum::actingAs($this->u);
        $this->assertSame(1, $this->postJson("/api/v1/trips/$id/per-diem")->assertOk()->json('data.created'));
        $this->assertSame('120000.00', (string) TripExpense::where('trip_id', $id)->where('is_generated', true)->value('amount'));   // 4 days x 30000
        $this->postJson("/api/v1/trips/$id/per-diem")->assertOk();                          // repeat: refreshed, not duplicated
        $this->assertSame(1, TripExpense::where('trip_id', $id)->where('is_generated', true)->count());
    }

    public function test_expense_validation(): void
    {
        $id = $this->approvedTrip();
        Sanctum::actingAs($this->u);
        $this->postJson("/api/v1/trips/$id/expenses", ['category_id' => Lookup::id('trip_expense_category', 'transport'), 'expense_date' => '2026-12-20', 'description' => 'x', 'amount' => 10])->assertStatus(422);   // outside the trip
        $this->postJson("/api/v1/trips/$id/expenses", ['category_id' => Lookup::id('trip_expense_category', 'per_diem'), 'expense_date' => '2026-12-03', 'description' => 'x', 'amount' => 10])->assertStatus(422);  // per diem is generated
        $this->expense($id);
    }

    public function test_the_report_needs_receipts_then_finance_reviews_and_settles(): void
    {
        $id = $this->approvedTrip();
        $adv = TripAdvance::where('trip_id', $id)->firstOrFail();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/trips/$id/advances/{$adv->id}/pay", ['paid_on' => '2026-11-16'])->assertOk();
        $this->assertSame('2026-12-19', $adv->refresh()->due_on->toDateString());               // trip end + the default retirement period

        Sanctum::actingAs($this->u);
        $x = $this->expense($id, ['amount' => 150000]);
        $this->postJson("/api/v1/trips/$id/report", ['report_text' => 'Done'])->assertStatus(422);              // a receipt is missing
        $this->receipt($x);
        $this->postJson("/api/v1/trips/$id/report", ['report_text' => ''])->assertStatus(422);
        $this->postJson("/api/v1/trips/$id/report", ['report_text' => 'Meeting held'])->assertOk()->assertJsonPath('data.status', 'report_submitted');
        $this->postJson("/api/v1/trips/$id/expenses", ['category_id' => Lookup::id('trip_expense_category', 'meals'), 'expense_date' => '2026-12-03', 'description' => 'late', 'amount' => 5])->assertStatus(422);   // closed for new expenses

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/trips/$id/settle", ['employee_id' => $this->e->id, 'resolution' => 'paid_to_employee'])->assertStatus(422);       // expense not reviewed yet
        $this->postJson("/api/v1/trips/expenses/$x/review", ['decision' => 'reject'])->assertStatus(422);       // a rejection needs a reason
        $this->postJson("/api/v1/trips/expenses/$x/review", ['decision' => 'accept'])->assertNoContent();
        $p = $this->getJson("/api/v1/trips/$id/settlement-preview?employee_id={$this->e->id}")->assertOk()->json('data');
        $this->assertSame('-50000.00', $p['balance']);                                           // 150000 accepted - 200000 advance: the traveller owes 50000
        $this->postJson("/api/v1/trips/$id/settle", ['employee_id' => $this->e->id, 'resolution' => 'paid_to_employee'])->assertStatus(422);       // wrong direction
        $this->postJson("/api/v1/trips/$id/settle", ['employee_id' => $this->e->id, 'resolution' => 'payroll_deduction'])->assertStatus(422);      // no consent recorded
        $this->postJson("/api/v1/trips/$id/settle", ['employee_id' => $this->e->id, 'resolution' => 'payroll_deduction', 'consent' => ['consented_on' => '2026-11-16', 'lawful_basis' => 'Signed consent form']])->assertOk()
            ->assertJsonPath('data.status', 'settled');
        $this->assertSame('50000.00', (string) TripDeductionConsent::firstOrFail()->amount);
        $this->assertSame('retired', $adv->refresh()->status->code);
    }

    public function test_unretired_advances_are_listed_when_late(): void
    {
        $id = $this->approvedTrip();
        $adv = TripAdvance::where('trip_id', $id)->firstOrFail();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/trips/$id/advances/{$adv->id}/pay", ['paid_on' => '2026-11-16'])->assertOk();
        $this->getJson('/api/v1/trips/unretired-advances')->assertOk()->assertJsonCount(0, 'data');
        Carbon::setTestNow('2026-12-25 09:00:00');
        $this->getJson('/api/v1/trips/unretired-advances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.days_late', 6);
    }

    public function test_a_trip_with_a_paid_advance_is_settled_not_cancelled(): void
    {
        $id = $this->approvedTrip();
        $adv = TripAdvance::where('trip_id', $id)->firstOrFail();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/trips/$id/advances/{$adv->id}/pay", ['paid_on' => '2026-11-16'])->assertOk();
        Sanctum::actingAs($this->u);
        $this->postJson("/api/v1/trips/$id/cancel", ['reason' => 'x'])->assertStatus(422);
    }

    public function test_rates_need_configure_and_are_ended_not_edited(): void
    {
        Sanctum::actingAs($this->u);
        $this->postJson('/api/v1/trips/rates', [])->assertForbidden();
        $this->rate('30000');
        $rate = $this->getJson('/api/v1/trips/rates')->assertOk()->json('data.0.id');
        $this->postJson("/api/v1/trips/rates/$rate/end", ['valid_to' => '2026-12-31'])->assertOk()->assertJsonPath('data.valid_to', '2026-12-31');
        $this->assertDatabaseHas('audit_logs', ['action' => 'trip.rate_ended']);
    }
}
