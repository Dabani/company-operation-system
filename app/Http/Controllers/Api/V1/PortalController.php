<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FileAsset;
use App\Models\Invoice;
use App\Models\Milestone;
use App\Models\PortalFileShare;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Billing\InvoicePdf;
use App\Services\ClientContext;
use App\Services\FileService;
use App\Services\Notifier;
use App\Services\Portal\PortalAccess;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Everything a client sees. Every model below is client-scoped (BelongsToClient) so another client's identifier answers 404, and the
 * payloads are deliberately narrow: progress only - never task titles, internal comments, time entries, rates, costs or staff data.
 */
class PortalController extends Controller
{
    private function identity() { return app(ClientContext::class)->identity(); }

    private function project(string $id): Project
    {
        return Project::with(['status', 'client'])->where('portal_visible', true)->findOrFail($id);      // not shared / other client's => 404
    }

    public function me()
    {
        $i = $this->identity();
        $c = Client::find($i->client_id);
        return ['email' => $i->email, 'client' => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name]];
    }

    public function contacts() { return ['data' => ClientContact::orderBy('name')->get(['id', 'name', 'email', 'phone', 'job_title'])]; }
    public function contact(string $id) { return ['data' => ClientContact::findOrFail($id)->only(['id', 'name', 'email', 'phone', 'job_title'])]; }

    // ---- projects ------------------------------------------------------------------------------------------------------------------
    public function projects()
    {
        return ['data' => Project::with(['status', 'client'])->where('portal_visible', true)->orderBy('name')->get()->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'status' => $p->status->code, 'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(),
            'progress' => PortalAccess::progress($p->id)['percent'],
        ])];
    }

    public function showProject(string $id)
    {
        $p = $this->project($id);
        $me = $this->identity();
        $shares = PortalFileShare::where('project_id', $p->id)->with(['file.currentVersion', 'file.status'])->orderByDesc('created_at')->get()
            ->filter(fn ($s) => $s->file && $s->file->status?->code === 'active');
        return ['data' => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status->code,
            'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'progress' => PortalAccess::progress($p->id),
            'milestones' => Milestone::where('project_id', $p->id)->orderBy('due_date')->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'due_date' => $m->due_date?->toDateString(),
                'completed' => $m->completed_at !== null, 'progress' => PortalAccess::progress($p->id, $m->id)['percent']]),
            'deliverables' => $shares->map(fn ($s) => ['id' => $s->file_id, 'name' => $s->file->name, 'size_bytes' => $s->file->size_bytes, 'added_at' => $s->created_at->toIso8601String(), 'mine' => $s->client_identity_id === $me->id])->values(),
            'comments' => ProjectComment::where('project_id', $p->id)->where('is_client_visible', true)->with(['user:id,name', 'clientIdentity:id,email'])->orderBy('created_at')->get()->map(fn ($c) => [
                'id' => $c->id, 'author' => $c->user?->name ?? ($c->client_identity_id === $me->id ? null : $c->clientIdentity?->email), 'mine' => $c->client_identity_id === $me->id,
                'staff' => $c->user_id !== null, 'body' => $c->body, 'created_at' => $c->created_at->toIso8601String()]),
            'features' => ['uploads' => PortalAccess::enabled($p, 'portal_uploads'), 'invoices' => PortalAccess::enabled($p, 'portal_invoices')],
        ]];
    }

    public function comment(Request $request, string $id)
    {
        $p = $this->project($id);
        $d = $request->validate(['body' => 'required|string|max:20000']);
        $me = $this->identity();
        $c = ProjectComment::create(['project_id' => $p->id, 'client_identity_id' => $me->id, 'body' => trim($d['body']), 'is_client_visible' => true]);
        $this->notifyTeam($p, 'portal_client_comment');
        AuditLogger::log('portal.comment', $p, [], ['comment' => $c->id]);
        return response()->json(['id' => $c->id], 201);
    }

    // ---- files -----------------------------------------------------------------------------------------------------------------------
    public function upload(Request $request, string $id)
    {
        $p = $this->project($id);
        abort_unless(PortalAccess::enabled($p, 'portal_uploads'), 403, __('portal.feature_disabled'));
        $request->validate(['file' => 'required|file']);
        $file = $request->file('file');

        $org = $p->organization_id;
        $allowed = array_values(array_intersect(array_map('trim', explode(',', strtolower((string) SettingsService::get($org, 'portal.upload_extensions')))), config('ops.files.allowed_extensions')));
        if (!in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) throw ValidationException::withMessages(['file' => [__('portal.upload.type_not_allowed', ['types' => implode(', ', $allowed)])]]);
        $maxMb = min((int) SettingsService::get($org, 'portal.upload_max_mb'), (int) config('ops.files.max_mb'));
        if ($file->getSize() > $maxMb * 1024 * 1024) throw ValidationException::withMessages(['file' => [__('portal.upload.too_large', ['mb' => $maxMb])]]);

        $owner = User::findOrFail($p->owner_id);                                    // internal owner of record; the uploader is the client identity
        $asset = FileService::store($file, $owner, [], ['type' => 'project', 'id' => $p->id]);     // validates type, detects content, virus-scans, stores a version
        PortalFileShare::create(['project_id' => $p->id, 'file_id' => $asset->id, 'client_identity_id' => $this->identity()->id]);
        $this->notifyTeam($p, 'portal_client_upload');
        AuditLogger::log('portal.file_uploaded', $asset, [], ['name' => $asset->name, 'project' => $p->code, 'client_identity_id' => $this->identity()->id]);
        return response()->json(['id' => $asset->id], 201);
    }

    public function download(string $id, string $fileId)
    {
        $p = $this->project($id);
        PortalFileShare::where('project_id', $p->id)->where('file_id', $fileId)->firstOrFail();
        $f = FileAsset::with('currentVersion')->findOrFail($fileId);
        abort_unless($f->currentVersion && FileService::exists($f->currentVersion), 404);
        AuditLogger::log('portal.file_downloaded', $f, [], ['project' => $p->code]);
        return Storage::disk(FileService::disk())->download($f->currentVersion->storage_path, $f->currentVersion->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    // ---- invoices (only when enabled for the client or the project) ------------------------------------------------------------------
    private function visibleInvoices(Project $p)
    {
        abort_unless(PortalAccess::enabled($p, 'portal_invoices'), 403, __('portal.feature_disabled'));
        return Invoice::where('project_id', $p->id)->whereHas('status', fn ($s) => $s->whereIn('code', ['issued', 'partially_paid', 'paid', 'overdue']));
    }

    public function invoices(string $id)
    {
        $p = $this->project($id);
        return ['data' => $this->visibleInvoices($p)->with('status')->orderByDesc('issue_date')->get()->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status->code,
            'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(), 'currency_code' => $i->currency_code,
            'total' => $i->total, 'paid_total' => $i->paid_total, 'balance' => $i->balance])];
    }

    /** The client's invoice PDF carries the invoice only - the internal time breakdown (people and hours) is never included. */
    public function invoicePdf(string $id, string $invoiceId)
    {
        $p = $this->project($id);
        $i = $this->visibleInvoices($p)->findOrFail($invoiceId);
        AuditLogger::log('portal.invoice_downloaded', $i);
        return response((new InvoicePdf())->render($i, false), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$i->number}.pdf\"", 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function notifyTeam(Project $p, string $key): void
    {
        $ids = ProjectMember::where('project_id', $p->id)->whereHas('role', fn ($r) => $r->where('code', 'manager'))->pluck('user_id')->push($p->owner_id)->unique();
        Notifier::send(User::whereIn('id', $ids)->get(), $key, ['title' => $p->code . ' ' . $p->name, 'client' => $p->client?->name ?? ''], null, ['in_app', 'email'], "/projects/{$p->id}", $p->organization_id);
    }
}
