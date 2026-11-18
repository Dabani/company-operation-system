<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/** COPS Phase 8b: purchase orders and goods receipts. Mounted inside the authenticated, organization-scoped group of routes/api.php. */
Route::middleware('perm:procurement.manage,procurement.receive')->group(function () {
    Route::get('purchase-orders', [V1\PurchaseOrderController::class, 'index']);
    Route::get('purchase-orders/{id}', [V1\PurchaseOrderController::class, 'show']);
});
Route::middleware('perm:procurement.manage')->group(function () {
    Route::post('purchase-orders', [V1\PurchaseOrderController::class, 'store']);
    Route::patch('purchase-orders/{id}', [V1\PurchaseOrderController::class, 'update']);
    Route::post('purchase-orders/{id}/submit', [V1\PurchaseOrderController::class, 'submit']);
    Route::post('purchase-orders/{id}/issue', [V1\PurchaseOrderController::class, 'issue']);
    Route::post('purchase-orders/{id}/status', [V1\PurchaseOrderController::class, 'status']);
});
Route::middleware('perm:procurement.receive')->post('purchase-orders/{id}/receipts', [V1\PurchaseOrderController::class, 'receive']);
