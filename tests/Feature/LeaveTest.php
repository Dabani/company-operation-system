<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\Hr\Attendance\LeaveLookup;
use App\Services\Hr\Leave\LeaveBalanceService;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs bundle 17. Legal numbers below are TEST INPUTS, not the law. */
class LeaveTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->hr = $this->makeUser('hr_manager');
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

    private function type(string $code): string { return Lookup::id('leave_type', $code); }

    private function body(string $from = '2026-12-07', string $to = '2026-12-09', array $o = []): array
    {
        return $o + ['leave_type_id' => $this->type('annual'), 'start_date' => $from, 'end_date' => $to];
    }

    private function giveBalance(Employee $e, string $days): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/leave/adjustments', ['employee_id' => $e->id, 'leave_type_id' => $this->type('annual'), 'days' => $days, 'reason' => 'Opening balance'])->assertCreated();
    }

    private function floorRule(int $value = 18): string
    {
        Sanctum::actingAs($this->hr);
        return $this->postJson('/api/v1/leave/legal-rules', ['rule_key' => 'entitlement_days', 'bound_type_id' => Lookup::id('legal_bound', 'floor'), 'value' => $value, 'unit' => 'days',
            'valid_from' => '2026-01-01', 'source_citation' => 'Test citation', 'leave_type_id' => $this->type('annual')])->assertCreated()->assertJsonPath('data.verification', 'unverified')->json('data.id');
    }

    private function verify(string $rule): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson("/api/v1/leave/legal-rules/$rule/verify")->assertOk()->assertJsonPath('data.verification', 'verified');
    }

    public function test_leave_types_are_seeded_and_no_legal_value_is_prefilled(): void
    {
        Sanctum::actingAs($this->hr);
        $types = array_column($this->getJson('/api/v1/leave/types')->assertOk()->json('data'), 'leave_type');
        $this->assertContains('annual', $types);
        $this->assertContains('sick', $types);
        $this->getJson('/api/v1/leave/legal-rules')->assertOk()->assertJsonPath('data', []);
    }

    public function test_no_balance_blocks_a_request_and_nothing_is_created(): void
    {
        [$u] = $this->person('staff');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/leave/requests', $this->body())->assertStatus(422);
        $this->assertSame(0, LeaveRequest::count());
        $p = $this->postJson('/api/v1/leave/requests/preview', $this->body())->assertOk()->json('data');
        $this->assertContains('insufficient_balance', array_column($p['blockers'], 'code'));
        $this->assertSame('3.00', $p['days']);
    }

    public function test_request_approval_ledger_attendance_and_cancellation(): void
    {
        [$mu, $manager] = $this->person('manager');
        [$u, $e] = $this->person('staff', $manager);
        $this->giveBalance($e, '10');

        Sanctum::actingAs($u);
        $id = $this->postJson('/api/v1/leave/requests', $this->body())->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $bal = app(LeaveBalanceService::class);
        $this->assertSame('10.00', $bal->balance($this->org->id, $e->id, $this->type('annual')));          // nothing is deducted before approval...
        $p = $this->postJson('/api/v1/leave/requests/preview', $this->body('2026-12-10', '2026-12-18'))->assertOk()->json('data');
        $this->assertSame('7.00', $p['available']);                                                      // ...but the days are reserved
        $this->postJson('/api/v1/leave/requests', $this->body('2026-12-08', '2026-12-08'))->assertStatus(422);   // overlaps the pending request

        $approval = LeaveRequest::findOrFail($id)->approval_request_id;
        $this->assertContains($this->postJson("/api/v1/approvals/$approval/act", ['decision' => 'approve'])->status(), [403, 422]);   // the requester cannot approve their own request (403 before the baseline access seeder, 422 from the engine after it)
        Sanctum::actingAs($mu);
        $this->postJson("/api/v1/approvals/$approval/act", ['decision' => 'approve'])->assertOk();

        $this->assertSame('approved', LeaveRequest::findOrFail($id)->status->code);
        $this->assertSame('7.00', $bal->balance($this->org->id, $e->id, $this->type('annual')));
        $this->assertSame(3, LeaveRequestDay::where('request_id', $id)->count());
        $this->assertTrue(app(LeaveLookup::class)->onApprovedLeave($e->id, '2026-12-08'));
        $this->assertFalse(app(LeaveLookup::class)->onApprovedLeave($e->id, '2026-12-10'));

        Sanctum::actingAs($u);
        $this->postJson("/api/v1/leave/requests/$id/cancel", ['reason' => 'Plans changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('10.00', $bal->balance($this->org->id, $e->id, $this->type('annual')));          // the days come back through a reversal entry
        $this->assertSame(0, LeaveRequestDay::where('request_id', $id)->count());
        $this->assertSame(3, LeaveLedgerEntry::where('employee_id', $e->id)->count());                      // adjustment + usage + reversal: nothing was edited or deleted
    }

    public function test_public_holiday_and_weekend_are_not_counted(): void
    {
        [$u] = $this->person('staff');
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/holidays', ['holiday_date' => '2026-12-09', 'name' => 'Test holiday'])->assertCreated();
        Sanctum::actingAs($u);
        $p = $this->postJson('/api/v1/leave/requests/preview', $this->body('2026-12-07', '2026-12-13'))->assertOk()->json('data');   // Mon-Sun
        $this->assertSame('4.00', $p['days']);
    }

    public function test_statutory_floor_is_applied_only_when_verified_by_a_second_person(): void
    {
        $rule = $this->floorRule();
        [, $e] = $this->person('staff');
        $url = "/api/v1/leave/policy/preview?employee_id={$e->id}&leave_type_id=" . $this->type('annual') . '&date=2026-11-16';
        Sanctum::actingAs($this->hr);
        $this->getJson($url)->assertOk()->assertJsonPath('data.annual_entitlement', '0.00');                  // unverified: shown, never applied
        $this->postJson("/api/v1/leave/legal-rules/$rule/verify")->assertStatus(403);                        // HR lacks rules.signoff
        $this->verify($rule);
        Sanctum::actingAs($this->hr);
        $this->getJson($url)->assertOk()->assertJsonPath('data.annual_entitlement', '18.00');
    }

    public function test_the_author_cannot_verify_their_own_rule(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $rule = $this->postJson('/api/v1/leave/legal-rules', ['rule_key' => 'entitlement_days', 'bound_type_id' => Lookup::id('legal_bound', 'floor'), 'value' => 18, 'valid_from' => '2026-01-01', 'source_citation' => 'x'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/leave/legal-rules/$rule/verify")->assertStatus(422);
    }

    public function test_accrual_is_monthly_exact_and_safe_to_repeat(): void
    {
        $this->verify($this->floorRule());
        [, $e] = $this->person('staff');
        $svc = app(LeaveBalanceService::class);
        $this->assertSame(1, $svc->accrueMonth($this->org->id, '2026-01'));
        $this->assertSame(0, $svc->accrueMonth($this->org->id, '2026-01'));                                  // a second run posts nothing
        for ($m = 2; $m <= 12; $m++) {
            $svc->accrueMonth($this->org->id, sprintf('2026-%02d', $m));
        }
        $this->assertSame('18.00', $svc->balance($this->org->id, $e->id, $this->type('annual')));
    }

    public function test_leave_is_scoped_to_people_the_user_may_see(): void
    {
        [$u1, $e1] = $this->person('staff');
        [$u2] = $this->person('staff');
        $this->giveBalance($e1, '5');
        Sanctum::actingAs($u1);
        $id = $this->postJson('/api/v1/leave/requests', $this->body('2026-12-07', '2026-12-07'))->assertCreated()->json('data.id');
        Sanctum::actingAs($u2);
        $this->getJson("/api/v1/leave/requests/$id")->assertNotFound();
        $this->getJson("/api/v1/leave/balances/{$e1->id}/ledger")->assertForbidden();
        $this->postJson("/api/v1/leave/requests/$id/cancel")->assertNotFound();
    }

    public function test_balance_adjustment_needs_hr_and_a_reason(): void
    {
        [$u, $e] = $this->person('staff');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/leave/adjustments', ['employee_id' => $e->id, 'leave_type_id' => $this->type('annual'), 'days' => 5, 'reason' => 'x'])->assertForbidden();
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/leave/adjustments', ['employee_id' => $e->id, 'leave_type_id' => $this->type('annual'), 'days' => 0, 'reason' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/leave/adjustments', ['employee_id' => $e->id, 'leave_type_id' => $this->type('annual'), 'days' => 5, 'reason' => ''])->assertStatus(422);
    }

    public function test_leave_profile_is_audited_encrypted_and_hidden_from_staff(): void
    {
        [$u, $e] = $this->person('staff');
        Sanctum::actingAs($u);
        $this->getJson("/api/v1/leave/profiles/{$e->id}")->assertForbidden();
        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/leave/profiles/{$e->id}", ['date_of_birth' => '1990-05-01', 'sex_id' => Lookup::id('employee_sex', 'female'), 'weekly_hours' => 40])->assertNoContent();
        $this->getJson("/api/v1/leave/profiles/{$e->id}")->assertOk()->assertJsonPath('data.sex', 'female')->assertJsonPath('data.weekly_hours', '40.00');
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.profile_viewed', 'entity_id' => $e->id]);
        $this->assertNotSame('1990-05-01', DB::table('hr_leave_profiles')->where('employee_id', $e->id)->value('date_of_birth'));   // encrypted at rest
    }
}
