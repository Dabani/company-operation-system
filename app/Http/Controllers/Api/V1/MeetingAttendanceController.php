<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingInvitation;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Meetings\AttendanceRules;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\MeetingAttendanceService;
use App\Services\OrganizationContext;
use App\Services\SettingsService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/** The attendance sheet of a meeting: expected list, check-in, corrections, self check-in link, export (Guide 6.5). */
class MeetingAttendanceController extends Controller
{
    public function __construct(private MeetingAccess $access, private MeetingAttendanceService $svc, private OrganizationContext $ctx) {}

    private function sheetViewer(Request $r, Meeting $m): bool { return $this->access->canManage($r->user(), $m) || $this->access->canChair($r->user(), $m); }

    private function loadRow(Request $r, string $id): MeetingAttendance
    {
        $a = MeetingAttendance::with('meeting.status')->findOrFail($id);
        $this->access->find($r->user(), $a->meeting_id);                        // 404 when the meeting is outside the user's visibility
        return $a;
    }

    private function rows(Meeting $m, bool $all, string $userId)
    {
        $q = MeetingAttendance::where('meeting_id', $m->id)->with(['user:id,name', 'status', 'method', 'invitation.response', 'invitation.department']);
        if (!$all) {
            $q->where('user_id', $userId);
        }
        return $q->get()->sortBy(fn ($a) => mb_strtolower($a->displayName()))->values();
    }

    private function roles(Meeting $m, ?string $userId): array
    {
        return array_keys(array_filter(['organizer' => $m->organizer_id === $userId, 'chair' => $m->chair_id === $userId, 'secretary' => $m->secretary_id === $userId, 'facilitator' => $m->facilitator_id === $userId]));
    }

    private function payload(Request $r, Meeting $m): array
    {
        $viewer = $this->sheetViewer($r, $m);
        $rows = $this->rows($m, $viewer, $r->user()->id);
        $status = $m->status->code;
        $mine = MeetingAttendance::where('meeting_id', $m->id)->where('user_id', $r->user()->id)->with('status')->first();
        return [
            'data' => $rows->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->displayName(), 'is_guest' => !$a->user_id, 'walk_in' => !$a->invitation_id, 'roles' => $this->roles($m, $a->user_id),
                'department' => $a->invitation?->department?->name, 'rsvp' => $a->invitation?->response?->code, 'status' => $a->status->code,
                'arrived_at' => $a->arrived_at?->toIso8601String(), 'method' => $a->method?->code, 'note' => $a->note, 'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            ]),
            'meta' => [
                'timezone' => AttendanceService::tz($m->organization_id), 'meeting_status' => $status, 'open' => AttendanceRules::isOpen($status), 'correction' => AttendanceRules::isCorrection($status),
                'summary' => AttendanceRules::summary($rows->map(fn ($a) => $a->status->code)->all()), 'is_sheet_viewer' => $viewer, 'can_record' => $this->access->canManage($r->user(), $m),
                'ack_required' => (bool) SettingsService::get($m->organization_id, 'meeting.attendance_ack'),
                'checkin_token' => $this->access->canManage($r->user(), $m) ? $m->checkin_token : null,
                'me' => $mine ? ['id' => $mine->id, 'status' => $mine->status->code, 'acknowledged' => (bool) $mine->acknowledged_at] : null,
            ],
        ];
    }

    public function index(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        return $this->payload($request, $m);
    }

    public function generate(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->access->requireManage($request->user(), $m);
        abort_unless(AttendanceRules::isOpen($m->status->code), 422, __('meetings.attendance_not_open'));
        $this->svc->generate($m, $request->user());
        return $this->payload($request, $m);
    }

    public function update(Request $request, string $id)
    {
        $a = $this->loadRow($request, $id);
        $this->access->requireManage($request->user(), $a->meeting);
        $d = $request->validate(['status' => ['required', Rule::in(AttendanceRules::STATUSES)], 'note' => 'nullable|string|max:1000', 'reason' => 'nullable|string|max:1000']);
        $this->svc->record($a, $d['status'], $request->user(), $d['note'] ?? null, $d['reason'] ?? null);
        return $this->payload($request, $a->meeting->refresh()->load('status'));
    }

