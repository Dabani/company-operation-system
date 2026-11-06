<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\Projects\ProjectAccess;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project and task attachments are Controlled Files (classification, versions, scan, audit) linked by type "project" / "task".
 * Visibility = project visibility AND the file's own classification rules (FileAccess). Restricted files cannot be attached here.
 */
class ProjectFileController extends Controller
{
    public function __construct(private ProjectAccess $access) {}

    private function with(): array { return ['classification.translations', 'status.translations', 'owner']; }

    private function target(Request $r, string $kind, string $id): array
    {
        if ($kind === 'project') {
            [$p, $level] = $this->access->find($r->user(), $id);
            return [$p->id, $level];
        }
        $t = Task::findOrFail($id);
        [, $level] = $this->access->find($r->user(), $t->project_id);
        return [$t->id, $level];
    }

    public function index(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['project', 'task'], true), 404);
        [$linkId] = $this->target($request, $kind, $id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with($this->with())->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', $kind))->where('link_id', $linkId))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function store(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['project', 'task'], true), 404);
        [$linkId, $level] = $this->target($request, $kind, $id);
        abort_unless(ProjectAccess::canComment($level), 403, 'forbidden');
        $d = $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'file_classification')->pluck('id')))]]);
        abort_if(($d['classification_id'] ?? null) === Lookup::id('file_classification', 'restricted'), 422, __('projects.file.not_restricted'));

        $asset = FileService::store($request->file('file'), $request->user(), $d, ['type' => $kind, 'id' => $linkId])->load($this->with());
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, $kind => $linkId, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }
}
