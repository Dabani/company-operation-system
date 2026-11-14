<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Hr\Attendance\AttendanceCalculator;
use App\Services\Hr\Attendance\AttendanceService;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs Bundle 16. Times are built in the organization's own timezone, whatever it is. */
class AttendanceTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private string $tz;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        AttendanceService::forgetTimezones();
        $this->tz = AttendanceService::tz($this->org->id);
        $this->hr = $this->makeUser('hr_manager');
        $this->at('2026-11-09', '06:00');                                                                      // a fixed clock for every test (a Monday, before work starts)
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Freeze the clock at a local date and time of the organization. */
    private function at(string $date, string $time): void
    {
        Carbon::setTestNow(CarbonImmutable::createFromTimestampUTC(AttendanceCalculator::localToUtc($this->tz, $date, $time)));
    }

    private function admin(): User { return User::where('email', 'admin@example.com')->firstOrFail(); }

    private function person(string $role = 'staff', array $extra = []): array
    {
        $u = $this->makeUser($role);
        $e = Employee::create($extra + ['first_name' => ucfirst($role), 'last_name' => 'Tester', 'category_id' => Lookup::id('employee_category', 'contracted'),
            'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $u->id, 'hired_on' => '2026-01-05']);
        return [$u, $e];
    }

    /** A Monday-to-Friday 08:00-17:00 schedule (60 minute unpaid break, 10 minute grace) assigned from 1 November to the given employees. Returns the shift id. */
    private function workweek(array $employees): string
    {
        Sanctum::actingAs($this->hr);
        $shift = $this->postJson('/api/v1/attendance/shifts', ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'grace_minutes' => 10])->assertCreated()->assertJsonPath('data.code', 'SHF-0001')->json('data.id');
        $sched = $this->postJson('/api/v1/attendance/schedules', ['name' => 'Office week', 'days' => array_map(fn ($d) => ['weekday' => $d, 'shift_id' => $shift], [1, 2, 3, 4, 5])])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/attendance/schedule-assignments', ['schedule_id' => $sched, 'effective_from' => '2026-11-01', 'employee_ids' => array_map(fn ($e) => $e->id, $employees)])->assertCreated();
        return $shift;
    }

    private function day(Employee $e, string $date): ?AttendanceDay
    {
        return AttendanceDay::where('employee_id', $e->id)->whereDate('work_date', $date)->first();
    }

    private function csv(string $content): UploadedFile { return UploadedFile::fake()->createWithContent('punches.csv', $content); }

    public function test_clocking_in_and_out_with_the_server_clock(): void
    {
        [$staff, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-11-09', '08:03');                                                                    // a Monday
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/me/today')->assertOk()->assertJsonPath('data.state', 'out')->assertJsonPath('data.shift.name', 'Day')->assertJsonPath('data.ip_restricted', false);
        $this->postJson('/api/v1/attendance/me/clock')->assertCreated()->assertJsonPath('data.state', 'in');
        $this->postJson('/api/v1/attendance/me/clock', ['direction' => 'in'])->assertStatus(422);            // already in
        $this->postJson('/api/v1/attendance/me/clock')->assertStatus(422);                                    // the same instant: too soon

        $this->at('2026-11-09', '17:00');
        $this->postJson('/api/v1/attendance/me/clock')->assertCreated()->assertJsonPath('data.state', 'out');
        $days = $this->getJson('/api/v1/attendance/me/days?month=2026-11')->assertOk()->json('data.days');
        $d = collect($days)->firstWhere('work_date', '2026-11-09');                                           // earlier weekdays of the assignment are absences
        $this->assertNotNull($d);
        $this->assertSame('present', $d['status']);                                                           // 3 minutes after the start is inside the 10 minute grace
        $this->assertSame(537, $d['worked_minutes']);
        $this->assertSame(0, $d['late_minutes']);
        $this->assertSame(57, $d['overtime_minutes']);                                                        // 537 worked against 480 expected
        $this->assertSame(2, AttendanceEvent::where('employee_id', $e->id)->count());
    }

    public function test_lateness_beyond_the_grace_period(): void
    {
        [$staff, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-11-10', '08:25');
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/attendance/me/clock')->assertCreated();
        $this->at('2026-11-10', '17:05');
        $this->postJson('/api/v1/attendance/me/clock')->assertCreated();
        $d = $this->day($e, '2026-11-10');
        $this->assertSame('late', $d->status->code);
        $this->assertSame(25, $d->late_minutes);
    }

    public function test_absences_holidays_and_days_off_are_detected_by_the_daily_job(): void
    {
        [, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-11-11', '10:00');                                                                      // Wednesday: Monday 9 and Tuesday 10 are over
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/holidays', ['holiday_date' => '2026-11-10', 'name' => 'Test holiday'])->assertCreated();
        $this->artisan('cops:hr-attendance-close')->assertSuccessful();
        $this->assertSame('absent', $this->day($e, '2026-11-09')->status->code);
        $this->assertSame('holiday', $this->day($e, '2026-11-10')->status->code);
        $this->assertNull($this->day($e, '2026-11-11'));                                                       // today: nothing is decided yet
        $this->assertNull($this->day($e, '2026-11-08'));                                                       // Sunday: not scheduled, nothing recorded
        $this->artisan('cops:hr-attendance-close')->assertSuccessful();
        $this->assertSame(1, AttendanceDay::where('employee_id', $e->id)->whereDate('work_date', '2026-11-09')->count());   // idempotent
    }

    public function test_a_correction_needs_approval_and_then_replaces_the_day(): void
    {
        [$mgrUser, $mgr] = $this->person('manager');
        [$staff, $e] = $this->person('staff', ['manager_employee_id' => $mgr->id]);
        $this->workweek([$e]);
        $this->at('2026-11-11', '10:00');
        $this->artisan('cops:hr-attendance-close')->assertSuccessful();
        $this->assertSame('absent', $this->day($e, '2026-11-09')->status->code);

        Sanctum::actingAs($staff);
        $body = ['work_date' => '2026-11-09', 'in_at' => '2026-11-09 08:00', 'out_at' => '2026-11-09 17:00', 'reason' => 'Forgot to clock: field visit'];
        $c = $this->postJson('/api/v1/attendance/corrections', $body)->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data');
        $this->postJson('/api/v1/attendance/corrections', $body)->assertStatus(422);                           // one pending correction per day
        $this->postJson('/api/v1/attendance/corrections', ['employee_id' => $mgr->id] + $body)->assertForbidden();   // not for somebody else
        $this->assertSame('absent', $this->day($e, '2026-11-09')->status->code);                               // nothing changes before approval

        app(ApprovalEngine::class)->act($c['approval_request_id'], $mgrUser, 'approve');
        $d = $this->day($e, '2026-11-09');
        $this->assertSame('present', $d->status->code);
        $this->assertSame(540, $d->worked_minutes);
        $this->assertNotNull($d->correction_id);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/corrections?status=approved')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_validated_month_is_frozen_until_it_is_reopened(): void
    {
        [, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-12-05', '10:00');
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/periods/2026-12/validate')->assertStatus(422);                    // the month is not over
        // one clock-in without a clock-out on Monday 9 November: an incomplete day
        $this->post('/api/v1/attendance/import', ['file' => $this->csv("employee_number,timestamp,direction\n{$e->employee_number},2026-11-09 08:00,in\n")], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.rows_imported', 1);
        $this->assertSame('incomplete', $this->day($e, '2026-11-09')->status->code);
        $this->postJson('/api/v1/attendance/periods/2026-11/validate')->assertStatus(422);                    // an incomplete day blocks validation

        $c = $this->postJson('/api/v1/attendance/corrections', ['employee_id' => $e->id, 'work_date' => '2026-11-09', 'in_at' => '2026-11-09 08:00', 'out_at' => '2026-11-09 17:00', 'reason' => 'Forgot to clock out'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/attendance/periods/2026-11/validate')->assertStatus(422);                    // a correction is still waiting
        app(ApprovalEngine::class)->act($c['approval_request_id'], $this->admin(), 'approve');
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/periods/2026-11/validate')->assertCreated();
        $this->assertTrue(AuditLog::where('action', 'attendance.month_validated')->exists());
        $this->getJson('/api/v1/attendance/periods?year=2026')->assertOk()->assertJsonPath('data.10.validated', true);

        $this->postJson('/api/v1/attendance/corrections', ['employee_id' => $e->id, 'work_date' => '2026-11-10', 'in_at' => '2026-11-10 08:00', 'out_at' => '2026-11-10 17:00', 'reason' => 'Late request'])->assertStatus(422);
        $this->post('/api/v1/attendance/import', ['file' => $this->csv("employee_number,timestamp,direction\n{$e->employee_number},2026-11-12 08:00,in\n")], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.rows_rejected', 1);
        $this->postJson('/api/v1/attendance/periods/2026-11/reopen', ['reason' => 'Payroll found a mistake'])->assertForbidden();   // HR cannot reopen on its own

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/attendance/periods/2026-11/reopen', ['reason' => 'Payroll found a mistake'])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'attendance.month_reopened')->exists());
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/corrections', ['employee_id' => $e->id, 'work_date' => '2026-11-10', 'in_at' => '2026-11-10 08:00', 'out_at' => '2026-11-10 17:00', 'reason' => 'Now allowed'])->assertCreated();
    }

    public function test_import_reads_a_csv_skips_duplicates_and_reports_rejected_lines(): void
    {
        [, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-11-11', '10:00');
        Sanctum::actingAs($this->hr);
        $n = $e->employee_number;
        $file = "employee_number,timestamp,direction\n$n,2026-11-09 08:00,in\n$n,2026-11-09 17:00,out\nNOBODY,2026-11-09 08:00,in\n$n,2026-11-09 08:00,in\n$n,not a date,in\n$n,2026-12-31 08:00,in\n";
        $r = $this->post('/api/v1/attendance/import', ['file' => $this->csv($file)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame(6, $r['rows_total']);
        $this->assertSame(2, $r['rows_imported']);
        $this->assertSame(1, $r['rows_skipped']);                                                              // the same punch twice
        $this->assertSame(3, $r['rows_rejected']);                                                             // unknown employee, unreadable date, future date
        $this->assertStringContainsString('line 4', $r['errors']);
        $d = $this->day($e, '2026-11-09');
        $this->assertSame(540, $d->worked_minutes);
        $this->assertSame('present', $d->status->code);

        $again = $this->post('/api/v1/attendance/import', ['file' => $this->csv($file)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame(0, $again['rows_imported']);                                                         // safe to import the same file twice
        $this->assertSame(2, AttendanceEvent::where('employee_id', $e->id)->count());
    }

    public function test_import_without_a_direction_column_alternates_in_and_out(): void
    {
        [, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-11-11', '10:00');
        Sanctum::actingAs($this->hr);
        $csv = "Badge;Time\n{$e->employee_number};2026-11-10 08:00\n{$e->employee_number};2026-11-10 12:00\n{$e->employee_number};2026-11-10 13:00\n{$e->employee_number};2026-11-10 17:00\n";
        $this->post('/api/v1/attendance/import', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.rows_imported', 4);
        $this->assertSame(['in', 'out', 'in', 'out'], AttendanceEvent::where('employee_id', $e->id)->orderBy('occurred_at')->pluck('direction')->all());
        $this->assertSame(480, $this->day($e, '2026-11-10')->worked_minutes);                                  // the lunch break was punched
        $this->post('/api/v1/attendance/import', ['file' => $this->csv("foo,bar\n1,2\n")], ['Accept' => 'application/json'])->assertStatus(422);   // columns not found
    }

    public function test_the_optional_network_restriction(): void
    {
        [$staff, $e] = $this->person();
        $this->workweek([$e]);
        $this->at('2026-11-09', '08:00');
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/networks', ['label' => 'Head office', 'cidr' => 'not a range'])->assertStatus(422);
        $this->postJson('/api/v1/attendance/networks', ['label' => 'Head office', 'cidr' => '10.0.0.0/24'])->assertCreated();

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/me/today')->assertJsonPath('data.ip_restricted', true);              // the screen discloses that the address is checked and kept
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/attendance/me/clock')->assertStatus(422);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/api/v1/attendance/me/clock')->assertCreated();
        $this->assertSame('10.0.0.5', AttendanceEvent::where('employee_id', $e->id)->first()->ip_address);
    }

    public function test_who_can_see_and_set_up_what(): void
    {
        [$staff, $e] = $this->person();
        $this->workweek([$e]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/days?month=2026-11')->assertForbidden();                              // staff see only themselves
        $this->getJson('/api/v1/attendance/summary?month=2026-11')->assertForbidden();
        $this->postJson('/api/v1/attendance/shifts', ['name' => 'X', 'start_time' => '08:00', 'end_time' => '16:00'])->assertForbidden();
        $this->getJson('/api/v1/attendance/holidays')->assertOk();                                                // the calendar is for everyone
        $this->postJson('/api/v1/attendance/periods/2026-10/validate')->assertForbidden();

        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/shifts', ['name' => 'Same', 'start_time' => '08:00', 'end_time' => '08:00'])->assertStatus(422);   // start and end must differ
        $rows = $this->getJson('/api/v1/attendance/summary?month=2026-11')->assertOk()->json('data.rows');
        $this->assertNotEmpty($rows);
        $this->assertTrue(AuditLog::where('action', 'attendance.schedule_assigned')->exists());
    }
}
