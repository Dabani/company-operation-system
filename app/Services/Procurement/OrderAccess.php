<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Builder;

/** Buyers see every purchase order; a requester sees the orders raised for their own requisitions (to follow delivery and confirm receipt), once the order is issued. */
class OrderAccess
{
    public function __construct(private ProcurementAccess $access) {}

    public function scope(Builder $q, User $u): Builder
    {
        if ($this->access->buyer()) {
            return $q;
        }
        $ids = array_map(fn ($c) => Lookup::id('po_status', $c), ['issued', 'partially_received', 'received', 'closed']);   // drafts and approvals stay with the buyers
        return $q->whereIn('status_id', $ids)->whereHas('requisition', fn ($r) => $r->where('requester_id', $u->id));
    }

    public function find(User $u, string $id, array $with = []): PurchaseOrder
    {
        $o = $this->scope(PurchaseOrder::query(), $u)->with($with)->whereKey($id)->first();
        abort_unless($o, 404);
        return $o;
    }
}
