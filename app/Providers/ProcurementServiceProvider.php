<?php

namespace App\Providers;

use App\Services\Procurement\OrderApprovalHandler;
use App\Services\Procurement\RequisitionApprovalHandler;
use App\Services\Procurement\SupplierApprovalHandler;
use Illuminate\Support\ServiceProvider;

/** COPS Phase 8 wiring: approval outcome handlers for the subjects "supplier", "purchase_requisition" and "purchase_order". */
class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config([
            'cops.approval_handlers' => array_merge(config('cops.approval_handlers', []), ['supplier' => SupplierApprovalHandler::class, 'purchase_requisition' => RequisitionApprovalHandler::class, 'purchase_order' => OrderApprovalHandler::class]),
        ]);
    }
}
