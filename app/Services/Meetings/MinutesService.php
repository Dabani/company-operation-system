<?php

namespace App\Services\Meetings;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\Language;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingMinuteComment;
use App\Models\MeetingMinuteDistribution;
use App\Models\MeetingMinuteRecipient;
use App\Models\MeetingMinuteReviewer;
use App\Models\MeetingMinutes;
use App\Models\MeetingMinuteStatusHistory;
use App\Models\MeetingMinuteVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\FileService;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Notifier;
use App\Support\Lookup;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Minutes of a meeting: draft from a template, review with comments, chair approval through the approval engine, publication, corrections as new versions (Guide 6.6). */
class MinutesService
{
    public function __construct(private MinutesAccess $access, private ApprovalEngine $approvals, private MeetingService $meetings) {}

    private function fail(string $field, string $key, array $params = []): never
    {
        throw ValidationException::withMessages([$field => [__("meetings.$key", $params)]]);
    }

    private function sid(string $code): string { return Lookup::id('minutes_status', $code); }

    private function link(MeetingMinuteVersion $v): string { return '/minutes/' . $v->id; }

    private function history(MeetingMinuteVersion $v, ?string $from, string $to, ?User $by, ?string $note = null): void
    {
        MeetingMinuteStatusHistory::create(['version_id' => $v->id, 'from_status_id' => $from, 'to_status_id' => $this->sid($to), 'note' => $note, 'changed_by' => $by?->id, 'created_at' => now()]);
    }

    private function move(MeetingMinuteVersion $v, string $to, ?User $by, array $attrs = [], ?string $note = null): MeetingMinuteVersion
    {
        $from = $v->status->code;
        if (!MinutesRules::allowed($from, $to)) {
            $this->fail('status', 'minutes_bad_state');
        }
        $fromId = $v->status_id;
        $v->update(['status_id' => $this->sid($to)] + $attrs);
        $this->history($v, $fromId, $to, $by, $note);
        AuditLogger::log("meeting.minutes_$to", $v, ['status' => $from], ['status' => $to, 'version' => $v->version_no], $note);
        return $v->refresh()->load('status');
    }

    private function labels(): array
    {
        $keys = ['attendance', 'apologies', 'absent', 'none', 'chair', 'secretary', 'agenda', 'discussion', 'decision', 'resolutions', 'actions', 'closing', 'presenter'];
        return array_combine($keys, array_map(fn ($k) => __("meetings.min_$k"), $keys));
    }

    /** The first version, a draft built from the agenda and the attendance sheet. One minutes record per meeting. */
    public function create(Meeting $m, User $by): MeetingMinuteVersion
    {
        $m->loadMissing(['status', 'room', 'chair', 'secretary', 'agendaItems.presenter']);
        if (!MinutesRules::canStart($m->status->code)) {
            $this->fail('status', 'minutes_meeting_not_started');
        }
        if (MeetingMinutes::where('meeting_id', $m->id)->exists()) {
            $this->fail('status', 'minutes_exists');
        }
        return DB::transaction(function () use ($m, $by) {
            $minutes = MeetingMinutes::create(['meeting_id' => $m->id, 'created_by' => $by->id]);
            $v = MeetingMinuteVersion::create(['minutes_id' => $minutes->id, 'version_no' => 1, 'status_id' => $this->sid('draft'), 'body' => $this->template($m), 'created_by' => $by->id]);
            $this->history($v, null, 'draft', $by);
            AuditLogger::log('meeting.minutes_created', $v, [], ['meeting' => $m->code, 'version' => 1]);
            return $v->load('status');
        });
    }

