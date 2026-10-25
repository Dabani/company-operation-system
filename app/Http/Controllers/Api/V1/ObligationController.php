<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ComplianceObligation;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\ObligationService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Compliance calendar: recurring duties with due dates, owners and completion evidence. */
class ObligationController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['frequency.translations', 'role', 'responsible', 'legalReference']; }

    private function row(ComplianceObligation $o, $lang): array
    {
        $state = ObligationService::state($o);
        return [
            'id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'description' => $o->description, 'is_active' => $o->is_active,
            'frequency' => ['id' => $o->frequency->id, 'code' => $o->frequency->code, 'label' => $o->frequency->label($lang)],
            'responsible_role_id' => $o->responsible_role_id, 'responsible_role' => $o->role?->name, 'responsible_user_id' => $o->responsible_user_id, 'responsible_user' => $o->responsible?->name,
            'legal_reference_id' => $o->legal_reference_id, 'legal_reference' => $o->legalReference?->code, 'link_route' => $o->link_route,
            'next_due_on' => $o->next_due_on->toDateString(), 'last_completed_on' => $o->last_completed_on?->toDateString(),
            'days_until' => today()->diffInDays($o->next_due_on, false), 'state' => $state,
        ];
    }

    public function index(Request $request)
    {
        $lang = $request->user()->language_id;
        $rows = ComplianceObligation::with($this->with())->orderBy('next_due_on')->get();
        return response()->json(['data' => $rows->map(fn ($o) => $this->row($o, $lang))->values()]);
    }

    private function rules(?ComplianceObligation $o = null): array
    {
        $org = $this->ctx->organizationId();
        return [
            'code' => ['required', 'alpha_dash', 'max:60', Rule::unique('compliance_obligations', 'code')->where('organization_id', $org)->ignore($o?->id)],
            'title' => 'required|string|max:200', 'description' => 'nullable|string|max:20000',
            'frequency_id' => ['required', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'obligation_frequency')->pluck('id')))],
            'responsible_role_id' => ['nullable', Rule::exists('roles', 'id')->where('organization_id', $org)],
            'responsible_user_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'legal_reference_id' => ['nullable', Rule::exists('legal_references', 'id')->where('organization_id', $org)],
            'link_route' => 'nullable|string|max:120|regex:/^\\/[A-Za-z0-9\\/_\\-?=&]*$/', 'next_due_on' => 'required|date', 'is_active' => 'boolean',
        ];
    }

    public function store(Request $request)
    {
        $o = ComplianceObligation::create($request->validate($this->rules()));
        AuditLogger::log('obligation.created', $o, [], ['code' => $o->code, 'next_due_on' => $o->next_due_on->toDateString()]);
        return response()->json(['data' => $this->row($o->load($this->with()), $request->user()->language_id)], 201);
    }

    public function update(Request $request, string $id)
    {
        $o = ComplianceObligation::findOrFail($id);
        $d = $request->validate($this->rules($o));
        $old = $o->only(['title', 'next_due_on', 'is_active']);
        $o->update($d);
        AuditLogger::log('obligation.updated', $o, array_map('strval', $old), array_map('strval', $o->only(['title', 'next_due_on', 'is_active'])));
        return response()->json(['data' => $this->row($o->refresh()->load($this->with()), $request->user()->language_id)]);
    }

    /** Mark this cycle done. Allowed for compliance managers and for the person named as responsible. */
    public function complete(Request $request, string $id)
    {
        $o = ComplianceObligation::with($this->with())->findOrFail($id);
        abort_unless($this->ctx->can('compliance.manage') || $o->responsible_user_id === $request->user()->id, 403);
        $d = $request->validate(['notes' => 'nullable|string|max:1000', 'completed_on' => 'nullable|date|before_or_equal:today']);
        $c = ObligationService::complete($o, Carbon::parse($d['completed_on'] ?? today()), $request->user(), $d['notes'] ?? null);
        AuditLogger::log('obligation.completed', $o, [], ['code' => $o->code, 'due_on' => $c->due_on->toDateString(), 'next_due_on' => $o->next_due_on->toDateString()]);
        return response()->json(['data' => $this->row($o->refresh()->load($this->with()), $request->user()->language_id)]);
    }

    public function completions(string $id)
    {
        $o = ComplianceObligation::findOrFail($id);
        return response()->json(['data' => $o->completions()->with('user')->orderByDesc('completed_on')->orderByDesc('id')->limit(50)->get()->map(fn ($c) => [
            'id' => $c->id, 'due_on' => $c->due_on->toDateString(), 'completed_on' => $c->completed_on->toDateString(), 'by' => $c->user?->name, 'notes' => $c->notes, 'automatic' => $c->automatic,
            'late' => $c->completed_on->gt($c->due_on),
        ])]);
    }
}
