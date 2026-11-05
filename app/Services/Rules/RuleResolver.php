<?php

namespace App\Services\Rules;

use App\Models\RuleVersion;
use App\Services\OrganizationContext;
use Illuminate\Support\Facades\Cache;

/**
 * The single entry point approvals, procurement, leave, payroll and the portal use to read configurable rules (Guide 3.7.4).
 * Cached per organization and domain; the cache key carries a per-organization generation that is bumped on every activation,
 * so nothing is ever shared across organizations.
 */
class RuleResolver
{
    public function resolve(string $domain, array $attributes, ?string $ruleSetCode = null, ?string $on = null, ?string $organizationId = null): ?ResolvedRule
    {
        $org = $organizationId ?? app(OrganizationContext::class)->organizationId();
        abort_unless($org, 500, 'rule resolution needs an organization');
        $on ??= now()->toDateString();

        $cands = $this->candidates($org, $domain);
        if ($ruleSetCode !== null) {
            $cands = array_values(array_filter($cands, fn ($c) => $c['rule_set'] === $ruleSetCode));
        }
        $hit = RuleMatcher::pick($cands, $attributes, $on);
        return $hit ? new ResolvedRule($hit['id'], $hit['version'], $hit['rule_set'], $hit['outcomes']) : null;
    }

    /** Candidate versions (active or superseded-but-still-in-effect) with conditions and outcomes, as plain arrays. */
    public function candidates(string $org, string $domain): array
    {
        $gen = Cache::get($this->genKey($org), 1);
        return Cache::remember("cops:rules:$org:$gen:$domain", 600, function () use ($org, $domain) {
            return RuleVersion::withoutGlobalScopes()->where('organization_id', $org)
                ->whereHas('status', fn ($q) => $q->whereIn('code', ['active', 'superseded']))
                ->whereHas('ruleSet.domain', fn ($q) => $q->where('code', $domain))
                ->with(['ruleSet', 'conditions.attribute', 'conditions.operator', 'outcomes'])
                ->get()->map(fn (RuleVersion $v) => [
                    'id' => $v->id, 'version' => $v->version, 'rule_set' => $v->ruleSet->code, 'priority' => $v->priority,
                    'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
                    'conditions' => $v->conditions->map(fn ($c) => [
                        'attribute' => $c->attribute->code, 'operator' => $c->operator->code,
                        'text' => $c->value_text, 'decimal' => $c->value_decimal, 'decimal_to' => $c->value_decimal_to,
                    ])->all(),
                    'outcomes' => $v->outcomes->mapWithKeys(fn ($o) => [$o->outcome_key => $o->value()])->all(),
                ])->all();
        });
    }

    public static function flush(string $organizationId): void
    {
        $k = "cops:rules:gen:$organizationId";
        Cache::add($k, 1);
        Cache::increment($k);
    }

    private function genKey(string $org): string { return "cops:rules:gen:$org"; }
}
