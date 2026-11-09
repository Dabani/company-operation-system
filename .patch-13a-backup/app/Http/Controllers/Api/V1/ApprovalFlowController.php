<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequest;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Flow definitions are configuration: separate permission (approval.configure), versioned, and a new version only goes live
 * when activated by a different person than its author (segregation of duties for configuration changes).
 */
class ApprovalFlowController extends Controller
{
    private function shape(ApprovalFlow $f): array
    {
        return [
            'id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'description' => $f->description, 'version' => $f->version,
            'subject_type' => $f->subjectType?->code, 'subject_type_id' => $f->subject_type_id, 'is_active' => $f->is_active,
            'effective_from' => $f->effective_from->toDateString(), 'effective_to' => $f->effective_to?->toDateString(), 'created_by' => $f->created_by,
            'steps' => $f->steps->map(fn ($s) => ['id' => $s->id, 'step_order' => $s->step_order, 'label' => $s->label, 'step_type' => $s->type?->code,
                'step_type_id' => $s->step_type_id, 'role_id' => $s->role_id, 'role' => $s->role?->name, 'user_id' => $s->user_id, 'escalate_after_hours' => $s->escalate_after_hours]),
        ];
    }

    public function index()
    {
        return ['data' => ApprovalFlow::with(['steps.type', 'steps.role', 'subjectType'])->orderBy('code')->orderByDesc('version')->get()->map(fn ($f) => $this->shape($f))];
    }

    public function store(Request $request)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $d = $request->validate([
            'name' => 'required|string|max:255', 'description' => 'nullable|string|max:2000',
            'new_version_of' => ['nullable', Rule::exists('approval_flows', 'id')->where('organization_id', $org)],     // the flow code (FLW-0001) is system-generated
            'subject_type_id' => ['nullable', Rule::exists('lookup_values', 'id')], 'effective_from' => 'required|date',
            'steps' => 'required|array|min:1|max:10',
            'steps.*.label' => 'required|string|max:120',
            'steps.*.step_type_id' => ['required', Rule::exists('lookup_values', 'id')],
            'steps.*.role_id' => ['nullable', Rule::exists('roles', 'id')->where('organization_id', $org)],
            'steps.*.user_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'steps.*.escalate_after_hours' => 'nullable|integer|min:1|max:2000',
        ]);
        foreach ($d['steps'] as $s) {   // each step must carry what its type needs
            $type = \App\Models\LookupValue::find($s['step_type_id'])?->code;
            if (($type === 'role' && empty($s['role_id'])) || ($type === 'user' && empty($s['user_id']))) {
                throw ValidationException::withMessages(['steps' => [__('foundation.approval.step_incomplete')]]);
            }
        }
        $flow = DB::transaction(function () use ($d, $request) {
            $code = isset($d['new_version_of']) ? ApprovalFlow::findOrFail($d['new_version_of'])->code : null;       // null -> generated
            $version = $code ? (int) ApprovalFlow::where('code', $code)->max('version') + 1 : 1;
            $f = ApprovalFlow::create([
                'code' => $code, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'subject_type_id' => $d['subject_type_id'] ?? null,
                'version' => $version, 'effective_from' => $d['effective_from'], 'is_active' => false, 'created_by' => $request->user()->id,
            ]);
            foreach ($d['steps'] as $i => $s) {
                $f->steps()->create($s + ['step_order' => $i + 1, 'organization_id' => $f->organization_id]);
            }
            return $f;
        });
        AuditLogger::log('approval_flow.created', $flow, [], ['code' => $flow->code, 'version' => $flow->version]);
        return response()->json(['data' => $this->shape($flow->load(['steps.type', 'steps.role', 'subjectType']))], 201);
    }

    public function activate(Request $request, string $id)
    {
        $f = ApprovalFlow::findOrFail($id);
        if ($f->created_by === $request->user()->id) {
            throw ValidationException::withMessages(['flow' => [__('foundation.approval.author_cannot_activate')]]);
        }
        DB::transaction(function () use ($f) {
            ApprovalFlow::where('code', $f->code)->where('id', '!=', $f->id)->where('is_active', true)
                ->update(['is_active' => false, 'effective_to' => $f->effective_from->copy()->subDay()->toDateString()]);
            $f->update(['is_active' => true]);
        });
        AuditLogger::log('approval_flow.activated', $f, [], ['code' => $f->code, 'version' => $f->version]);
        return ['data' => $this->shape($f->refresh()->load(['steps.type', 'steps.role', 'subjectType']))];
    }

    /** Flows referenced by requests are never edited or deleted; deactivate via a new version instead. */
    public function destroy(string $id)
    {
        $f = ApprovalFlow::findOrFail($id);
        abort_if(ApprovalRequest::where('flow_id', $f->id)->exists() || $f->is_active, 422, __('api.in_use'));
        $f->delete();
        AuditLogger::log('approval_flow.deleted', $f);
        return response()->noContent();
    }
}
