<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LookupValueResource;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LookupController extends Controller
{
    public function types()
    {
        return response()->json(['data' => LookupType::orderBy('name')->get(['id', 'code', 'name', 'is_system'])]);
    }

    /** GET /lookups?type=user_status[&all=1] -> values for dropdowns (active only unless all=1 and caller can manage). */
    public function index(Request $request)
    {
        $request->validate(['type' => 'required|string']);
        $q = LookupValue::visible()->with(['type', 'translations.language'])
            ->whereHas('type', fn ($t) => $t->where('code', $request->type))->orderBy('sort_order');
        if (!($request->boolean('all') && app(OrganizationContext::class)->can('lookup.manage'))) {
            $q->where('is_active', true);
        }
        return LookupValueResource::collection($q->get());
    }

    public function languages()
    {
        return response()->json(['data' => Language::where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'native_name'])]);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $value = DB::transaction(function () use ($d) {
            $v = LookupValue::create([
                'lookup_type_id' => $d['lookup_type_id'],
                'organization_id' => app(OrganizationContext::class)->organizationId(),   // org-owned values only
                'code' => $d['code'], 'color' => $d['color'] ?? null, 'icon' => $d['icon'] ?? null,
                'sort_order' => $d['sort_order'] ?? 0, 'is_active' => $d['is_active'] ?? true,
            ]);
            $this->saveLabels($v, $d['labels']);
            return $v;
        });
        AuditLogger::log('lookup.created', $value, [], ['code' => $value->code]);
        Lookup::flush();
        return (new LookupValueResource($value->load(['type', 'translations.language'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $v = $this->ownedOrGlobal($id);
        $d = $this->validated($request, $v);
        // Global/system values: only colour, icon, order, active flag and labels may change; the code is a stable key.
        $attrs = ['color' => $d['color'] ?? null, 'icon' => $d['icon'] ?? null, 'sort_order' => $d['sort_order'] ?? 0, 'is_active' => $d['is_active'] ?? true];
        if (!$v->is_system && $v->organization_id) {
            $attrs['code'] = $d['code'];
        }
        $old = $v->only(array_keys($attrs));
        $v->update($attrs);
        $this->saveLabels($v, $d['labels']);
        AuditLogger::log('lookup.updated', $v, $old, $attrs);
        Lookup::flush();
        return new LookupValueResource($v->load(['type', 'translations.language']));
    }

    public function destroy(string $id)
    {
        $v = $this->ownedOrGlobal($id);
        abort_if($v->is_system || !$v->organization_id, 422, __('api.system_value_locked'));
        try {
            $v->delete();
        } catch (\Throwable $e) {
            abort(422, __('api.in_use'));      // FK violation: deactivate instead of delete
        }
        AuditLogger::log('lookup.deleted', $v);
        Lookup::flush();
        return response()->noContent();
    }

    private function ownedOrGlobal(string $id): LookupValue
    {
        return LookupValue::visible()->findOrFail($id);
    }

    private function validated(Request $r, ?LookupValue $v = null): array
    {
        $typeId = $v?->lookup_type_id ?? $r->input('lookup_type_id');
        $org = app(OrganizationContext::class)->organizationId();
        return $r->validate([
            'lookup_type_id' => [$v ? 'nullable' : 'required', 'exists:lookup_types,id'],
            'code' => ['required', 'alpha_dash', 'max:60', Rule::unique('lookup_values', 'code')->where('lookup_type_id', $typeId)->where('organization_id', $org)->ignore($v?->id)],
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:60',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'labels' => 'required|array',                       // { "en": "Label", "fr": "..." }  (request shape only; stored as rows)
            'labels.en' => 'required|string|max:150',
            'labels.*' => 'nullable|string|max:150',
        ]);
    }

    private function saveLabels(LookupValue $v, array $labels): void
    {
        $langs = Language::pluck('id', 'code');
        foreach ($labels as $code => $label) {
            if (!isset($langs[$code]) || $label === null || $label === '') {
                continue;
            }
            $v->translations()->updateOrCreate(['language_id' => $langs[$code]], ['label' => $label]);
        }
    }
}
