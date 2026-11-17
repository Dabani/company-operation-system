<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MinutesLibraryResource;
use App\Models\Meeting;
use App\Models\MeetingMinuteComment;
use App\Models\MeetingMinuteDistribution;
use App\Models\MeetingMinutes;
use App\Models\MeetingMinuteVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FileService;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\MinutesAccess;
use App\Services\Meetings\MinutesRules;
use App\Services\Meetings\MinutesService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Meeting minutes (Guide 6.6): draft, review, approval by the chair, publication, corrections as new versions, library, controlled PDF. */
class MeetingMinutesController extends Controller
{
    public function __construct(private MinutesService $svc, private MinutesAccess $access, private MeetingAccess $meetings, private OrganizationContext $ctx) {}

    /** @return array{0:MeetingMinuteVersion,1:Meeting} the version and its meeting; 404 when the user may not read it */
    private function load(Request $r, string $id): array
    {
        $v = MeetingMinuteVersion::with(['status', 'visibility', 'minutes'])->findOrFail($id);
        $m = Meeting::with(['status', 'confidentiality'])->findOrFail($v->minutes->meeting_id);
        abort_unless($this->access->canRead($r->user(), $m, $v), 404);
        return [$v, $m];
    }

    private function writer(Request $r, Meeting $m): void { abort_unless($this->access->isWriter($r->user(), $m), 403, 'forbidden'); }

    private function row(MeetingMinuteVersion $v): array
    {
        return ['id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status->code, 'visibility' => $v->visibility?->code, 'correction_reason' => $v->correction_reason,
            'approved_at' => $v->approved_at?->toIso8601String(), 'published_at' => $v->published_at?->toIso8601String(), 'has_pdf' => (bool) $v->file_id];
    }

    // ---- per meeting ------------------------------------------------------------------------------------------------------------
    public function forMeeting(Request $request, string $id)
    {
        $u = $request->user();
        $m = $this->meetings->find($u, $id, ['status']);
        $minutes = MeetingMinutes::where('meeting_id', $m->id)->first();
        $versions = $minutes ? $minutes->versions()->with(['status', 'visibility'])->get()->filter(fn ($v) => $this->access->canRead($u, $m, $v))->values() : collect();
        $writer = $this->access->isWriter($u, $m);
        $latest = $minutes?->versions()->with('status')->orderByDesc('version_no')->first();
        return ['data' => $versions->sortByDesc('version_no')->map(fn ($v) => $this->row($v))->values(), 'meta' => [
            'exists' => (bool) $minutes, 'can_create' => $writer && !$minutes && MinutesRules::canStart($m->status->code),
            'can_revise' => $writer && $latest?->status->code === 'published', 'is_writer' => $writer,
        ]];
    }

    public function store(Request $request, string $id)
    {
        $m = $this->meetings->find($request->user(), $id, ['status', 'confidentiality']);
        $this->writer($request, $m);
        $v = $this->svc->create($m, $request->user());
        return response(['data' => $this->detail($request, $v, $m)], 201);
    }

    public function revise(Request $request, string $id)
    {
        $m = $this->meetings->find($request->user(), $id, ['status', 'confidentiality']);
        $this->writer($request, $m);
        $d = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $v = $this->svc->revise($m, $d['reason'], $request->user());
        return response(['data' => $this->detail($request, $v, $m)], 201);
    }

