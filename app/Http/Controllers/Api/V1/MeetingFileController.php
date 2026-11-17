<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Models\Meeting;
use App\Models\MeetingAgendaItem;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\Meetings\MeetingAccess;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Papers for a meeting or for one agenda item are Controlled Files (classification, versions, scan, audit) linked by type
 * "meeting" / "meeting_agenda_item". Who sees them = who sees the meeting (or the agenda item) AND the file's own classification rules.
 * Files of a closed or cancelled meeting are read-only.
 */
class MeetingFileController extends Controller
{
    public function __construct(private MeetingAccess $access) {}

    private function with(): array { return ['classification.translations', 'status.translations', 'owner']; }

    /** @return array{0:Meeting,1:string,2:string,3:bool} meeting, link type, link id, may upload */
    private function target(Request $r, string $kind, string $id): array
    {
        $u = $r->user();
        if ($kind === 'meeting') {
            $m = $this->access->find($u, $id, ['status']);
            return [$m, 'meeting', $m->id, $this->access->canManage($u, $m)];
        }
        $item = MeetingAgendaItem::findOrFail($id);
        $m = $this->access->find($u, $item->meeting_id, ['status']);
        abort_unless($this->access->canSeeItem($u, $m, $item), 404);
        return [$m, 'meeting_agenda_item', $item->id, $this->access->canManage($u, $m) || $item->presenter_id === $u->id];
    }

    public function index(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['meeting', 'agenda-item'], true), 404);
        [, $type, $linkId] = $this->target($request, $kind, $id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with($this->with())->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', $type))->where('link_id', $linkId))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function store(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['meeting', 'agenda-item'], true), 404);
        [$m, $type, $linkId, $mayUpload] = $this->target($request, $kind, $id);
        abort_unless($mayUpload, 403, 'forbidden');
        abort_if(in_array($m->status->code, ['closed', 'cancelled'], true), 422, __('meetings.files_read_only'));
        $d = $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'file_classification')->pluck('id')))]]);
        abort_if(($d['classification_id'] ?? null) === Lookup::id('file_classification', 'restricted'), 422, __('meetings.file_not_restricted'));

        $asset = FileService::store($request->file('file'), $request->user(), $d, ['type' => $type, 'id' => $linkId])->load($this->with());
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, $type => $linkId, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }
}
