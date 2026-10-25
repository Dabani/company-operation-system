<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArchiveRecord;
use App\Models\LegalReference;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\RetentionRule;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\RecordRules;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Retention schedule per record category + the two archive settings. Changes re-date the affected records. */
class RetentionController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    public function index(Request $request)
    {
        $org = $this->ctx->organizationId();
        $lang = $request->user()->language_id;
        $cats = LookupValue::visible()->with('translations')->where('is_active', true)->whereHas('type', fn ($t) => $t->where('code', 'record_category'))->orderBy('sort_order')->get();
        $rules = RetentionRule::with(['disposalAction.translations', 'legalReference'])->where(fn ($q) => $q->where('organization_id', $org)->orWhereNull('organization_id'))->get()->groupBy('category_id');

        return response()->json(['data' => [
            'rules' => $cats->map(function ($c) use ($rules, $org, $lang) {
                $set = $rules->get($c->id, collect());
                $own = $set->firstWhere('organization_id', $org); $global = $set->firstWhere('organization_id', null);
                $eff = $own ?? $global;
                return [
                    'category' => ['id' => $c->id, 'code' => $c->code, 'label' => $c->label($lang)],
                    'retention_years' => $eff?->retention_years ?? SettingsService::get($org, 'archive.default_retention_years'),
                    'disposal_action' => $eff?->disposalAction ? ['id' => $eff->disposalAction->id, 'code' => $eff->disposalAction->code, 'label' => $eff->disposalAction->label($lang)] : null,
                    'legal_reference' => $eff?->legalReference ? ['id' => $eff->legalReference->id, 'code' => $eff->legalReference->code, 'title' => $eff->legalReference->title] : null,
                    'notes' => $eff?->notes, 'overridden' => (bool) $own, 'default_years' => $global?->retention_years,
                ];
            })->values(),
            'settings' => ['transfer_days' => SettingsService::get($org, 'archive.transfer_days'), 'default_retention_years' => SettingsService::get($org, 'archive.default_retention_years'), 'training_retention_years' => SettingsService::get($org, 'training.retention_years')],
        ]]);
    }

    private function redate(string $categoryId): int
    {
        $n = 0;
        ArchiveRecord::whereHas('status', fn ($s) => $s->whereIn('code', ['inactive', 'archived', 'disposal_pending']))->when($categoryId !== '*', fn ($q) => $q->where('category_id', $categoryId))
            ->with(['status', 'format'])->chunkById(200, function ($recs) use (&$n) {
                foreach ($recs as $r) {
                    $old = $r->retention_until?->toDateString();
                    RecordRules::recompute($r);
                    if ($r->isDirty()) { $r->save(); $n++; if ($r->retention_until?->toDateString() !== $old) RecordRules::event($r, 'retention_changed', $old, $r->retention_until?->toDateString(), 'rule'); }
                }
            });
        return $n;
    }

    public function upsert(Request $request, string $categoryId)
    {
        $org = $this->ctx->organizationId();
        abort_unless(LookupValue::visible()->whereKey($categoryId)->whereHas('type', fn ($t) => $t->where('code', 'record_category'))->exists(), 404);
        $d = $request->validate([
            'retention_years' => 'required|integer|min:1|max:100', 'disposal_action_id' => ['required', $this->lookupRule('record_disposal_action')],
            'legal_reference_id' => ['nullable', Rule::exists('legal_references', 'id')->where('organization_id', $org)], 'notes' => 'nullable|string|max:500',
        ]);
        $rule = RetentionRule::updateOrCreate(['organization_id' => $org, 'category_id' => $categoryId], $d);
        $changed = $this->redate($categoryId);
        AuditLogger::log('retention.rule_saved', $rule, [], ['category' => LookupValue::find($categoryId)->code, 'years' => (string) $d['retention_years'], 'records_redated' => (string) $changed]);
        return response()->json(['data' => ['records_redated' => $changed]]);
    }

    public function destroy(string $categoryId)
    {
        $org = $this->ctx->organizationId();
        $rule = RetentionRule::where('organization_id', $org)->where('category_id', $categoryId)->firstOrFail();
        $rule->delete();
        $changed = $this->redate($categoryId);
        AuditLogger::log('retention.override_removed', $rule, [], ['records_redated' => (string) $changed]);
        return response()->json(['data' => ['records_redated' => $changed]]);
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['transfer_days' => 'required|integer|min:1|max:365', 'default_retention_years' => 'required|integer|min:1|max:100', 'training_retention_years' => 'sometimes|integer|min:1|max:50']);
        $org = $this->ctx->organizationId();
        $old = ['transfer_days' => (string) SettingsService::get($org, 'archive.transfer_days'), 'default_years' => (string) SettingsService::get($org, 'archive.default_retention_years')];
        SettingsService::set($org, 'archive.transfer_days', $d['transfer_days']);
        SettingsService::set($org, 'archive.default_retention_years', $d['default_retention_years']);
        if (isset($d['training_retention_years'])) SettingsService::set($org, 'training.retention_years', $d['training_retention_years']);
        $changed = $this->redate('*');
        AuditLogger::log('archive.settings_changed', null, $old, ['transfer_days' => (string) $d['transfer_days'], 'default_years' => (string) $d['default_retention_years']]);
        return response()->json(['data' => ['records_redated' => $changed]]);
    }
}