    private function template(Meeting $m): string
    {
        $tz = AttendanceService::tz($m->organization_id);
        $rows = MeetingAttendance::where('meeting_id', $m->id)->with(['user:id,name', 'status'])->get();
        $names = fn (array $codes) => $rows->filter(fn ($a) => in_array($a->status->code, $codes, true))->map(fn ($a) => $a->displayName())->sort()->values()->all();
        $absent = $rows->filter(fn ($a) => in_array($a->status->code, ['absent', 'expected'], true))->map(fn ($a) => $a->displayName())->sort()->values()->all();
        return MinutesTemplate::build([
            'title' => $m->title, 'code' => $m->code, 'when' => $m->starts_at->setTimezone($tz)->format('Y-m-d H:i') . ' - ' . $m->ends_at->setTimezone($tz)->format('H:i') . ' (' . $tz . ')',
            'where' => (string) ($m->room?->name ?? $m->venue ?? ''), 'chair' => $m->chair?->name, 'secretary' => $m->secretary?->name,
            'present' => $names(['present', 'late', 'online']), 'apologies' => $names(['excused']), 'absent' => $absent,
            'agenda' => $m->agendaItems->where('is_confidential', false)->map(fn ($i) => ['title' => $i->title, 'presenter' => $i->presenter?->name ?? $i->presenter_name, 'expected_decision' => $i->expected_decision])->values()->all(),
        ], $this->labels());
    }

    /** Save the text. The caller sends the revision it started from: if someone saved in between, nothing is overwritten. */
    public function saveBody(MeetingMinuteVersion $v, string $body, int $revision, User $by): MeetingMinuteVersion
    {
        return DB::transaction(function () use ($v, $body, $revision, $by) {
            $v = MeetingMinuteVersion::lockForUpdate()->with('status')->findOrFail($v->id);
            if (!MinutesRules::editable($v->status->code)) {
                $this->fail('body', 'minutes_not_editable');
            }
            if ($v->revision !== $revision) {
                $this->fail('revision', 'minutes_conflict');
            }
            $v->update(['body' => $body, 'revision' => $v->revision + 1]);
            return $v->refresh();
        });
    }

    /** Share the draft with chosen participants (they can read and comment). Only people who are in the meeting can be chosen. */
    public function setReviewers(MeetingMinuteVersion $v, Meeting $m, array $userIds, User $by): void
    {
        if (!MinutesRules::editable($v->status->code)) {
            $this->fail('reviewers', 'minutes_not_editable');
        }
        $allowed = $m->invitations()->whereNotNull('user_id')->pluck('user_id')->merge([$m->organizer_id, $m->chair_id, $m->secretary_id, $m->facilitator_id])->filter()->unique();
        $ids = collect($userIds)->unique()->values();
        if ($ids->diff($allowed)->isNotEmpty()) {
            $this->fail('reviewers', 'minutes_reviewers_only_participants');
        }
        $current = MeetingMinuteReviewer::where('version_id', $v->id)->pluck('user_id');
        MeetingMinuteReviewer::where('version_id', $v->id)->whereNotIn('user_id', $ids)->delete();
        $added = $ids->diff($current);
        foreach ($added as $id) {
            MeetingMinuteReviewer::create(['version_id' => $v->id, 'user_id' => $id, 'added_by' => $by->id, 'created_at' => now()]);
        }
        if ($added->isNotEmpty()) {
            Notifier::send(User::whereKey($added)->with('language')->get(), 'meeting_minutes_review', ['title' => $m->title, 'ref' => $m->code, 'by' => $by->name], null, ['in_app', 'email'], $this->link($v), $m->organization_id);
        }
        AuditLogger::log('meeting.minutes_reviewers_set', $v, [], ['count' => $ids->count()]);
    }

    public function addComment(MeetingMinuteVersion $v, User $by, string $body, ?string $anchor): MeetingMinuteComment
    {
        if (!MinutesRules::commentable($v->status->code)) {
            $this->fail('body', 'minutes_comment_closed');
        }
        return MeetingMinuteComment::create(['version_id' => $v->id, 'user_id' => $by->id, 'body' => $body, 'anchor_text' => $anchor ? mb_substr($anchor, 0, 500) : null, 'created_at' => now()]);
    }

