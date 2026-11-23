<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LookupType;
use App\Models\TripRate;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Per diem / accommodation / transport rate tables (perm hr.trip.configure). Effective-dated: a change is a new rate, the old one is ended, never edited. */
class TripSetupController extends Controller
{
    private function lookup(string $type): array { return ['required', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id'))]; }

    private function shape(TripRate $r): array
    {
        $r->loadMissing(['kind', 'zone', 'currency']);
        return ['id' => $r->id, 'kind' => $r->kind->code, 'zone' => $r->zone->code, 'zone_id' => $r->zone_id, 'grade_id' => $r->grade_id, 'currency' => $r->currency->code, 'amount' => (string) $r->amount,
            'valid_from' => $r->valid_from->toDateString(), 'valid_to' => $r->valid_to?->toDateString(), 'source_note' => $r->source_note, 'is_active' => $r->is_active];
    }

    public function rates() { return ['data' => TripRate::with(['kind', 'zone', 'currency'])->orderBy('kind_id')->orderBy('zone_id')->orderByDesc('valid_from')->get()->map(fn ($r) => $this->shape($r))]; }

    public function storeRate(Request $request)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $d = $request->validate(['kind_id' => $this->lookup('trip_rate_kind'), 'zone_id' => $this->lookup('trip_zone'), 'currency_id' => $this->lookup('currency'), 'amount' => 'required|numeric|min:0|max:9999999999999',
            'grade_id' => ['nullable', Rule::exists('hr_grades', 'id')->where('organization_id', $org)], 'valid_from' => 'required|date_format:Y-m-d', 'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'source_note' => 'nullable|string|max:255']);
        $r = TripRate::create($d + ['created_by' => $request->user()->id]);
        AuditLogger::log('trip.rate_created', $r, [], ['amount' => (string) $d['amount'], 'valid_from' => $d['valid_from']]);
        return response()->json(['data' => $this->shape($r)], 201);
    }

    public function endRate(Request $request, string $id)
    {
        $r = TripRate::findOrFail($id);
        $d = $request->validate(['valid_to' => 'required|date_format:Y-m-d|after_or_equal:' . $r->valid_from->toDateString()]);
        $old = ['valid_to' => $r->valid_to?->toDateString()];
        $r->update($d);
        AuditLogger::log('trip.rate_ended', $r, $old, $d);
        return ['data' => $this->shape($r->refresh())];
    }
}