    /** Mark several people at once (for example everyone still "expected" as present). */
    public function bulk(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->access->requireManage($request->user(), $m);
        $d = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'string', 'status' => ['required', Rule::in(AttendanceRules::STATUSES)], 'reason' => 'nullable|string|max:1000']);
        $rows = MeetingAttendance::where('meeting_id', $m->id)->whereIn('id', $d['ids'])->get();
        foreach ($rows as $a) {
            $this->svc->record($a, $d['status'], $request->user(), null, $d['reason'] ?? null);
        }
        return $this->payload($request, $m->refresh()->load('status'));
    }

    public function walkIn(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->access->requireManage($request->user(), $m);
        $org = $this->ctx->organizationId();
        $d = $request->validate([
            'user_id' => ['nullable', 'required_without:guest_name', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'guest_name' => 'nullable|required_without:user_id|string|max:150', 'reason' => 'nullable|string|max:1000',
        ]);
        $this->svc->walkIn($m, $d['user_id'] ?? null, $d['guest_name'] ?? null, $request->user(), $d['reason'] ?? null);
        return $this->payload($request, $m->refresh()->load('status'));
    }

    public function acknowledge(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->svc->acknowledge($m, $request->user());
        return $this->payload($request, $m);
    }

    public function rotateCheckin(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->access->requireManage($request->user(), $m);
        $this->svc->rotateCheckinLink($m, $request->user());
        return $this->payload($request, $m->refresh()->load('status'));
    }

    // ---- self check-in (the link or QR code the secretary shows) ----------------------------------------------------------------
    private function byToken(Request $r, string $token): Meeting
    {
        $m = Meeting::query()->visibleTo($r->user())->with('status')->where('checkin_token', $token)->first();
        abort_unless($m, 404);
        abort_unless($this->access->isParticipant($r->user(), $m), 404);
        return $m;
    }

    public function checkinPreview(Request $request, string $token)
    {
        $m = $this->byToken($request, $token);
        $mine = MeetingAttendance::where('meeting_id', $m->id)->where('user_id', $request->user()->id)->with('status')->first();
        return ['data' => [
            'id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'timezone' => AttendanceService::tz($m->organization_id), 'starts_at' => $m->starts_at->toIso8601String(), 'ends_at' => $m->ends_at->toIso8601String(),
            'status' => $m->status->code, 'can_check_in' => $m->status->code === 'in_progress', 'my_status' => $mine?->status->code,
        ]];
    }

    public function checkin(Request $request, string $token)
    {
        $m = $this->byToken($request, $token);
        $d = $request->validate(['online' => 'nullable|boolean']);
        $a = $this->svc->selfCheckIn($m, $request->user(), (bool) ($d['online'] ?? false));
        return ['data' => ['status' => $a->status()->value('code')]];
    }

    // ---- export ------------------------------------------------------------------------------------------------------------------
    public function export(Request $request, string $id)
    {
        abort_unless($this->ctx->can('data.export'), 403);
        $m = $this->access->find($request->user(), $id, ['status', 'chair', 'secretary']);
        abort_unless($this->sheetViewer($request, $m), 403);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])]])['format'];
        $lang = Language::where('code', app()->getLocale())->value('id');
        $tz = AttendanceService::tz($m->organization_id);
        $rows = $this->rows($m, true, $request->user()->id);
        $label = fn ($lv) => $lv?->label($lang);
        $lines = $rows->map(fn ($a) => [
            'name' => $a->displayName(), 'type' => $a->user_id ? __('meetings.att_member') : __('meetings.att_guest'), 'department' => $a->invitation?->department?->name ?? '',
            'status' => (string) $label($a->status), 'arrived' => $a->arrived_at?->setTimezone($tz)->format('Y-m-d H:i') ?? '',
            'acknowledged' => $a->acknowledged_at ? $a->acknowledged_at->setTimezone($tz)->format('Y-m-d H:i') : '', 'note' => (string) $a->note,
        ]);
        AuditLogger::log('meeting.attendance_exported', $m, [], ['format' => $format]);
        $file = 'attendance-' . $m->code . '-' . now()->format('Ymd-His');
        if ($format === 'pdf') {
            $org = Organization::findOrFail($m->organization_id);
            $html = view('pdf.meeting-attendance', ['m' => $m, 'org' => $org, 'lines' => $lines, 'tz' => $tz, 'locale' => app()->getLocale(), 'summary' => AttendanceRules::summary($rows->map(fn ($a) => $a->status->code)->all())])->render();
            $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $file . '.pdf"']);
        }
        $keys = ['name', 'type', 'department', 'status', 'arrived', 'acknowledged', 'note'];
        return Excel::download(new ListExport($lines->map(fn ($l) => array_values($l))->all(), array_map(fn ($k) => __("meetings.att_col_$k"), $keys)), "$file.$format");
    }
}
