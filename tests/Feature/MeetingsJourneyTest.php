<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Services\FileService;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * COPS Phase 7 acceptance (Guide 6.8 and the roadmap gate): the whole journey from invitation to published minutes and tracked action items,
 * plus the confidentiality rules. Not executed in the build environment (no Laravel runtime): run `php artisan test --filter=MeetingsJourney`.
 * Needs bundles 17 to 20 and the MySQL test database.
 */
class MeetingsJourneyTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Mail::fake();
        Storage::fake(FileService::disk());
    }

    private function createConfidentialMeeting($organizer, array $userIds): string
    {
        Sanctum::actingAs($organizer);
        $start = now()->addDay()->startOfHour();
        $r = $this->postJson('/api/v1/meetings', [
            'title' => 'Board review',
            'type_id' => Lookup::id('meeting_type', 'board'),
            'confidentiality_id' => Lookup::id('meeting_confidentiality', 'confidential'),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'venue' => 'Boardroom',
            'participants' => ['user_ids' => $userIds],
        ]);
        if ($r->status() !== 201) {
            dump($r->json('error'));
        }
        $r->assertCreated();
        return $r->json('data.id');
    }

    public function test_full_cycle_from_invitation_to_published_minutes_and_closed_actions(): void
    {
        $organizer = $this->makeUser('manager');
        $chair = $this->makeUser('team_leader');
        $secretary = $this->makeUser('team_leader');
        $attendee = $this->makeUser('staff');
        $outsider = $this->makeUser('staff');

        $id = $this->createConfidentialMeeting($organizer, [$chair->id, $secretary->id, $attendee->id]);
        $this->patchJson("/api/v1/meetings/$id", ['chair_id' => $chair->id, 'secretary_id' => $secretary->id])->assertOk();

        // The meeting does not exist for people who were not invited.
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/meetings/$id")->assertNotFound();
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonCount(0, 'data');

        // Agenda, scheduling and RSVP.
        Sanctum::actingAs($organizer);
        $this->postJson("/api/v1/meetings/$id/agenda-items", ['title' => 'Budget 2027'])->assertCreated();
        $this->postJson("/api/v1/meetings/$id/schedule")->assertOk();
        Sanctum::actingAs($attendee);
        $this->postJson("/api/v1/meetings/$id/rsvp", ['response' => 'accepted'])->assertOk()->assertJsonPath('data.response', 'accepted');

        // Hold the meeting and record attendance.
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/meetings/$id/start")->assertOk();
        $this->getJson("/api/v1/meetings/$id/attendance")->assertOk();
        $row = MeetingAttendance::where('meeting_id', $id)->where('user_id', $attendee->id)->firstOrFail();
        $this->postJson("/api/v1/meetings/$id/attendance/bulk", ['ids' => [$row->id], 'status' => 'present'])->assertOk();
        $this->postJson("/api/v1/meetings/$id/hold")->assertOk();

        // Draft minutes: only the writers, the chair and reviewers can see them.
        $v = $this->postJson("/api/v1/meetings/$id/minutes")->assertCreated()->json('data.id');
        $body = '<p>' . str_repeat('The board approved the 2027 budget. ', 3) . '</p>';
        $rev = $this->getJson("/api/v1/minutes/$v")->assertOk()->json('data.revision');
        $this->patchJson("/api/v1/minutes/$v", ['minutes_body' => $body, 'revision' => $rev])->assertOk();
        $this->patchJson("/api/v1/minutes/$v", ['minutes_body' => $body . 'x', 'revision' => $rev])->assertStatus(422);   // stale revision
        Sanctum::actingAs($attendee);
        $this->getJson("/api/v1/minutes/$v")->assertNotFound();

        // Review by a participant, then approval by the chair (never by the requester).
        Sanctum::actingAs($secretary);
        $this->putJson("/api/v1/minutes/$v/reviewers", ['user_ids' => [$attendee->id]])->assertOk();
        Sanctum::actingAs($attendee);
        $this->getJson("/api/v1/minutes/$v")->assertOk()->assertJsonPath('data.me.can_comment', true);
        $this->postJson("/api/v1/minutes/$v/comments", ['body' => 'Please add the vote count.'])->assertCreated();
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/minutes/$v/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $request = $this->getJson("/api/v1/minutes/$v")->json('data.approval_request_id');
        $this->assertContains($this->postJson("/api/v1/approvals/$request/act", ['decision' => 'approve'])->status(), [403, 422]);   // the requester can never approve
        Sanctum::actingAs($chair);
        $this->postJson("/api/v1/approvals/$request/act", ['decision' => 'approve'])->assertOk();
        $this->getJson("/api/v1/minutes/$v")->assertOk()->assertJsonPath('data.status', 'approved');

        // Publication: a confidential meeting cannot go to the whole organization; participants can.
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/minutes/$v/publish", ['visibility' => 'organization'])->assertStatus(422);
        $this->postJson("/api/v1/minutes/$v/publish", ['visibility' => 'participants'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame('closed', Meeting::find($id)->status->code);
        $this->getJson("/api/v1/minutes/$v/pdf")->assertOk();

        Sanctum::actingAs($attendee);
        $this->getJson('/api/v1/minutes')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/minutes/$v/pdf")->assertOk();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/minutes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/minutes/$v")->assertNotFound();

        // Action items: the owner finishes with evidence, someone else closes.
        Sanctum::actingAs($secretary);
        $a = $this->postJson("/api/v1/meetings/$id/actions", ['title' => 'Send the budget to the bank', 'owner_id' => $attendee->id, 'due_on' => now()->addWeek()->toDateString()])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/meeting-actions/$a/status", ['to' => 'done'])->assertStatus(422);                           // needs the result
        Sanctum::actingAs($attendee);
        $this->postJson("/api/v1/meeting-actions/$a/status", ['to' => 'done'])->assertStatus(422);                           // needs evidence
        $this->postJson("/api/v1/meeting-actions/$a/status", ['to' => 'done', 'note' => 'Sent on Friday'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->postJson("/api/v1/meeting-actions/$a/status", ['to' => 'closed'])->assertStatus(422);                         // the owner cannot close it
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/meeting-actions/$a/status", ['to' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/v1/meeting-followups/summary')->assertOk()->assertJsonPath('data.action.finished', 1);
    }

    public function test_corrections_create_a_new_version_and_the_old_one_is_superseded(): void
    {
        $organizer = $this->makeUser('manager');
        $chair = $this->makeUser('team_leader');
        $secretary = $this->makeUser('team_leader');
        $id = $this->createConfidentialMeeting($organizer, [$chair->id, $secretary->id]);
        $this->patchJson("/api/v1/meetings/$id", ['chair_id' => $chair->id, 'secretary_id' => $secretary->id])->assertOk();
        $this->postJson("/api/v1/meetings/$id/schedule")->assertOk();
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/meetings/$id/start")->assertOk();
        $v1 = $this->postJson("/api/v1/meetings/$id/minutes")->assertCreated()->json('data.id');
        $rev = $this->getJson("/api/v1/minutes/$v1")->json('data.revision');
        $this->patchJson("/api/v1/minutes/$v1", ['minutes_body' => '<p>' . str_repeat('Decisions were taken. ', 4) . '</p>', 'revision' => $rev])->assertOk();
        $this->postJson("/api/v1/minutes/$v1/submit")->assertOk();
        $request = $this->getJson("/api/v1/minutes/$v1")->json('data.approval_request_id');
        Sanctum::actingAs($chair);
        $this->postJson("/api/v1/approvals/$request/act", ['decision' => 'approve'])->assertOk();
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/minutes/$v1/publish", ['visibility' => 'participants'])->assertOk();

        $this->postJson("/api/v1/meetings/$id/minutes/revise", ['reason' => 'x'])->assertStatus(422);
        $v2 = $this->postJson("/api/v1/meetings/$id/minutes/revise", ['reason' => 'Wrong amount in item 2'])->assertCreated()->json('data.id');
        $this->assertNotSame($v1, $v2);
        $this->getJson("/api/v1/minutes/$v2")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version_no', 2);
        $this->getJson("/api/v1/minutes/$v1")->assertOk()->assertJsonPath('data.status', 'published');                    // stays until the correction is published
    }
}