    // ---- one version ------------------------------------------------------------------------------------------------------------
    private function detail(Request $request, MeetingMinuteVersion $v, Meeting $m): array
    {
        $u = $request->user();
        $v->load(['status', 'visibility', 'approver', 'publisher', 'creator', 'reviewers.user', 'recipients.user', 'recipients.department', 'comments.user', 'history.to', 'history.changedBy']);
        $status = $v->status->code;
        $writer = $this->access->isWriter($u, $m);
        $chair = $this->access->isChair($u, $m);
        $inCircle = $writer || $chair || $this->access->isReviewer($u, $m ? $v : $v);
        return $this->row($v) + [
            'timezone' => AttendanceService::tz($m->organization_id), 'body' => $v->body, 'revision' => $v->revision,
            'meeting' => ['id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'status' => $m->status->code, 'confidential' => $m->isConfidential(), 'starts_at' => $m->starts_at->toIso8601String(), 'can_open' => Meeting::query()->visibleTo($u)->whereKey($m->id)->exists()],
            'approved_by' => $v->approver?->name, 'published_by' => $v->publisher?->name, 'created_by' => $v->creator?->name, 'approval_request_id' => $v->approval_request_id,
            'reviewers' => $inCircle ? $v->reviewers->map(fn ($r) => ['id' => $r->user_id, 'name' => $r->user?->name]) : [],
            'recipients' => ($writer || $chair) ? $v->recipients->map(fn ($r) => ['user_id' => $r->user_id, 'department_id' => $r->department_id, 'name' => $r->user?->name ?? $r->department?->name]) : [],
            'comments' => $inCircle ? $v->comments->map(fn ($c) => ['id' => $c->id, 'user' => $c->user?->name, 'mine' => $c->user_id === $u->id, 'body' => $c->body, 'anchor_text' => $c->anchor_text,
                'created_at' => $c->created_at->toIso8601String(), 'resolved' => (bool) $c->resolved_at]) : [],
            'history' => ($writer || $chair) ? $v->history->map(fn ($h) => ['status' => $h->to->code, 'note' => $h->note, 'by' => $h->changedBy?->name, 'at' => $h->created_at->toIso8601String()]) : [],
            'allowed_visibilities' => MinutesRules::allowedVisibilities($m->isConfidential()),
            'me' => ['is_writer' => $writer, 'is_chair' => $chair, 'can_edit' => $writer && MinutesRules::editable($status), 'can_submit' => $writer && $status === 'draft',
                'can_publish' => $writer && $status === 'approved', 'can_comment' => $inCircle && MinutesRules::commentable($status), 'can_review' => $writer && MinutesRules::editable($status)],
        ];
    }

    public function show(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        return ['data' => $this->detail($request, $v, $m)];
    }

    public function update(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        $this->writer($request, $m);
        $d = $request->validate(['minutes_body' => 'required|string|max:400000', 'revision' => 'required|integer|min:1']);
        $this->svc->saveBody($v, $d['minutes_body'], (int) $d['revision'], $request->user());
        return ['data' => $this->detail($request, $v->refresh()->load('status'), $m)];
    }

    public function reviewers(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        $this->writer($request, $m);
        $d = $request->validate(['user_ids' => 'present|array|max:100', 'user_ids.*' => 'string']);
        $this->svc->setReviewers($v, $m, $d['user_ids'], $request->user());
        return ['data' => $this->detail($request, $v->refresh()->load('status'), $m)];
    }

    public function comment(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        $u = $request->user();
        abort_unless($this->access->isWriter($u, $m) || $this->access->isChair($u, $m) || $this->access->isReviewer($u, $v), 403, 'forbidden');
        $d = $request->validate(['body' => 'required|string|min:1|max:3000', 'anchor_text' => 'nullable|string|max:500']);
        $this->svc->addComment($v, $u, $d['body'], $d['anchor_text'] ?? null);
        return response(['data' => $this->detail($request, $v->refresh()->load('status'), $m)], 201);
    }

    public function resolve(Request $request, string $id)
    {
        $c = MeetingMinuteComment::findOrFail($id);
        [$v, $m] = $this->load($request, $c->version_id);
        abort_unless($this->access->isWriter($request->user(), $m) || $c->user_id === $request->user()->id, 403, 'forbidden');
        $this->svc->resolveComment($c, $request->user());
        return ['data' => $this->detail($request, $v, $m)];
    }

    public function submit(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        $this->writer($request, $m);
        $v = $this->svc->submit($v, $m, $request->user());
        return ['data' => $this->detail($request, $v, $m)];
    }

    public function publish(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        $this->writer($request, $m);
        $d = $request->validate([
            'visibility' => ['required', Rule::in(MinutesRules::VISIBILITIES)],
            'department_ids' => 'nullable|array|max:50', 'department_ids.*' => ['string', Rule::exists('departments', 'id')->where('organization_id', $this->ctx->organizationId())],
            'user_ids' => 'nullable|array|max:200', 'user_ids.*' => ['string', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId())],
        ]);
        $v = $this->svc->publish($v, $m, $d['visibility'], $d['department_ids'] ?? [], $d['user_ids'] ?? [], $request->user());
        return ['data' => $this->detail($request, $v->load('status'), $m->refresh()->load(['status', 'confidentiality']))];
    }

    /** The controlled PDF copy. Reading it follows the minutes' own visibility, not the Files module's rules; every download is audited. */
    public function pdf(Request $request, string $id)
    {
        [$v, $m] = $this->load($request, $id);
        abort_unless(in_array($v->status->code, ['published', 'superseded'], true) && $v->file_id, 404);
        $file = $v->file()->with('currentVersion')->firstOrFail();
        $ver = $file->currentVersion;
        abort_unless($ver && FileService::exists($ver), 410, __('api.file_not_available'));
        MeetingMinuteDistribution::where('version_id', $v->id)->where('user_id', $request->user()->id)->whereNull('downloaded_at')->update(['downloaded_at' => now()]);
        AuditLogger::log('meeting.minutes_downloaded', $v, [], ['version' => $v->version_no]);
        return Storage::disk(FileService::disk())->download($ver->storage_path, 'minutes-' . $m->code . '-v' . $v->version_no . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    // ---- library ----------------------------------------------------------------------------------------------------------------
    public function library(Request $request)
    {
        $u = $request->user();
        $q = $this->access->scopeLibrary(MeetingMinuteVersion::query(), $u)->with(['status', 'visibility', 'minutes.meeting.confidentiality'])->orderByDesc('published_at');
        if (!$request->boolean('all_versions')) {
            $q->whereHas('status', fn ($s) => $s->where('code', 'published'));
        }
        if ($s = trim((string) $request->query('search'))) {
            $q->whereHas('minutes.meeting', fn ($m) => $m->where('title', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
        }
        return MinutesLibraryResource::collection($q->paginate(min(100, max(5, (int) $request->query('per_page', 25)))));
    }
}
