<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 2b routes - required inside the authenticated "org.context" group by apply-bundle-11.py. */
Route::get('invoices', [V1\InvoiceController::class, 'index'])->middleware('perm:billing.view');
Route::get('invoices/{id}', [V1\InvoiceController::class, 'show'])->middleware('perm:billing.view');
Route::get('invoices/{id}/pdf', [V1\InvoiceController::class, 'pdf'])->middleware('perm:billing.view');
Route::middleware('perm:billing.manage')->group(function () {
    Route::post('projects/{id}/invoices/generate', [V1\InvoiceController::class, 'generate']);
    Route::patch('invoices/{id}', [V1\InvoiceController::class, 'update']);
    Route::post('invoices/{id}/lines', [V1\InvoiceController::class, 'addLine']);
    Route::delete('invoices/{id}/lines/{lineId}', [V1\InvoiceController::class, 'removeLine']);
    Route::post('invoices/{id}/cancel', [V1\InvoiceController::class, 'cancel']);
});
Route::post('invoices/{id}/issue', [V1\InvoiceController::class, 'issue'])->middleware('perm:billing.issue');
Route::middleware('perm:billing.payments')->group(function () {
    Route::post('invoices/{id}/payments', [V1\InvoiceController::class, 'pay']);
    Route::post('invoices/{id}/payments/{paymentId}/reverse', [V1\InvoiceController::class, 'reversePayment']);
});
Route::post('invoices/{id}/credit-notes', [V1\InvoiceController::class, 'credit'])->middleware('perm:billing.credit');

Route::get('tax-rates', [V1\BillingAdminController::class, 'taxRates'])->middleware('perm:billing.view');
Route::post('tax-rates', [V1\BillingAdminController::class, 'storeTaxRate'])->middleware('perm:billing.configure');
Route::post('tax-rates/{id}/verify', [V1\BillingAdminController::class, 'verifyTaxRate'])->middleware('perm:rules.signoff');
Route::get('projects/{id}/billing', [V1\BillingAdminController::class, 'billing'])->middleware('perm:project.view');
Route::put('projects/{id}/billing', [V1\BillingAdminController::class, 'setBilling'])->middleware('perm:billing.configure');
Route::patch('projects/{id}/milestones/{milestoneId}/billing', [V1\BillingAdminController::class, 'setMilestoneAmount'])->middleware('perm:billing.configure');
Route::post('projects/{id}/deliver', [V1\BillingAdminController::class, 'deliver'])->middleware('perm:project.view');
Route::post('projects/{id}/close', [V1\BillingAdminController::class, 'close'])->middleware('perm:project.view');
