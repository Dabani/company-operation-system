<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/** COPS Phase 8e: documents attached to supplier quotes and supplier invoices. Mounted inside the authenticated, organization-scoped group of routes/api.php. */
Route::middleware('perm:procurement.manage')->group(function () {
    Route::get('rfq-quote-files/{id}', [V1\ProcurementFileController::class, 'quoteFiles']);
    Route::post('rfq-quote-files/{id}', [V1\ProcurementFileController::class, 'storeQuoteFile']);
});
Route::middleware('perm:procurement.invoice,procurement.invoice.approve,procurement.invoice.pay')->get('supplier-invoice-files/{id}', [V1\ProcurementFileController::class, 'invoiceFiles']);
Route::middleware('perm:procurement.invoice')->post('supplier-invoice-files/{id}', [V1\ProcurementFileController::class, 'storeInvoiceFile']);
