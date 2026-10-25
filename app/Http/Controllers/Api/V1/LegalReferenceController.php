<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalReference;
use App\Models\LookupType;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The laws, regulations and standards the organization relies on. Each is "unreviewed" until someone qualified verifies it. */
class LegalReferenceController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function row(LegalReference $l, $lang): array
    {
        return ['id' => $l->id, 'code' => $l->code, 'title' => $l->title, 'citation' => $l->citation, 'jurisdiction' => $l->jurisdiction, 'topic' => $l->topic, 'url' => $l->url, 'notes' => $l->notes,
            'status' => ['id' => $l->status->id, 'code' => $l->status->code, 'label' => $l->status->label($lang), 'color' => $l->status->color],
            'verified_by' => $l->verifier?->name, 'verified_at' => $l->verified_at?->toIso8601String()];
    }

    private function rules(?LegalReference $l = null): array
    {
        return [
            'code' => ['required', 'string', 'max:60', Rule::unique('legal_references', 'code')->where('organization_id', $this->ctx->organizationId())->ignore($l?->id)],
            'title' => 'required|string|max:250', 'citation' => 'nullable|string|max:2000', 'jurisdiction' => 'nullable|string|max:60', 'topic' => 'nullable|string|max:120',
            'url' => 'nullable|url|max:250', 'notes' => 'nullable|string|max:2000',
            'status_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'legal_status')->pluck('id')))],
        ];
    }

    public function index(Request $request)
    {
        $q = LegalReference::with(['status.translations', 'verifier'])->where('organization_id', $this->ctx->organizationId())->orderBy('code');
        if ($s = trim((string) $request->query('search', ''))) $q->where(fn ($w) => $w->where('code', 'like', "%$s%")->orWhere('title', 'like', "%$s%")->orWhere('topic', 'like', "%$s%"));
        if ($st = data_get($request->query('filter'), 'status_id')) $q->where('status_id', $st);
        $lang = $request->user()->language_id;
        return response()->json(['data' => $q->get()->map(fn ($l) => $this->row($l, $lang))]);
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules());
        $l = LegalReference::create($d + ['organization_id' => $this->ctx->organizationId(), 'status_id' => $d['status_id'] ?? Lookup::id('legal_status', 'unreviewed')]);
        AuditLogger::log('legal_reference.created', $l, [], ['code' => $l->code]);
        return response()->json(['data' => $this->row($l->load(['status.translations', 'verifier']), $request->user()->language_id)], 201);
    }

    public function update(Request $request, string $id)
    {
        $l = LegalReference::where('organization_id', $this->ctx->organizationId())->findOrFail($id);
        $d = $request->validate($this->rules($l));
        if (empty($d['status_id'])) unset($d['status_id']);
        $old = $l->only(['title', 'citation', 'status_id']);
        $l->update($d);
        AuditLogger::log('legal_reference.updated', $l, $old, $l->only(['title', 'citation', 'status_id']));
        return response()->json(['data' => $this->row($l->refresh()->load(['status.translations', 'verifier']), $request->user()->language_id)]);
    }

    /** "I checked this against the official text." */
    public function verify(Request $request, string $id)
    {
        $l = LegalReference::where('organization_id', $this->ctx->organizationId())->findOrFail($id);
        $l->update(['status_id' => Lookup::id('legal_status', 'verified'), 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLogger::log('legal_reference.verified', $l, [], ['code' => $l->code]);
        return response()->json(['data' => $this->row($l->refresh()->load(['status.translations', 'verifier']), $request->user()->language_id)]);
    }
}
