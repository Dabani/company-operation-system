<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/** COPS Phase 8c: supplier invoices, three-way match, approval and payment handoff. Mounted inside the authenticated, organization-scoped group of routes/api.php. */
Route::middleware('perm:procurement.invoice,procurement.invoice.approve,procurement.invoice.pay')->group(function () {
    Route::get('supplier-invoices', [V1\SupplierInvoiceController::class, 'index']);
    Route::get('supplier-invoices/{id}', [V1\SupplierInvoiceController::class, 'show']);
});
Route::middleware('perm:procurement.invoice')->group(function () {
    Route::post('supplier-invoices', [V1\SupplierInvoiceController::class, 'store']);
    Route::post('supplier-invoices/{id}/rematch', [V1\SupplierInvoiceController::class, 'rematch']);
    Route::post('supplier-invoices/{id}/cancel', [V1\SupplierInvoiceController::class, 'cancel']);
});
Route::middleware('perm:procurement.invoice.approve')->group(function () {
    Route::post('supplier-invoices/{id}/exception', [V1\SupplierInvoiceController::class, 'exception']);
    Route::post('supplier-invoices/{id}/reject', [V1\SupplierInvoiceController::class, 'reject']);
    Route::post('supplier-invoices/{id}/approve', [V1\SupplierInvoiceController::class, 'approve']);
});
Route::middleware('perm:procurement.invoice.pay')->post('supplier-invoices/{id}/pay', [V1\SupplierInvoiceController::class, 'pay']);
