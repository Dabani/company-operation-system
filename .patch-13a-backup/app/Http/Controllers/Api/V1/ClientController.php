<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClientResource;
use App\Models\Client;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Internal client register. perm: client.view / client.manage. The external portal login is a separate identity (PortalController). */
class ClientController extends Controller
{
    use AppliesListQuery;

    public function index(Request $request)
    {
        return $this->respondList($request, Client::query()->with('status'), ClientResource::class, ['code', 'name', 'email'], ['name', 'code', 'created_at'],
            ['status_id', 'portal_enabled'], ['code', 'name', 'email', 'phone', 'status', 'portal_enabled']);
    }

    public function show(string $id) { return new ClientResource(Client::with(['status', 'contacts'])->findOrFail($id)); }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $c = Client::create($d + ['status_id' => $d['status_id'] ?? Lookup::id('client_status', 'active')]);
        AuditLogger::log('client.created', $c, [], $d);
        return (new ClientResource($c->load(['status', 'contacts'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $c = Client::findOrFail($id);
        $d = $this->validated($request, $c);
        $old = $c->only(array_keys($d));
        $c->update($d);
        AuditLogger::log('client.updated', $c, $old, $d);
        return new ClientResource($c->load(['status', 'contacts']));
    }

    public function destroy(string $id)
    {
        $c = Client::findOrFail($id);
        $c->delete();
        AuditLogger::log('client.deleted', $c);
        return response()->noContent();
    }

    public function storeContact(Request $request, string $id)
    {
        $c = Client::findOrFail($id);
        $d = $request->validate(['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'job_title' => 'nullable|string|max:120', 'is_primary' => 'boolean']);
        $contact = \App\Models\ClientContact::withoutGlobalScopes()->create($d + ['client_id' => $c->id, 'organization_id' => $c->organization_id]);
        AuditLogger::log('client.contact_added', $c, [], ['contact' => $contact->name]);
        return response()->json(['id' => $contact->id], 201);
    }

    public function destroyContact(string $id, string $contactId)
    {
        $c = Client::findOrFail($id);
        \App\Models\ClientContact::withoutGlobalScopes()->where('client_id', $c->id)->findOrFail($contactId)->delete();
        AuditLogger::log('client.contact_removed', $c);
        return response()->noContent();
    }

    private function validated(Request $r, ?Client $m = null): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        return $r->validate([
            // no 'code': the client code is system-generated (CLI-0001)
            'name' => [$m ? 'sometimes' : 'required', 'string', 'max:255'], 'status_id' => ['sometimes', Rule::exists('lookup_values', 'id')],
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:2000',
            'tax_number' => 'nullable|string|max:60', 'portal_enabled' => 'boolean',
        ]);
    }
}