    public function resolveComment(MeetingMinuteComment $c, User $by): MeetingMinuteComment
    {
        if (!$c->resolved_at) {
            $c->update(['resolved_at' => now(), 'resolved_by' => $by->id]);
        }
        return $c->refresh();
    }

    /** The secretary asks the chair to approve. The approval engine chooses the approver (step type "meeting_chair"). */
    public function submit(MeetingMinuteVersion $v, Meeting $m, User $by): MeetingMinuteVersion
    {
        return DB::transaction(function () use ($v, $m, $by) {
            $v = MeetingMinuteVersion::lockForUpdate()->with('status')->findOrFail($v->id);
            if ($v->status->code !== 'draft') {
                $this->fail('status', 'minutes_bad_state');
            }
            if (MinutesRules::plainLength($v->body) < 20) {
                $this->fail('body', 'minutes_empty');
            }
            $req = $this->approvals->submit('meeting_minutes', $v->id, $by, __('meetings.minutes_approval_title', ['title' => $m->title, 'version' => $v->version_no]), ['department_id' => $m->department_id]);
            return $this->move($v, 'pending_approval', $by, ['submitted_at' => now(), 'submitted_by' => $by->id, 'approval_request_id' => $req->id]);
        });
    }

    /** Called by the approval handler. Approved: frozen and ready to publish. Anything else: back to the secretary as a draft, with the chair's comment. */
    public function outcome(string $versionId, string $outcome, ApprovalRequest $req): void
    {
        $v = MeetingMinuteVersion::with(['status', 'minutes.meeting'])->find($versionId);
        if (!$v || $v->status->code !== 'pending_approval') {
            return;
        }
        $last = ApprovalAction::where('request_id', $req->id)->orderByDesc('acted_at')->first();
        $m = $v->minutes->meeting;
        if ($outcome === 'approved') {
            $this->move($v, 'approved', null, ['approved_by' => $last?->actor_id, 'approved_at' => now()]);
            return;
        }
        $this->move($v, 'draft', null, ['approval_request_id' => null], $last?->comment ?: $outcome);
        $secretary = User::whereKey(array_filter([$m->secretary_id, $m->organizer_id]))->with('language')->get();
        Notifier::send($secretary, 'meeting_minutes_returned', ['title' => $m->title, 'ref' => $m->code, 'note' => (string) $last?->comment], null, ['in_app', 'email'], $this->link($v), $m->organization_id);
    }

