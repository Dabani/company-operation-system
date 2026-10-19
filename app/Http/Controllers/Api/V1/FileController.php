<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\AuditLog;
use App\Models\FileAsset;
use App\Models\FileComment;
use App\Models\FileDeletionRequest;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\SupportCase;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FileController extends Controller
{
    use AppliesListQuery;

    private const PREVIEW_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    public function __construct(private OrganizationContext $ctx) {}

    // ---- helpers ------------------------------------------------------------------------------

    private function with(): array
    {
        return ['classification.translations', 'status.translations', 'owner'];
    }

    /** Visible-or-404: a file you may not see does not reveal that it exists. */
    private function find(string $id): FileAsset
    {
        return FileAccess::scopeVisible(FileAsset::query())->with($this->with())->findOrFail($id);
    }

    private function classificationRule()
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'file_classification')->pluck('id')));
    }

    private function hiddenLinkTypeIds(): array
    {
        return [Lookup::id('file_link_type', 'user_avatar'), Lookup::id('file_link_type', 'org_logo')];
    }

    // ---- library ------------------------------------------------------------------------------

    public function index(Request $request)
    {
        $q = FileAccess::scopeVisible(FileAsset::query())->with($this->with())
            ->whereDoesntHave('links', fn ($l) => $l->whereIn('link_type_id', $this->hiddenLinkTypeIds()));

        if (empty(data_get($request->query('filter'), 'status_id'))) {
            $q->whereHas('status', fn ($s) => $s->where('code', 'active'));         // archived files only when asked for
        }
        if ($request->boolean('mine')) {
            $q->where('file_assets.owner_id', $request->user()->id);
        }

        return $this->respondList($request, $q, FileResource::class, ['name', 'owner.name'], ['created_at', 'name', 'size_bytes', 'updated_at'],
            ['classification_id', 'status_id', 'extension', 'owner_id'],
            ['name', 'extension', 'size_bytes', 'classification_label', 'status_label', 'owner_name', 'created_at']);
    }

    public function show(string $id)
    {
        $f = $this->find($id);
        $f->load(['versions.uploader', 'links.type']);
        $pending = $f->deletionRequests()->whereHas('status', fn ($s) => $s->where('code', 'pending'))->with(['requester', 'comment'])->first();

        return response()->json(['data' => array_merge((new FileResource($f))->resolve(request()), [
            'versions' => $f->versions->map(fn ($v) => [
                'id' => $v->id, 'number' => $v->version_number, 'name' => $v->original_name, 'size_bytes' => (int) $v->size_bytes, 'mime_type' => $v->mime_type,
                'uploaded_by' => $v->uploader?->name, 'created_at' => $v->created_at->toIso8601String(), 'sha256' => $v->sha256, 'available' => $v->purged_at === null,
            ])->values(),
            'links' => $f->links->map(fn ($l) => ['type' => $l->type->code, 'id' => $l->link_id])->values(),
            'pending_deletion' => $pending ? ['id' => $pending->id, 'requested_by' => $pending->requester?->name, 'reason' => $pending->comment?->body, 'created_at' => $pending->created_at->toIso8601String()] : null,
        ])]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'file' => 'required|file',
            'classification_id' => ['nullable', $this->classificationRule()],
            'name' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:20000',
        ]);
        $asset = FileService::store($request->file('file'), $request->user(), $d);
        $asset->load($this->with());
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'classification' => $asset->classification->code, 'size' => $asset->size_bytes, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $f = $this->find($id);
        abort_unless(FileAccess::canManage($f), 403);
        $d = $request->validate([
            'name' => 'sometimes|required|string|max:200',
            'description' => 'sometimes|nullable|string|max:20000',
            'classification_id' => ['sometimes', $this->classificationRule()],
        ]);
        $old = $f->only(array_keys($d));
        $f->update($d);
        AuditLogger::log('file.updated', $f, $old, $d);
        return new FileResource($f->refresh()->load($this->with()));
    }

    // ---- content ------------------------------------------------------------------------------

    private function versionOf(FileAsset $f, ?string $versionId)
    {
        $v = $versionId ? $f->versions()->findOrFail($versionId) : $f->currentVersion()->firstOrFail();
        abort_unless(FileService::exists($v), 410, __('api.file_not_available'));
        return $v;
    }

    public function download(Request $request, string $id)
    {
        $f = $this->find($id);
        $v = $this->versionOf($f, $request->query('version'));
        AuditLogger::log('file.downloaded', $f, [], ['version' => (string) $v->version_number]);

        return Storage::disk(FileService::disk())->download($v->storage_path, $v->original_name, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Inline view for images and PDFs only; everything else must be downloaded. */
    public function preview(Request $request, string $id)
    {
        $f = $this->find($id);
        $v = $this->versionOf($f, $request->query('version'));
        abort_unless(in_array($v->mime_type, self::PREVIEW_MIME, true), 415, __('api.file_preview_unsupported'));
        AuditLogger::log('file.previewed', $f, [], ['version' => (string) $v->version_number]);

        return Storage::disk(FileService::disk())->response($v->storage_path, $v->original_name, [
            'Content-Type' => $v->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function addVersion(Request $request, string $id)
    {
        $f = $this->find($id);
        abort_unless(FileAccess::abilities($f)['replace'], 403);
        $request->validate(['file' => 'required|file']);
        $v = FileService::addVersion($f, $request->file('file'), $request->user());
        AuditLogger::log('file.replaced', $f, [], ['version' => (string) $v->version_number, 'sha256' => $v->sha256]);
        return new FileResource($f->refresh()->load($this->with()));
    }

    // ---- lifecycle ----------------------------------------------------------------------------

    public function archive(string $id)
    {
        $f = $this->find($id);
        abort_unless(FileAccess::abilities($f)['archive'], 403);
        $f->update(['status_id' => Lookup::id('file_status', 'archived'), 'archived_at' => now()]);
        AuditLogger::log('file.archived', $f);
        return new FileResource($f->refresh()->load($this->with()));
    }

    public function restore(string $id)
    {
        $f = $this->find($id);
        abort_unless(FileAccess::abilities($f)['restore'], 403);
        $f->update(['status_id' => Lookup::id('file_status', 'active'), 'archived_at' => null]);
        AuditLogger::log('file.restored', $f);
        return new FileResource($f->refresh()->load($this->with()));
    }

    public function legalHold(Request $request, string $id)
    {
        $f = $this->find($id);
        $d = $request->validate(['on' => 'required|boolean']);
        $f->update(['legal_hold' => $d['on']]);
        AuditLogger::log($d['on'] ? 'file.legal_hold_set' : 'file.legal_hold_released', $f);
        return new FileResource($f->refresh()->load($this->with()));
    }

    // ---- comments & activity ------------------------------------------------------------------

    public function comments(string $id)
    {
        $f = $this->find($id);
        $c = $f->comments()->with(['user', 'kind'])->orderBy('created_at')->get();
        return response()->json(['data' => $c->map(fn ($x) => [
            'id' => $x->id, 'kind' => $x->kind->code, 'body' => $x->body, 'created_at' => $x->created_at->toIso8601String(),
            'user' => ['id' => $x->user->id, 'name' => $x->user->name, 'has_avatar' => (bool) $x->user->avatar_path],
        ])]);
    }

    public function addComment(Request $request, string $id)
    {
        $f = $this->find($id);
        $d = $request->validate(['body' => 'required|string|max:20000']);
        $c = FileComment::create(['file_id' => $f->id, 'user_id' => $request->user()->id, 'kind_id' => Lookup::id('file_comment_kind', 'comment'), 'body' => $d['body']]);
        AuditLogger::log('file.commented', $f);
        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    public function activity(string $id)
    {
        $f = $this->find($id);
        $rows = AuditLog::with('user')->where('organization_id', $this->ctx->organizationId())
            ->where('entity_type', 'FileAsset')->where('entity_id', $f->id)->orderByDesc('created_at')->limit(50)->get();
        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'action' => $r->action, 'user' => $r->user?->name, 'created_at' => $r->created_at->toIso8601String()])]);
    }

    // ---- formal deletion workflow -------------------------------------------------------------

    /** Step 1: anyone allowed to request raises a formal, reasoned request (stored as a file comment). */
    public function requestDeletion(Request $request, string $id)
    {
        $f = $this->find($id);
        $d = $request->validate(['body' => 'required|string|max:20000']);       // the reason, purified rich text
        abort_if($f->legal_hold, 422, __('api.file_legal_hold'));
        abort_if($f->deletionRequests()->whereHas('status', fn ($s) => $s->where('code', 'pending'))->exists(), 422, __('api.file_deletion_pending'));

        $user = $request->user();
        $req = DB::transaction(function () use ($f, $d, $user) {
            $c = FileComment::create(['file_id' => $f->id, 'user_id' => $user->id, 'kind_id' => Lookup::id('file_comment_kind', 'deletion_request'), 'body' => $d['body']]);
            return FileDeletionRequest::create([
                'file_id' => $f->id, 'requested_by' => $user->id, 'comment_id' => $c->id, 'status_id' => Lookup::id('deletion_status', 'pending'),
            ]);
        });
        AuditLogger::log('file.deletion_requested', $f, [], ['request' => $req->id]);

        // Owner + administrators decide.
        $deciders = Notifier::usersWithPermission($this->ctx->organizationId(), 'file.delete_approve')->push($f->owner->loadMissing('language'))->reject(fn ($u) => $u->id === $user->id);
        Notifier::send($deciders, 'file_delete_requested', ['file' => $f->name, 'user' => $user->name], null, ['in_app', 'email'], '/files?open=' . $f->id, $this->ctx->organizationId());

        return response()->json(['data' => ['id' => $req->id]], 201);
    }

    /** Requests I raised, plus those I may decide (my files, or everything for administrators). */
    public function deletionRequests(Request $request)
    {
        $me = $request->user()->id;
        $status = $request->query('status', 'pending');
        $q = FileDeletionRequest::with(['file.owner', 'requester', 'status.translations', 'comment', 'decider'])
            ->whereHas('status', fn ($s) => $s->where('code', $status));
        if (!$this->ctx->can('file.delete_approve')) {
            $q->where(fn ($w) => $w->where('requested_by', $me)->orWhereHas('file', fn ($f) => $f->where('owner_id', $me)));
        }
        $lang = $request->user()->language_id;

        return response()->json(['data' => $q->orderByDesc('created_at')->limit(100)->get()->map(fn ($r) => [
            'id' => $r->id, 'file_id' => $r->file_id, 'file_name' => $r->file?->name, 'file_deleted' => $r->file?->trashed(), 'legal_hold' => (bool) $r->file?->legal_hold,
            'owner' => $r->file?->owner?->name, 'requested_by' => $r->requester?->name, 'reason' => $r->comment?->body,
            'status' => ['code' => $r->status->code, 'label' => $r->status->label($lang), 'color' => $r->status->color],
            'decided_by' => $r->decider?->name, 'decision_note' => $r->decision_note, 'created_at' => $r->created_at->toIso8601String(),
            'can_decide' => $r->status->code === 'pending' && $r->file && ($r->file->owner_id === $me || $this->ctx->can('file.delete_approve')),
        ])]);
    }

    /** Step 2: only the file owner or an administrator decides. Approval = secure soft delete (content purged after retention). */
    public function approveDeletion(Request $request, string $id)
    {
        [$req, $f] = $this->pendingRequest($id, $request);
        abort_if($f->legal_hold, 422, __('api.file_legal_hold'));
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        DB::transaction(function () use ($req, $f, $request, $d) {
            $req->update(['status_id' => Lookup::id('deletion_status', 'approved'), 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]);
            FileComment::create(['file_id' => $f->id, 'user_id' => $request->user()->id, 'kind_id' => Lookup::id('file_comment_kind', 'decision'), 'body' => e($d['note'] ?? 'approved')]);
            $f->delete();
        });
        AuditLogger::log('file.deleted', $f, [], ['request' => $req->id], $d['note'] ?? null);
        $this->notifyDecision($req, $f, 'approved', $request);
        return response()->noContent();
    }

    public function rejectDeletion(Request $request, string $id)
    {
        [$req, $f] = $this->pendingRequest($id, $request);
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        $req->update(['status_id' => Lookup::id('deletion_status', 'rejected'), 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]);
        FileComment::create(['file_id' => $f->id, 'user_id' => $request->user()->id, 'kind_id' => Lookup::id('file_comment_kind', 'decision'), 'body' => e($d['note'] ?? 'rejected')]);
        AuditLogger::log('file.deletion_rejected', $f, [], ['request' => $req->id], $d['note'] ?? null);
        $this->notifyDecision($req, $f, 'rejected', $request);
        return response()->noContent();
    }

    private function pendingRequest(string $id, Request $request): array
    {
        $req = FileDeletionRequest::with(['file', 'status', 'requester'])->findOrFail($id);
        abort_unless($req->status->code === 'pending', 422, __('api.request_not_pending'));
        $f = $req->file;
        abort_unless($f && !$f->trashed() && ($f->owner_id === $request->user()->id || $this->ctx->can('file.delete_approve')), 403);
        return [$req, $f];
    }

    private function notifyDecision(FileDeletionRequest $req, FileAsset $f, string $decision, Request $request): void
    {
        $to = collect([$req->requester, $f->owner])->filter()->unique('id')->reject(fn ($u) => $u->id === $request->user()->id)->each->loadMissing('language');
        Notifier::send($to, 'file_delete_decided', ['file' => $f->name, 'user' => $request->user()->name, 'decision' => __("api.decision_$decision")], null, ['in_app', 'email'], null, $this->ctx->organizationId());
    }

    // ---- case attachments ---------------------------------------------------------------------

    private function caseFor(string $id): SupportCase
    {
        $q = SupportCase::query();
        if (!$this->ctx->can('case.view_all')) {
            $me = request()->user()->id;
            $q->where(fn ($w) => $w->where('requester_id', $me)->orWhere('assignee_id', $me));
        }
        return $q->with('status')->findOrFail($id);
    }

    public function caseAttachments(Request $request, string $id)
    {
        $case = $this->caseFor($id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with($this->with())
            ->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', 'case'))->where('link_id', $case->id))
            ->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function caseUpload(Request $request, string $id)
    {
        $case = $this->caseFor($id);
        abort_if(!$this->ctx->can('case.view_all') && in_array($case->status->code, ['closed', 'cancelled'], true), 422, __('api.in_use'));
        $d = $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', $this->classificationRule()]]);
        abort_if(($d['classification_id'] ?? null) === Lookup::id('file_classification', 'restricted'), 422, __('api.file_case_not_restricted'));

        $asset = FileService::store($request->file('file'), $request->user(), $d, ['type' => 'case', 'id' => $case->id]);
        $asset->load($this->with());
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'case' => $case->reference, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }
}
