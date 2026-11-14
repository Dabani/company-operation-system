<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs Bundle 14 (HrServiceProvider registered). */
class HrEmploymentTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Carbon::setTestNow('2026-11-09 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function dept(string $name): string
    {
        Sanctum::actingAs($this->admin());
        return $this->postJson('/api/v1/departments', ['name' => $name])->assertCreated()->json('data.id');
    }

    /** Creates an employee through the API as an HR manager; returns [hr user, employee id]. */
    private function employee(array $extra = []): array
    {
        $hr = $this->makeUser('hr_manager');
        Sanctum::actingAs($hr);
        $id = $this->postJson('/api/v1/employees', $extra + [
            'first_name' => 'Ada',
            'last_name' => 'Uwase',
            'hired_on' => '2026-01-05',
            'category_id' => Lookup::id('employee_category', 'probation')
        ])->assertCreated()->json('data.id');
        return [$hr, $id];
    }

    private function change(string $id, array $body)
    {
        return $this->postJson("/api/v1/employees/$id/employment-changes", $body);
    }

    private function approve(string $recordId, string $decision = 'approve', ?string $comment = null): void
    {
        $req = ApprovalRequest::findOrFail(EmploymentRecord::findOrFail($recordId)->approval_request_id);
        app(ApprovalEngine::class)->act($req->id, $this->admin(), $decision, $comment);
    }

    public function test_hire_row_is_created_with_the_employee_and_history_is_append_only(): void
    {
        [, $id] = $this->employee();
        $rows = $this->getJson("/api/v1/employees/$id/history")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('hire', $rows[0]['change_type']);
        $this->assertSame('2026-01-05', $rows[0]['effective_from']);

        $rec = EmploymentRecord::where('employee_id', $id)->firstOrFail();
        try {
            $rec->update(['reason' => 'rewritten']);
            $this->fail('a past record must not be editable');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            $rec->delete();
            $this->fail('a record must not be deletable');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_transfer_is_effective_at_once_and_the_old_state_stays_readable(): void
    {
        $a = $this->dept('Finance');
        $b = $this->dept('Operations');
        [, $id] = $this->employee(['department_id' => $a]);
        $this->change($id, ['change_type' => 'transfer', 'effective_from' => '2026-11-09', 'department_id' => $b])->assertCreated()->assertJsonPath('data.status', 'effective');

        $this->assertSame($b, Employee::find($id)->department_id);
        $this->getJson("/api/v1/employees/$id/as-of?date=2026-06-01")->assertOk()->assertJsonPath('data.department_id', $a);
        $this->getJson("/api/v1/employees/$id/as-of?date=2026-11-09")->assertOk()->assertJsonPath('data.department_id', $b);
        $rows = $this->getJson("/api/v1/employees/$id/history")->json('data');
        $this->assertSame('transfer', $rows[0]['change_type']);       // newest first
        $this->assertSame('2026-11-08', $rows[1]['valid_to']);        // the hire row ended the day before
    }

    public function test_promotion_needs_independent_approval_and_applies_only_when_approved(): void
    {
        [$hr, $id] = $this->employee();
        $grade = $this->postJson('/api/v1/hr/grades', ['name' => 'Senior', 'level' => 3])->assertCreated()->json('data.id');
        $pos = $this->postJson('/api/v1/hr/positions', ['title' => 'Senior Analyst', 'grade_id' => $grade])->assertCreated();
        $this->assertStringStartsWith('POS-', $pos->json('data.code'));

        $rec = $this->change($id, ['change_type' => 'promotion', 'effective_from' => '2026-11-09', 'position_id' => $pos->json('data.id'), 'grade_id' => $grade, 'reason' => 'Strong year'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->assertNull(Employee::find($id)->grade_id);             // nothing applied before approval

        $this->change($id, ['change_type' => 'promotion', 'effective_from' => '2026-12-01', 'grade_id' => $grade])->assertStatus(422);   // one pending change at a time
        $this->getJson('/api/v1/employment-changes')->assertOk()->assertJsonCount(1, 'data');

        $this->approve($rec);
        $e = Employee::find($id);
        $this->assertSame($grade, $e->grade_id);
        $this->assertSame('Senior Analyst', $e->position_title);
        $this->assertSame('effective', EmploymentRecord::find($rec)->status->code);
        $this->assertNotNull(EmploymentRecord::find($rec)->applied_at);
    }

    public function test_rejected_change_leaves_the_employee_untouched(): void
    {
        [$hr, $id] = $this->employee();
        $contracted = Lookup::id('employee_category', 'contracted');
        $rec = $this->change($id, ['change_type' => 'confirmation', 'effective_from' => '2026-11-09', 'category_id' => $contracted])->assertCreated()->json('data.id');
        $this->approve($rec, 'reject', 'Probation review not finished');
        $this->assertSame('rejected', EmploymentRecord::find($rec)->status->code);
        $this->assertSame(Lookup::id('employee_category', 'probation'), Employee::find($id)->category_id);
        $this->change($id, ['change_type' => 'confirmation', 'effective_from' => '2026-11-10', 'category_id' => $contracted])->assertCreated();   // a new proposal is possible again
    }

    public function test_future_dated_change_waits_for_its_day(): void
    {
        $a = $this->dept('Finance');
        $b = $this->dept('Operations');
        [, $id] = $this->employee(['department_id' => $a]);
        $this->change($id, ['change_type' => 'transfer', 'effective_from' => '2026-12-01', 'department_id' => $b])->assertCreated();
        $this->assertSame($a, Employee::find($id)->department_id);

        Carbon::setTestNow('2026-12-01 06:00:00');
        $this->artisan('cops:hr-apply-effective')->assertSuccessful();
        $this->assertSame($b, Employee::find($id)->department_id);
        $this->assertNotNull(EmploymentRecord::where('employee_id', $id)->orderByDesc('effective_from')->first()->applied_at);
    }

    public function test_date_order_and_empty_changes_are_refused(): void
    {
        $a = $this->dept('Finance');
        $b = $this->dept('Operations');
        [, $id] = $this->employee(['department_id' => $a]);
        $this->change($id, ['change_type' => 'transfer', 'effective_from' => '2026-01-05', 'department_id' => $b])->assertStatus(422);   // not after the hire row
        $this->change($id, ['change_type' => 'transfer', 'effective_from' => '2026-11-09', 'department_id' => $a])->assertStatus(422);                        // same department: nothing changes
    }

    public function test_tracked_fields_cannot_be_edited_directly(): void
    {
        $a = $this->dept('Finance');
        $b = $this->dept('Operations');
        [$hr, $id] = $this->employee(['department_id' => $a]);
        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/employees/$id", ['department_id' => $b])->assertStatus(422);
        $this->assertSame($a, Employee::find($id)->department_id);                       // nothing was changed
        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/employees/$id", ['last_name' => 'Mukamana', 'department_id' => $a])->assertOk();       // an unchanged value is fine
        $this->assertSame('Mukamana', Employee::find($id)->last_name);
    }

    public function test_salary_is_hidden_without_its_permission_and_reads_are_audited(): void
    {
        [$hr, $id] = $this->employee();
        $rec = $this->change($id, ['change_type' => 'salary_change', 'effective_from' => '2026-11-09', 'salary_amount' => '500000', 'salary_currency' => 'RWF'])->assertCreated()->json('data.id');
        $this->approve($rec);

        Sanctum::actingAs($hr);
        $rows = $this->getJson("/api/v1/employees/$id/history")->assertOk()->json('data');
        $this->assertSame('500000.00', $rows[0]['salary_amount']);
        $this->assertTrue(AuditLog::where('action', 'employment.salary_viewed')->exists());

        $role = Role::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'hr_viewer', 'name' => 'HR viewer', 'level' => 10, 'is_system' => false, 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('code', ['hr.history.view', 'employee.view', 'scope.organization'])->pluck('id')->all());
        Sanctum::actingAs($this->makeUser('hr_viewer'));
        $row = $this->getJson("/api/v1/employees/$id/history")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('salary_amount', $row);
        $this->change($id, ['change_type' => 'salary_change', 'effective_from' => '2026-12-01', 'salary_amount' => '900000', 'salary_currency' => 'RWF'])->assertForbidden();
    }

    public function test_career_progression_reports_months_in_grade_and_missing_rating(): void
    {
        [$hr, $id] = $this->employee();
        $g1 = $this->postJson('/api/v1/hr/grades', ['name' => 'Officer', 'level' => 1])->json('data.id');
        $g2 = $this->postJson('/api/v1/hr/grades', ['name' => 'Senior officer', 'level' => 2])->json('data.id');
        $this->postJson('/api/v1/hr/progression-criteria', ['from_grade_id' => $g2, 'to_grade_id' => $g1, 'min_months_in_grade' => 1])->assertStatus(422);   // must go up
        $this->postJson('/api/v1/hr/progression-criteria', ['from_grade_id' => $g1, 'to_grade_id' => $g2, 'min_months_in_grade' => 6, 'min_rating_level' => 3])->assertCreated();

        $rec = $this->change($id, ['change_type' => 'promotion', 'effective_from' => '2026-01-06', 'grade_id' => $g1])->assertCreated()->json('data.id');
        $this->approve($rec);

        Sanctum::actingAs($hr);
        $c = $this->getJson("/api/v1/employees/$id/career")->assertOk()->json('data');
        $this->assertSame(10, $c['months_in_grade']);
        $this->assertFalse($c['next_grades'][0]['eligible']);
        $this->assertSame(['rating'], $c['next_grades'][0]['missing']);
    }

    public function test_review_flow_reviewer_writes_employee_acknowledges_and_others_see_nothing(): void
    {
        $leader = $this->makeUser('team_leader');
        $staff = $this->makeUser('staff');
        $other = $this->makeUser('staff');
        $mgr = Employee::create(['first_name' => 'Lead', 'last_name' => 'Er', 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $leader->id]);
        $sub = Employee::create([
            'first_name' => 'Sub',
            'last_name' => 'Ject',
            'category_id' => Lookup::id('employee_category', 'probation'),
            'status_id' => Lookup::id('employee_status', 'active'),
            'user_id' => $staff->id,
            'manager_employee_id' => $mgr->id
        ]);
        Employee::create(['first_name' => 'Out', 'last_name' => 'Sider', 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $other->id]);

        $hr = $this->makeUser('hr_manager');
        Sanctum::actingAs($hr);
        $cycle = $this->postJson('/api/v1/review-cycles', ['name' => '2026 H2', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/review-cycles/$cycle/reviews", ['employee_ids' => [$sub->id]])->assertCreated()->assertJsonPath('data.created', 1);
        $this->postJson("/api/v1/review-cycles/$cycle/reviews", ['employee_ids' => [$sub->id]])->assertCreated()->assertJsonPath('data.created', 0);   // idempotent
        $rid = $this->getJson('/api/v1/reviews')->assertOk()->json('data.0.id');

        Sanctum::actingAs($staff);
        $this->getJson("/api/v1/reviews/$rid")->assertNotFound();                                                    // a draft is not visible to the person reviewed
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/reviews/$rid")->assertNotFound();
        $this->patchJson("/api/v1/reviews/$rid", ['rating' => 'outstanding'])->assertForbidden();                   // no hr.reviews.write

        Sanctum::actingAs($leader);
        $this->postJson("/api/v1/reviews/$rid/submit")->assertStatus(422);                                           // a rating is required
        $this->patchJson("/api/v1/reviews/$rid", ['rating' => 'meets_expectations', 'recommendation' => 'confirm', 'summary' => 'Solid first half'])->assertOk();
        $this->postJson("/api/v1/reviews/$rid/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.rating_level', 3);
        $this->patchJson("/api/v1/reviews/$rid", ['summary' => 'edited after submit'])->assertStatus(422);

        Sanctum::actingAs($staff);
        $this->getJson("/api/v1/reviews/$rid")->assertOk()->assertJsonPath('data.recommendation', 'confirm');
        $this->postJson("/api/v1/reviews/$rid/acknowledge", ['employee_comment' => 'Thank you'])->assertOk()->assertJsonPath('data.status', 'acknowledged');
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/reviews/$rid")->assertNotFound();

        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/reviews/$rid")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'review.viewed')->exists());                                     // HR reads are audited
    }

    public function test_reminders_are_sent_once_per_step_and_catch_up_later(): void
    {
        $hr = $this->makeUser('hr_manager');
        Employee::create([
            'first_name' => 'Pro',
            'last_name' => 'Bation',
            'category_id' => Lookup::id('employee_category', 'probation'),
            'status_id' => Lookup::id('employee_status', 'active'),
            'probation_end_on' => '2026-11-19'
        ]);                                                                    // 10 days away -> the 14-day step

        $this->artisan('cops:hr-reminders')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $hr->id)->count());
        $this->artisan('cops:hr-reminders')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $hr->id)->count());                                   // no duplicate

        Carbon::setTestNow('2026-11-13 09:00:00');                                                                   // 6 days away -> the 7-day step
        $this->artisan('cops:hr-reminders')->assertSuccessful();
        $this->assertSame(2, AppNotification::where('user_id', $hr->id)->count());
    }

    public function test_employee_sees_only_their_own_record_without_salary(): void
    {
        [$hr, $id] = $this->employee();
        $staff = $this->makeUser('staff');
        Employee::find($id)->update(['user_id' => $staff->id]);
        $rec = $this->change($id, ['change_type' => 'salary_change', 'effective_from' => '2026-11-09', 'salary_amount' => '300000', 'salary_currency' => 'RWF'])->json('data.id');
        $this->approve($rec);

        Sanctum::actingAs($staff);
        $r = $this->getJson('/api/v1/my/employment')->assertOk()->assertJsonPath('data.employee.id', $id)->json('data');
        $this->assertArrayNotHasKey('salary_amount', $r['history'][0]);
        Sanctum::actingAs($this->makeUser('staff'));
        $this->getJson('/api/v1/my/employment')->assertNotFound();
        $this->getJson("/api/v1/employees/$id/history")->assertForbidden();
    }
}
