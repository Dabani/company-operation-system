<?php

namespace App\Services\Procurement;

use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees what in procurement. Buyers (procurement.manage) see every supplier and requisition; everyone else sees approved suppliers (basic facts only)
 * and their own requisitions. Approvers reach a requisition through the approvals inbox, which carries the title and amount.
 */
class ProcurementAccess
{
    public function __construct(private OrganizationContext $ctx) {}

    public function can(string $permission): bool { return $this->ctx->can($permission); }

    public function buyer(): bool { return $this->can('procurement.manage'); }

    public function scopeSuppliers(Builder $q): Builder
    {
        return $this->buyer() ? $q : $q->where('status_id', Lookup::id('supplier_status', 'approved'));
    }

    public function supplier(string $id, array $with = []): Supplier
    {
        $s = $this->scopeSuppliers(Supplier::query())->with($with)->whereKey($id)->first();
        abort_unless($s, 404);
        return $s;
    }

    public function scopeRequisitions(Builder $q, User $u): Builder
    {
        return $this->buyer() ? $q : $q->where('requester_id', $u->id);
    }

    public function requisition(User $u, string $id, array $with = []): PurchaseRequisition
    {
        $r = $this->scopeRequisitions(PurchaseRequisition::query(), $u)->with($with)->whereKey($id)->first();
        abort_unless($r, 404);
        return $r;
    }

    public function ownsOrBuyer(User $u, PurchaseRequisition $r): bool { return $r->requester_id === $u->id || $this->buyer(); }
}
