<?php

namespace App\Support;

use App\Services\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant scoping: every query is limited to the active organization once the
 * OrganizationContext has been resolved (HTTP requests). Never trust client-supplied organization_id.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $q) {
            $ctx = app(OrganizationContext::class);
            if ($ctx->resolved()) {
                $q->where($q->getModel()->getTable() . '.organization_id', $ctx->organizationId());
            }
        });

        static::creating(function (Model $m) {
            $ctx = app(OrganizationContext::class);
            if ($ctx->resolved() && empty($m->organization_id)) {
                $m->organization_id = $ctx->organizationId();
            }
        });
    }
}
