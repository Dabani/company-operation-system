<?php

namespace App\Services\Meetings;

use App\Models\ComplianceObligation;
use App\Models\Department;
use App\Models\FileLink;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingFollowupHistory;
use App\Models\MeetingInvitation;
use App\Models\MeetingResolution;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Notifier;
use App\Services\Projects\TaskService;
use App\Support\Html;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Resolutions and action items (Guide 6.7): numbering, status changes with reasons and evidence, conversion to a task or a compliance duty. */
class FollowUpService
{
    public function __construct(private TaskService $tasks) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("meetings.$key", $p)]]);
    }

    private function st(string $kind, string $code): string { return Lookup::id($kind === 'resolution' ? 'resolution_status' : 'action_status', $code); }
    private function link(Meeting $m): string { return '/meetings/' . $m->id; }

    public static function today(string $orgId): string { return now(AttendanceService::tz($orgId))->toDateString(); }

    private function history(string $kind, string $id, string $org, ?string $from, string $to, ?User $by, ?string $note): void
    {
        MeetingFollowupHistory::create(['organization_id' => $org, 'subject_type' => $kind, 'subject_id' => $id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    /** People who can be given work: the hosts and the invitees of the meeting (also keeps confidential meetings closed). */
    private function requireParticipant(Meeting $m, ?string $userId, string $field): void
    {
        if ($userId !== null && !in_array($userId, array_filter([$m->organizer_id, $m->chair_id, $m->secretary_id, $m->facilitator_id]), true)
            && !MeetingInvitation::where('meeting_id', $m->id)->where('user_id', $userId)->exists()) {
            $this->fail($field, 'followup_only_participants');
        }
    }

    private function requireRecordable(Meeting $m): void
    {
        $m->loadMissing('status');
        if (!FollowUpRules::canRecord($m->status->code)) {
            $this->fail('status', 'followup_meeting_not_started');
        }
    }

    private function nextSeq(string $table, Meeting $m): int
    {
        Meeting::withoutGlobalScopes()->whereKey($m->id)->lockForUpdate()->first();     // numbering is serialized per meeting
        return (int) DB::table($table)->where('meeting_id', $m->id)->max('seq') + 1;
    }

    // ---- resolutions ------------------------------------------------------------------------------------------------------------
    public function createResolution(Meeting $m, array $d, User $by): MeetingResolution
    {
        $this->requireRecordable($m);
        $this->requireParticipant($m, $d['responsible_user_id'] ?? null, 'responsible_user_id');
        return DB::transaction(function () use ($m, $d, $by) {
            $r = MeetingResolution::create([
                'meeting_id' => $m->id, 'agenda_item_id' => $d['agenda_item_id'] ?? null, 'seq' => $this->nextSeq('meeting_resolutions', $m),
                'title' => $d['title'], 'body' => isset($d['body']) ? Html::clean($d['body']) : null,
                'responsible_user_id' => $d['responsible_user_id'] ?? null, 'responsible_department_id' => $d['responsible_department_id'] ?? null,
                'due_on' => $d['due_on'] ?? null, 'status_id' => $this->st('resolution', 'open'), 'created_by' => $by->id,
            ]);
            $this->history('resolution', $r->id, $r->organization_id, null, $r->status_id, $by, null);
            AuditLogger::log('meeting.resolution_created', $r, [], ['code' => $r->code, 'meeting' => $m->code, 'due_on' => $r->due_on?->toDateString()]);
            $this->notifyAssigned($m, $r->responsible_user_id, $r->code, $r->title, $r->due_on?->toDateString(), $by);
            return $r;
        });
    }

    public function updateResolution(MeetingResolution $r, Meeting $m, array $d, User $by): MeetingResolution
    {
        $r->loadMissing('status');
        if (!FollowUpRules::editable($r->status->code)) {
            $this->fail('status', 'followup_not_editable');
        }
        if (array_key_exists('responsible_user_id', $d)) {
            $this->requireParticipant($m, $d['responsible_user_id'], 'responsible_user_id');
        }
        if (isset($d['body'])) {
            $d['body'] = Html::clean($d['body']);
        }
        $old = $r->only(['title', 'responsible_user_id', 'responsible_department_id', 'due_on']);
        $newResp = array_key_exists('responsible_user_id', $d) && $d['responsible_user_id'] !== $r->responsible_user_id ? $d['responsible_user_id'] : null;
        if (array_key_exists('due_on', $d) && ($d['due_on'] ?? null) !== $r->due_on?->toDateString()) {
            $d['due_alerted_on'] = null;
            $d['overdue_alerted_on'] = null;
        }
        $r->update($d);
        AuditLogger::log('meeting.resolution_updated', $r, array_map(fn ($v) => (string) $v, $old), array_map(fn ($v) => (string) $v, $r->only(array_keys($old))));
        $this->notifyAssigned($m, $newResp, $r->code, $r->title, $r->due_on?->toDateString(), $by);
        return $r;
    }

    public function transitionResolution(MeetingResolution $r, Meeting $m, string $to, ?string $note, User $by, bool $runner): MeetingResolution
    {
        $r->loadMissing('status');
        $from = $r->status->code;
        if (!FollowUpRules::canMove('resolution', $from, $to, $r->responsible_user_id === $by->id, $runner)) {
            $this->fail('status', 'followup_bad_move');
        }
        if (FollowUpRules::needsReason('resolution', $from, $to) && mb_strlen(trim((string) $note)) < 3) {
            $this->fail('note', 'followup_reason_required');
        }
        $fromId = $r->status_id;
        $r->update(['status_id' => $this->st('resolution', $to), 'completed_at' => $to === 'completed' ? now() : null]);
        $this->history('resolution', $r->id, $r->organization_id, $fromId, $r->status_id, $by, $note);
        AuditLogger::log('meeting.resolution_status', $r, ['status' => $from], ['status' => $to, 'note' => $note]);
        return $r->refresh();
    }

    public function deleteResolution(MeetingResolution $r): void
    {
        $r->loadMissing('status');
        if ($r->task_id || $r->obligation_id || $r->status->code !== 'open' || $r->actions()->exists()) {
            $this->fail('status', 'followup_cannot_delete');
        }
        AuditLogger::log('meeting.resolution_deleted', $r, ['code' => $r->code], []);
        $r->delete();
    }

    /** Convert a resolution into a task of a project (link back through the resolution and the task text). */
    public function convertToTask(MeetingResolution $r, Meeting $m, Project $p, ?string $assigneeId, User $by): MeetingResolution
    {
        if ($r->task_id || $r->obligation_id) {
            $this->fail('status', 'followup_already_converted');
        }
        $src = '<p>' . e(__('meetings.fu_source', ['code' => $m->code, 'title' => $m->title, 'res' => $r->code])) . '</p>';
        $task = $this->tasks->create($p, [
            'title' => '[' . $r->code . '] ' . $r->title, 'description' => $src . ($r->body ?? ''),
            'assignee_id' => $assigneeId ?? $r->responsible_user_id, 'department_id' => $r->responsible_department_id, 'due_date' => $r->due_on?->toDateString(),
        ], $by);
        $r->update(['task_id' => $task->id]);
        if ($r->status->code === 'open') {
            $this->transitionResolution($r, $m, 'in_progress', null, $by, true);
        }
        AuditLogger::log('meeting.resolution_to_task', $r, [], ['code' => $r->code, 'project' => $p->code, 'task' => $task->number]);
        return $r->refresh();
    }

    /** Convert a resolution into a compliance duty (an obligation that recurs); it keeps the link back to the meeting. */
    public function convertToObligation(MeetingResolution $r, Meeting $m, string $frequencyCode, User $by): MeetingResolution
    {
        if ($r->task_id || $r->obligation_id) {
            $this->fail('status', 'followup_already_converted');
        }
        if (!$r->due_on) {
            $this->fail('due_on', 'followup_due_required');
        }
        $o = ComplianceObligation::create([
            'title' => $r->title, 'description' => '<p>' . e(__('meetings.fu_source', ['code' => $m->code, 'title' => $m->title, 'res' => $r->code])) . '</p>' . ($r->body ?? ''),
            'frequency_id' => Lookup::id('obligation_frequency', $frequencyCode), 'responsible_user_id' => $r->responsible_user_id,
            'link_route' => '/meetings/' . $m->id, 'next_due_on' => $r->due_on->toDateString(), 'is_active' => true,
        ]);
        $r->update(['obligation_id' => $o->id]);
        AuditLogger::log('meeting.resolution_to_obligation', $r, [], ['code' => $r->code, 'obligation' => $o->code]);
        return $r->refresh();
    }

    // ---- action items -----------------------------------------------------------------------------------------------------------
    public function createAction(Meeting $m, array $d, User $by): MeetingAction
    {
        $this->requireRecordable($m);
        $this->requireParticipant($m, $d['owner_id'] ?? null, 'owner_id');
        if (!empty($d['resolution_id']) && !MeetingResolution::where('meeting_id', $m->id)->whereKey($d['resolution_id'])->exists()) {
            $this->fail('resolution_id', 'followup_bad_resolution');
        }
        return DB::transaction(function () use ($m, $d, $by) {
            $a = MeetingAction::create([
                'meeting_id' => $m->id, 'resolution_id' => $d['resolution_id'] ?? null, 'agenda_item_id' => $d['agenda_item_id'] ?? null, 'seq' => $this->nextSeq('meeting_actions', $m),
                'title' => $d['title'], 'description' => isset($d['description']) ? Html::clean($d['description']) : null, 'owner_id' => $d['owner_id'] ?? null,
                'due_on' => $d['due_on'] ?? null, 'status_id' => $this->st('action', 'open'), 'created_by' => $by->id,
            ]);
            $this->history('action', $a->id, $a->organization_id, null, $a->status_id, $by, null);
            AuditLogger::log('meeting.action_created', $a, [], ['code' => $a->code, 'meeting' => $m->code, 'owner' => $a->owner_id, 'due_on' => $a->due_on?->toDateString()]);
            $this->notifyAssigned($m, $a->owner_id, $a->code, $a->title, $a->due_on?->toDateString(), $by);
            return $a;
        });
    }

    public function updateAction(MeetingAction $a, Meeting $m, array $d, User $by): MeetingAction
    {
        $a->loadMissing('status');
        if (!FollowUpRules::editable($a->status->code)) {
            $this->fail('status', 'followup_not_editable');
        }
        if (array_key_exists('owner_id', $d)) {
            $this->requireParticipant($m, $d['owner_id'], 'owner_id');
        }
        if (isset($d['description'])) {
            $d['description'] = Html::clean($d['description']);
        }
        $old = $a->only(['title', 'owner_id', 'due_on']);
        $newOwner = array_key_exists('owner_id', $d) && $d['owner_id'] !== $a->owner_id ? $d['owner_id'] : null;
        if (array_key_exists('due_on', $d) && ($d['due_on'] ?? null) !== $a->due_on?->toDateString()) {
            $d['due_alerted_on'] = null;
            $d['overdue_alerted_on'] = null;
        }
        $a->update($d);
        AuditLogger::log('meeting.action_updated', $a, array_map(fn ($v) => (string) $v, $old), array_map(fn ($v) => (string) $v, $a->only(array_keys($old))));
        $this->notifyAssigned($m, $newOwner, $a->code, $a->title, $a->due_on?->toDateString(), $by);
        return $a;
    }

    public function transitionAction(MeetingAction $a, Meeting $m, string $to, ?string $note, User $by, bool $runner): MeetingAction
    {
        $a->loadMissing('status');
        $from = $a->status->code;
        if (!FollowUpRules::canMove('action', $from, $to, $a->owner_id === $by->id, $runner)) {
            $this->fail('status', 'followup_bad_move');
        }
        if (FollowUpRules::needsReason('action', $from, $to) && mb_strlen(trim((string) $note)) < 3) {
            $this->fail('note', 'followup_reason_required');
        }
        $files = $to === 'done' ? FileLink::where('link_type_id', Lookup::id('file_link_type', 'meeting_action'))->where('link_id', $a->id)->count() : 0;
        if ($to === 'done' && !FollowUpRules::evidenceOk($note, $files)) {
            $this->fail('note', 'followup_evidence_required');
        }
        $fromId = $a->status_id;
        $patch = ['status_id' => $this->st('action', $to)];
        if ($to === 'done') {
            $patch += ['completed_at' => now(), 'completion_note' => $note ? mb_substr(trim($note), 0, 2000) : $a->completion_note];
        } elseif ($to === 'closed') {
            $patch += ['closed_at' => now(), 'closed_by' => $by->id];
        } elseif ($from === 'done') {
            $patch += ['completed_at' => null];
        }
        $a->update($patch);
        $this->history('action', $a->id, $a->organization_id, $fromId, $a->status_id, $by, $note);
        AuditLogger::log('meeting.action_status', $a, ['status' => $from], ['status' => $to, 'note' => $note]);
        if ($to === 'done' && $a->owner_id === $by->id) {
            $this->notifyRunners($m, 'meeting_followup_done', $a->code, $a->title, null);
        }
        return $a->refresh();
    }

    public function deleteAction(MeetingAction $a): void
    {
        $a->loadMissing('status');
        if ($a->status->code !== 'open') {
            $this->fail('status', 'followup_cannot_delete');
        }
        AuditLogger::log('meeting.action_deleted', $a, ['code' => $a->code], []);
        $a->delete();
    }

    // ---- notices ----------------------------------------------------------------------------------------------------------------
    private function notifyAssigned(Meeting $m, ?string $userId, string $ref, string $title, ?string $due, User $by): void
    {
        if (!$userId || $userId === $by->id) {
            return;
        }
        Notifier::send(User::whereKey($userId)->with('language')->get(), 'meeting_followup_assigned', ['title' => $title, 'ref' => $ref, 'meeting' => $m->title, 'due' => $due ?? '-', 'by' => $by->name],
            null, ['in_app', 'email'], $this->link($m), $m->organization_id);
    }

    public function notifyRunners(Meeting $m, string $key, string $ref, string $title, ?string $due): void
    {
        $users = User::whereKey(array_unique(array_filter([$m->secretary_id, $m->organizer_id])))->with('language')->get();
        if ($users->isNotEmpty()) {
            Notifier::send($users, $key, ['title' => $title, 'ref' => $ref, 'meeting' => $m->title, 'due' => $due ?? '-'], null, ['in_app', 'email'], $this->link($m), $m->organization_id);
        }
    }
}