    /** Publish an approved version to the chosen audience, with a controlled PDF copy and a notification to each recipient. */
    public function publish(MeetingMinuteVersion $v, Meeting $m, string $visibility, array $departmentIds, array $userIds, User $by): MeetingMinuteVersion
    {
        if (!in_array($visibility, MinutesRules::allowedVisibilities($m->isConfidential()), true)) {
            $this->fail('visibility', 'minutes_visibility_not_allowed');
        }
        $kind = MinutesRules::recipientKind($visibility);
        if (($kind === 'department' && !$departmentIds) || ($kind === 'user' && !$userIds)) {
            $this->fail('recipients', 'minutes_need_recipients');
        }
        $published = DB::transaction(function () use ($v, $m, $visibility, $departmentIds, $userIds, $by) {
            $v = MeetingMinuteVersion::lockForUpdate()->with('status')->findOrFail($v->id);
            if ($v->status->code !== 'approved') {
                $this->fail('status', 'minutes_bad_state');
            }
            $v->update(['visibility_id' => Lookup::id('minutes_visibility', $visibility)]);
            MeetingMinuteRecipient::where('version_id', $v->id)->delete();
            foreach (array_unique($visibility === 'department' ? $departmentIds : []) as $d) {
                MeetingMinuteRecipient::create(['version_id' => $v->id, 'department_id' => $d]);
            }
            foreach (array_unique($visibility === 'named' ? $userIds : []) as $u) {
                MeetingMinuteRecipient::create(['version_id' => $v->id, 'user_id' => $u]);
            }
            $v->load(['visibility', 'minutes', 'approver']);
            $asset = $this->storePdf($v, $m, $by);
            $previous = MeetingMinuteVersion::where('minutes_id', $v->minutes_id)->where('id', '!=', $v->id)->whereHas('status', fn ($s) => $s->where('code', 'published'))->get();
            foreach ($previous as $old) {
                $this->move($old->load('status'), 'superseded', $by, [], __('meetings.minutes_superseded_by', ['version' => $v->version_no]));
            }
            $v = $this->move($v, 'published', $by, ['published_at' => now(), 'published_by' => $by->id, 'file_id' => $asset->id]);
            if ($m->status->code === 'held') {
                $this->meetings->close($m, $by);                  // published minutes end the meeting's cycle
            }
            return $v;
        });
        $v = $published->load(['visibility', 'minutes']);
        $people = $this->access->recipients($m, $v);
        foreach ($people->chunk(200) as $chunk) {
            Notifier::send($chunk, 'meeting_minutes_published', ['title' => $m->title, 'ref' => $m->code, 'version' => $v->version_no], null, ['in_app', 'email'], $this->link($v), $m->organization_id);
        }
        foreach ($people as $p) {
            MeetingMinuteDistribution::firstOrCreate(['version_id' => $v->id, 'user_id' => $p->id], ['notified_at' => now()]);
        }
        AuditLogger::log('meeting.minutes_distributed', $v, [], ['visibility' => $visibility, 'recipients' => $people->count()]);
        return $v;
    }

    /** The PDF is a controlled file linked to the version; downloads go through the minutes endpoint, which checks the minutes' own visibility. */
    private function storePdf(MeetingMinuteVersion $v, Meeting $m, User $by)
    {
        $tz = AttendanceService::tz($m->organization_id);
        $locale = app()->getLocale();
        $html = view('pdf.meeting-minutes', ['v' => $v, 'm' => $m, 'org' => Organization::findOrFail($m->organization_id), 'tz' => $tz, 'locale' => $locale])->render();
        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $tmp = tempnam(sys_get_temp_dir(), 'min');
        file_put_contents($tmp, $pdf->output());
        try {
            $file = new UploadedFile($tmp, 'minutes-' . $m->code . '-v' . $v->version_no . '.pdf', 'application/pdf', null, true);
            $class = Lookup::id('file_classification', $m->isConfidential() ? 'confidential' : 'internal');
            return FileService::store($file, $by, ['classification_id' => $class, 'name' => 'Minutes ' . $m->code . ' v' . $v->version_no], ['type' => 'meeting_minutes', 'id' => $v->id]);
        } finally {
            @unlink($tmp);
        }
    }

    /** A correction: a new draft that starts from the latest published wording. The earlier versions stay untouched and readable. */
    public function revise(Meeting $m, string $reason, User $by): MeetingMinuteVersion
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            $this->fail('reason', 'minutes_revise_reason');
        }
        return DB::transaction(function () use ($m, $reason, $by) {
            $minutes = MeetingMinutes::where('meeting_id', $m->id)->lockForUpdate()->first();
            abort_unless($minutes, 404);
            $latest = MeetingMinuteVersion::where('minutes_id', $minutes->id)->with('status')->orderByDesc('version_no')->first();
            if ($latest->status->code !== 'published') {
                $this->fail('status', 'minutes_latest_not_published');
            }
            $v = MeetingMinuteVersion::create(['minutes_id' => $minutes->id, 'version_no' => $latest->version_no + 1, 'status_id' => $this->sid('draft'), 'body' => $latest->body, 'created_by' => $by->id, 'correction_reason' => $reason]);
            $this->history($v, null, 'draft', $by, $reason);
            AuditLogger::log('meeting.minutes_revised', $v, [], ['version' => $v->version_no], $reason);
            return $v->load('status');
        });
    }
}
