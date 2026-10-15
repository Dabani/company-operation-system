<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use AppliesListQuery;

    public function index(Request $request, OrganizationContext $ctx)
    {
        $q = AuditLog::query()->with(['user', 'outcome'])->where('organization_id', $ctx->organizationId());
        return $this->respondList($request, $q, AuditLogResource::class,
            ['action', 'entity_type', 'request_id', 'user.name'], ['created_at', 'action'], ['action', 'entity_type'],
            ['created_at', 'action', 'entity_type', 'entity_id', 'user', 'outcome', 'ip_address']);
    }

    public function show(string $id, OrganizationContext $ctx)
    {
        return new AuditLogResource(AuditLog::with(['user', 'outcome', 'changes'])->where('organization_id', $ctx->organizationId())->findOrFail($id));
    }
}
