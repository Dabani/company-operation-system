<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Time\TimeService;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs Bundles 08-09 helpers. */
class TimeTrackingTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void { parent::setUp(); $this->bootCops(); }

    private function projectWithMember($owner, $member): Project
    {
        $p = Project::create(['code' => 'PRJ', 'name' => 'P', 'owner_id' => $owner->id, 'status_id' => Lookup::id('project_status', 'active')]);
        ProjectMember::create(['project_id' => $p->id, 'user_id' => $member->id, 'project_role_id' => Lookup::id('project_role', 'member')]);
        return $p;
    }

    private function entry(string $uid, string $pid, string $start, string $end): array
    {
        return ['project_id' => $pid, 'started_at' => $start, 'ended_at' => $end, 'reason' => 'Forgot to start the timer'];
    }

    public function test_timer_survives_requests_pauses_and_single_timer_is_enforced(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $p = $this->projectWithMember($owner, $dev);
        Sanctum::actingAs($dev);
        $this->postJson('/api/v1/timer/start', ['project_id' => $p->id])->assertCreated()->assertJsonPath('data.state', 'running');
        $this->postJson('/api/v1/timer/start', ['project_id' => $p->id])->assertStatus(422);                        // one running timer
        $this->postJson('/api/v1/timer/pause')->assertOk()->assertJsonPath('data.state', 'paused');
        $this->getJson('/api/v1/timer')->assertOk()->assertJsonPath('data.state', 'paused');                         // persisted on the server
        $this->postJson('/api/v1/timer/resume')->assertOk()->assertJsonPath('data.state', 'running');
        $this->postJson('/api/v1/timer/start', ['project_id' => $p->id, 'stop_existing' => true])->assertCreated();   // prompt accepted
    }

    public function test_viewers_cannot_track_time_and_manual_entries_need_a_reason_and_cannot_overlap(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $viewer = $this->makeUser('staff');
        $p = $this->projectWithMember($owner, $dev);
        ProjectMember::create(['project_id' => $p->id, 'user_id' => $viewer->id, 'project_role_id' => Lookup::id('project_role', 'viewer')]);
        $s = gmdate('Y-m-d H:i:s', time() - 7200); $m = gmdate('Y-m-d H:i:s', time() - 5400); $e = gmdate('Y-m-d H:i:s', time() - 3600);

        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/timer/start', ['project_id' => $p->id])->assertForbidden();

        Sanctum::actingAs($dev);
        $this->postJson('/api/v1/time-entries', ['project_id' => $p->id, 'started_at' => $s, 'ended_at' => $m])->assertStatus(422);                     // no reason
        $this->postJson('/api/v1/time-entries', $this->entry($dev->id, $p->id, $s, $m))->assertCreated();
        $this->postJson('/api/v1/time-entries', $this->entry($dev->id, $p->id, gmdate('Y-m-d H:i:s', time() - 6000), $e))->assertStatus(422);         // overlaps
        $this->postJson('/api/v1/time-entries', $this->entry($dev->id, $p->id, $m, $e))->assertCreated();                                              // touching is fine
    }

    public function test_week_submission_approval_locks_entries_and_adjustments_correct_them(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $manager = $this->makeUser('manager');
        $this->makeUser('admin');
        $p = $this->projectWithMember($owner, $dev);
        $lead = Employee::create(['employee_number' => 'M1', 'first_name' => 'M', 'last_name' => 'M', 'user_id' => $manager->id,
            'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active')]);
        Employee::create(['employee_number' => 'D1', 'first_name' => 'D', 'last_name' => 'D', 'user_id' => $dev->id, 'manager_employee_id' => $lead->id,
            'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active')]);

        Sanctum::actingAs($dev);
        $id = $this->postJson('/api/v1/time-entries', $this->entry($dev->id, $p->id, gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 3600)))->json('data.id');
        $week = $this->getJson('/api/v1/time-week')->assertOk()->json('data.week_start');
        $this->postJson("/api/v1/timesheets/$week/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->patchJson("/api/v1/time-entries/$id", ['description' => 'x'])->assertStatus(422);                       // submitted: frozen
        $this->postJson('/api/v1/time-entries', $this->entry($dev->id, $p->id, gmdate('Y-m-d H:i:s', time() - 3000), gmdate('Y-m-d H:i:s', time() - 2400)))->assertStatus(422);   // week locked

        $req = ApprovalRequest::where('subject_id', Timesheet::where('user_id', $dev->id)->value('id'))->firstOrFail();
        app(ApprovalEngine::class)->act($req->id, $manager, 'approve');
        $this->assertSame('approved', TimeEntry::find($id)->status->code);
        $this->expectException(\LogicException::class);
        TimeEntry::find($id)->update(['duration_seconds' => 1]);                                                         // immutable once approved
    }

    public function test_rejected_timesheet_reopens_entries_and_forgotten_timer_is_auto_stopped(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $this->makeUser('admin');
        $p = $this->projectWithMember($owner, $dev);
        Sanctum::actingAs($dev);
        $id = $this->postJson('/api/v1/time-entries', $this->entry($dev->id, $p->id, gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 3600)))->json('data.id');
        $week = $this->getJson('/api/v1/time-week')->json('data.week_start');
        $this->postJson("/api/v1/timesheets/$week/submit")->assertOk();
        $admin = \App\Models\User::where('email', 'admin@example.com')->firstOrFail();
        $req = ApprovalRequest::where('subject_type_id', Lookup::id('approval_subject_type', 'timesheet'))->firstOrFail();
        app(ApprovalEngine::class)->act($req->id, $admin, 'return', 'Wrong project on Tuesday');
        $this->assertSame('draft', TimeEntry::find($id)->status->code);
        $this->assertNull(TimeEntry::find($id)->timesheet_id);

        $this->postJson('/api/v1/timer/start', ['project_id' => $p->id])->assertCreated();
        \App\Models\TimeEntrySegment::query()->update(['started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 3600)]);
        TimeEntry::whereNull('ended_at')->update(['started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 3600)]);
        $this->assertSame(1, app(TimeService::class)->autoStopDue());
        $this->assertTrue((bool) TimeEntry::where('auto_stopped', true)->exists());
        $this->assertSame(12 * 3600, (int) TimeEntry::where('auto_stopped', true)->value('duration_seconds'));
        $this->postJson("/api/v1/timesheets/$week/submit")->assertStatus(422);                                          // flagged entry must be corrected first
    }
}
