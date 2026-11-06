<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RateCardLine;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Billing rates are confidential: rates.view / rates.manage only. Every change is audited and lines are effective-dated, never overwritten. */
class RateCardController extends Controller
{
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    private function shape(RateCard $c): array
    {
        $c->loadMissing(['lines.scope', 'lines.projectRole', 'lines.user:id,name', 'lines.task:id,number,title']);
        return ['id' => $c->id, 'name' => $c->name, 'currency_code' => $c->currency_code, 'is_active' => $c->is_active, 'lines' => $c->lines->map(fn ($l) => [
            'id' => $l->id, 'scope' => $l->scope->code, 'role' => $l->projectRole?->code, 'user_id' => $l->user_id, 'user' => $l->user?->name, 'task_id' => $l->task_id,
            'task' => $l->task ? "#{$l->task->number} {$l->task->title}" : null, 'hourly_rate' => $l->hourly_rate, 'effective_from' => $l->effective_from->toDateString(), 'effective_to' => $l->effective_to?->toDateString()])];
    }

    public function index() { return ['data' => RateCard::with('lines')->orderBy('name')->get()->map(fn ($c) => $this->shape($c))]; }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:255', 'currency_code' => 'required|string|size:3']);
        $c = RateCard::create(['name' => $d['name'], 'currency_code' => strtoupper($d['currency_code'])]);
        AuditLogger::log('rate_card.created', $c, [], $d);
        return response()->json(['data' => $this->shape($c)], 201);
    }

    public function storeLine(Request $request, string $id)
    {
        $c = RateCard::findOrFail($id);
        $org = $this->org();
        $d = $request->validate([
            'scope_id' => ['required', Rule::exists('lookup_values', 'id')], 'hourly_rate' => 'required|numeric|min:0|max:9999999999999',
            'project_role_id' => ['nullable', Rule::exists('lookup_values', 'id')], 'user_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'task_id' => ['nullable', Rule::exists('tasks', 'id')->where('organization_id', $org)], 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);
        $scope = \App\Models\LookupValue::findOrFail($d['scope_id'])->code;
        $need = ['role' => 'project_role_id', 'employee' => 'user_id', 'task' => 'task_id'][$scope] ?? null;
        if ($need && empty($d[$need])) throw ValidationException::withMessages([$need => [__('time.rate.scope_value_required')]]);
        $line = RateCardLine::create($d + ['rate_card_id' => $c->id]);
        AuditLogger::log('rate_card.line_added', $c, [], ['scope' => $scope, 'rate' => $d['hourly_rate'], 'from' => $d['effective_from']]);
        return response()->json(['id' => $line->id], 201);
    }

    /** Lines are history: end-date them instead of deleting. */
    public function endLine(Request $request, string $id, string $lineId)
    {
        $d = $request->validate(['effective_to' => 'required|date']);
        $line = RateCardLine::where('rate_card_id', $id)->findOrFail($lineId);
        abort_if($line->effective_to !== null && $line->effective_to->lt($d['effective_to']), 422);
        $line->update(['effective_to' => $d['effective_to']]);
        AuditLogger::log('rate_card.line_ended', $line, [], $d);
        return response()->noContent();
    }

    public function setProjectCard(Request $request, string $id)
    {
        $d = $request->validate(['rate_card_id' => ['nullable', Rule::exists('rate_cards', 'id')->where('organization_id', $this->org())]]);
        $p = Project::findOrFail($id);
        $p->update(['rate_card_id' => $d['rate_card_id']]);
        AuditLogger::log('project.rate_card_set', $p, [], $d);
        return response()->noContent();
    }
}
