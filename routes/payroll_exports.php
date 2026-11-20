<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 6c routes (payroll exports: bank file, declarations, journal). Required once from routes/api.php next to payroll_runs.php, inside the authenticated "org.context" group.
 | hr.payroll.export.configure defines layouts and journal accounts, hr.payroll.verify is the second person who verifies a layout, hr.payroll.export generates and downloads.
 */
Route::middleware('perm:hr.payroll.export,hr.payroll.export.configure')->group(function () {
    Route::get('payroll/export/fields', [V1\PayrollExportController::class, 'fields']);
    Route::get('payroll/export-profiles', [V1\PayrollExportController::class, 'profiles']);
    Route::get('payroll/export-profiles/{id}/preview', [V1\PayrollExportController::class, 'preview']);
    Route::get('payroll/journal-accounts', [V1\PayrollExportController::class, 'journalAccounts']);
    Route::get('payroll/runs/{id}/journal-accounts', [V1\PayrollExportController::class, 'requiredAccounts']);
    Route::get('payroll/runs/{id}/exports', [V1\PayrollExportController::class, 'exports']);
});

Route::middleware('perm:hr.payroll.export.configure')->group(function () {
    Route::post('payroll/export-profiles', [V1\PayrollExportController::class, 'storeProfile']);
    Route::patch('payroll/export-profiles/{id}/active', [V1\PayrollExportController::class, 'setActive']);
    Route::delete('payroll/export-profiles/{id}', [V1\PayrollExportController::class, 'destroyProfile']);
    Route::put('payroll/journal-accounts', [V1\PayrollExportController::class, 'saveJournalAccounts']);
});

Route::post('payroll/export-profiles/{id}/verify', [V1\PayrollExportController::class, 'verifyProfile'])->middleware('perm:hr.payroll.verify');

Route::middleware('perm:hr.payroll.export')->group(function () {
    Route::post('payroll/runs/{id}/exports', [V1\PayrollExportController::class, 'generate']);
    Route::get('payroll/exports/{exportId}/download', [V1\PayrollExportController::class, 'download']);
});
