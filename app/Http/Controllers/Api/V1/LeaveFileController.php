<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\Employee;
use App\Models\FileAsset;
use App\Models\LeaveRequest;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;

/**
 * Supporting documents of a leave request (medical certificate...): Controlled Files (scan, versions, audit) linked by type "leave_request".
 * Visible to the employee and to people whose scope covers the employee; restricted files cannot be attached.
 */
class LeaveFileController extends Controller
{
    private function request(Request $r, string $id): LeaveRequest
    {
        $ctx = app(OrganizationContext::class);
        $q = LeaveRequest::query();
        if ($ctx->can('hr.leave.view') || $ctx->can('hr.leave.manage')) {
            $q->whereIn('employee_id', Employee::query()->visibleTo($r->user())->select('hr_employees.id'));
        } else {
            $q->where('employee_id', Employee::where('user_id', $r->user()->id)->value('id') ?? '0');
        }
        return $q->findOrFail($id);
    }

    public function index(Request $request, string $id)
    {
        $l = $this->request($request, $id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($x) => $x->where('link_type_id', Lookup::id('file_link_type', 'leave_request'))->where('link_id', $l->id))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function store(Request $request, string $id)
    {
        $l = $this->request($request, $id);
        abort_unless($l->requested_by === $request->user()->id || app(OrganizationContext::class)->can('hr.leave.manage') || $l->employee?->user_id === $request->user()->id, 403, 'forbidden');
        $request->validate(['file' => 'required|file']);
        $asset = FileService::store($request->file('file'), $request->user(), ['classification_id' => Lookup::id('file_classification', 'confidential')], ['type' => 'leave_request', 'id' => $l->id])
            ->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'leave_request' => $l->id]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }
}
