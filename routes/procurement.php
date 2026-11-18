<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/** COPS Phase 8a: suppliers and purchase requisitions. Mounted inside the authenticated, organization-scoped group of routes/api.php. */
Route::middleware('perm:procurement.view')->group(function () {
    Route::get('suppliers', [V1\SupplierController::class, 'index']);
    Route::get('suppliers/{id}', [V1\SupplierController::class, 'show']);
});
Route::middleware('perm:procurement.manage')->group(function () {
    Route::post('suppliers', [V1\SupplierController::class, 'store']);
    Route::patch('suppliers/{id}', [V1\SupplierController::class, 'update']);
    Route::post('suppliers/{id}/submit', [V1\SupplierController::class, 'submit']);
    Route::post('suppliers/{id}/status', [V1\SupplierController::class, 'status']);
    Route::post('suppliers/{id}/bank-accounts', [V1\SupplierController::class, 'proposeBank']);
});
Route::middleware('perm:procurement.supplier.bank')->group(function () {
    Route::post('supplier-bank-accounts/{id}/verify', [V1\SupplierController::class, 'verifyBank']);
    Route::post('supplier-bank-accounts/{id}/reveal', [V1\SupplierController::class, 'revealBank']);
});

Route::middleware('perm:procurement.requisition.create,procurement.manage')->group(function () {
    Route::get('purchase-requisitions', [V1\PurchaseRequisitionController::class, 'index']);
    Route::get('purchase-requisitions/{id}', [V1\PurchaseRequisitionController::class, 'show']);
});
Route::middleware('perm:procurement.requisition.create')->group(function () {
    Route::post('purchase-requisitions', [V1\PurchaseRequisitionController::class, 'store']);
    Route::patch('purchase-requisitions/{id}', [V1\PurchaseRequisitionController::class, 'update']);
    Route::delete('purchase-requisitions/{id}', [V1\PurchaseRequisitionController::class, 'destroy']);
    Route::post('purchase-requisitions/{id}/submit', [V1\PurchaseRequisitionController::class, 'submit']);
});
Route::middleware('perm:procurement.requisition.create,procurement.manage')->post('purchase-requisitions/{id}/status', [V1\PurchaseRequisitionController::class, 'status']);
