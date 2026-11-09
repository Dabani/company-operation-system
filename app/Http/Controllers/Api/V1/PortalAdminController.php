<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIdentity;
use App\Models\FileAsset;
use App\Models\PortalFileShare;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\Portal\ClientInvitationService;
use App\Services\Portal\PortalMfa;
use App\Services\Projects\ProjectAccess;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Staff side of the portal: who has access, what is shared, and the client conversation. Client set-up needs client.manage; project sharing needs project manager. */
class PortalAdminController extends Controller
{
    public function __construct(private ClientInvitationService $invitations, private PortalMfa $mfa, private ProjectAccess $access, private OrganizationContext $ctx) {}

    private function fail(string $field, string $key): never { throw ValidationException::withMessages([$field => [__("portal.$key")]]); }

    // ---- clients and their portal logins --------------------------------------------------------------------------------------------------
    public function overview()
    {
        $clients = Client::with('contacts')->orderBy('name')->get();
        $ids = ClientIdentity::whereIn('client_id', $clients->pluck('id'))->get()->keyBy('client_contact_id');
        return ['data' => $clients->map(fn ($c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'portal_enabled' => $c->portal_enabled, 'portal_invoices' => $c->portal_invoices, 'portal_uploads' => $c->portal_uploads,
            'contacts' => $c->contacts->map(function ($ct) use ($ids) {
                $i = $ids[$ct->id] ?? null;
                return ['id' => $ct->id, 'name' => $ct->name, 'email' => $ct->email, 'identity' => $i ? ['id' => $i->id, 'status' => $i->password === null ? 'invited' : ($i->is_active ? 'active' : 'inactive'),
                    'mfa' => $i->hasMfa(), 'last_login_at' => $i->last_login_at?->toIso8601String()] : null];
            }),
        ])];
    }

    public function setClientPortal(Request $request, string $id)
    {
        $c = Client::findOrFail($id);
        $d = $request->validate(['portal_enabled' => 'sometimes|boolean', 'portal_invoices' => 'sometimes|boolean', 'portal_uploads' => 'sometimes|boolean']);
        $old = $c->only(array_keys($d));
        $c->update($d);
        AuditLogger::log('client.portal_set', $c, $old, $d);
        return response()->noContent();
    }

    public function invite(Request $request, string $id, string $contactId)
    {
        $c = Client::with('contacts')->findOrFail($id);
        if (!$c->portal_enabled) $this->fail('client', 'invite.portal_disabled');
        $contact = $c->contacts->firstWhere('id', $contactId) ?? abort(404);
        if (!$contact->email) $this->fail('email', 'invite.no_email');

        $identity = ClientIdentity::where('client_contact_id', $contact->id)->first();
        if (!$identity) {
            if (ClientIdentity::where('email', strtolower($contact->email))->exists()) $this->fail('email', 'invite.email_taken');
            $identity = ClientIdentity::create(['client_id' => $c->id, 'client_contact_id' => $contact->id, 'email' => $contact->email, 'password' => null, 'is_active' => false, 'invited_at' => now()]);
        } elseif ($identity->password !== null && $identity->is_active) {
            $this->fail('email', 'invite.already_active');
        }
        if ($this->invitations->throttled($identity)) $this->fail('email', 'invite.throttled');
        [, $sent] = $this->invitations->issue($identity, $request->user(), 'invite');
        AuditLogger::log('portal.invited', $identity, [], ['client' => $c->code, 'sent' => $sent]);
        return response()->json(['sent' => $sent], 201);
    }

    public function setIdentityActive(Request $request, string $identityId)
    {
        $d = $request->validate(['active' => 'required|boolean']);
        $i = ClientIdentity::findOrFail($identityId);
        abort_if($d['active'] && $i->password === null, 422);                         // an invited person must accept the invitation first
        $i->update(['is_active' => $d['active']]);
        AuditLogger::log($d['active'] ? 'portal.identity_reactivated' : 'portal.identity_deactivated', $i);
        return response()->noContent();
    }

    public function resetMfa(string $identityId)
    {
        $this->mfa->reset(ClientIdentity::findOrFail($identityId));
        return response()->noContent();
    }

    // ---- project sharing -----------------------------------------------------------------------------------------------------------------
    public function setProjectPortal(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $d = $request->validate(['portal_visible' => 'sometimes|boolean', 'portal_invoices' => 'sometimes|boolean', 'portal_uploads' => 'sometimes|boolean']);
        abort_if(($d['portal_visible'] ?? false) && !$p->client_id, 422, __('portal.project_no_client'));
        $old = $p->only(array_keys($d));
        $p->update($d);
        AuditLogger::log('project.portal_set', $p, $old, $d);
        return response()->noContent();
    }

    public function projectPortal(Request $request, string $id)
    {
        [$p] = $this->access->find($request->user(), $id);
        $shared = PortalFileShare::where('project_id', $p->id)->with('file:id,name')->get();
        return ['data' => [
            'client_id' => $p->client_id, 'portal_visible' => $p->portal_visible, 'portal_invoices' => $p->portal_invoices, 'portal_uploads' => $p->portal_uploads,
            'client_portal_enabled' => (bool) $p->client_id && Client::find($p->client_id)?->portal_enabled,
            'shares' => $shared->map(fn ($s) => ['file_id' => $s->file_id, 'name' => $s->file?->name, 'by_client' => $s->client_identity_id !== null]),
            'comments' => ProjectComment::where('project_id', $p->id)->with(['user:id,name', 'clientIdentity:id,email'])->orderBy('created_at')->get()->map(fn ($c) => [
                'id' => $c->id, 'author' => $c->user?->name ?? $c->clientIdentity?->email, 'from_client' => $c->client_identity_id !== null, 'body' => $c->body,
                'is_client_visible' => $c->is_client_visible, 'created_at' => $c->created_at->toIso8601String()]),
        ]];
    }

    /** Share or unshare one Controlled File of this project with the client (the client can then download it). */
    public function setShare(Request $request, string $id, string $fileId)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $d = $request->validate(['shared' => 'required|boolean']);
        $file = FileAsset::whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', 'project'))->where('link_id', $p->id))->findOrFail($fileId);
        if ($d['shared']) PortalFileShare::firstOrCreate(['project_id' => $p->id, 'file_id' => $file->id], ['shared_by' => $request->user()->id]);
        else PortalFileShare::where('project_id', $p->id)->where('file_id', $file->id)->delete();
        AuditLogger::log($d['shared'] ? 'portal.file_shared' : 'portal.file_unshared', $file, [], ['project' => $p->code]);
        return response()->noContent();
    }

    /** Team comment on the project. Internal unless explicitly marked visible to the client. */
    public function comment(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::canComment($level), 403, 'forbidden');
        $d = $request->validate(['body' => 'required|string|max:20000', 'is_client_visible' => 'boolean']);
        $c = ProjectComment::create(['project_id' => $p->id, 'user_id' => $request->user()->id, 'body' => trim($d['body']), 'is_client_visible' => $d['is_client_visible'] ?? false]);
        AuditLogger::log('project.comment', $p, [], ['visible_to_client' => $c->is_client_visible]);
        return response()->json(['id' => $c->id], 201);
    }
}
