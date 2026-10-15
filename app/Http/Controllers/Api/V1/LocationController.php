<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LocationResource;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    use AppliesListQuery;

    public function index(Request $request)
    {
        return $this->respondList($request, Location::query()->with(['parent', 'type.translations']), LocationResource::class,
            ['code', 'name'], ['name', 'code', 'created_at'], ['is_active', 'location_type_id', 'parent_id'], ['code', 'name', 'location_type', 'parent', 'is_active']);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $m = Location::create($d);
        AuditLogger::log('location.created', $m, [], $d);
        return (new LocationResource($m->load(['parent', 'type'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $m = Location::findOrFail($id);
        $d = $this->validated($request, $m);
        abort_if(($d['parent_id'] ?? null) === $m->id, 422);
        $old = $m->only(array_keys($d));
        $m->update($d);
        AuditLogger::log('location.updated', $m, $old, $d);
        return new LocationResource($m->load(['parent', 'type']));
    }

    public function destroy(string $id)
    {
        $m = Location::findOrFail($id);
        $m->delete();
        AuditLogger::log('location.deleted', $m);
        return response()->noContent();
    }

    private function validated(Request $r, ?Location $m = null): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        return $r->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('locations', 'code')->where('organization_id', $org)->ignore($m?->id)],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'location_type_id' => 'required|exists:lookup_values,id',
            'parent_id' => ['nullable', Rule::exists('locations', 'id')->where('organization_id', $org)],
            'is_active' => 'boolean',
        ]);
    }
}
