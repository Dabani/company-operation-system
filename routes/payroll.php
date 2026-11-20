<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 6a routes (payroll configuration and dry-run). Required once from routes/api.php next to trips.php, inside the authenticated "org.context" group.
 | hr.payroll.view reads, hr.payroll.configure prepares, hr.payroll.verify is the second person, hr.payroll.prepare runs the dry-run.
 */
Route::middleware('perm:hr.payroll.view,hr.payroll.configure,hr.payroll.verify,hr.payroll.prepare')->group(function () {
    Route::get('payroll/components', [V1\PayrollSetupController::class, 'components']);
    Route::get('payroll/schemes', [V1\PayrollSetupController::class, 'schemes']);
    Route::get('payroll/assignments', [V1\PayrollSetupController::class, 'assignments']);
    Route::get('payroll/items', [V1\PayrollSetupController::class, 'items']);
    Route::get('payroll/setup-status', [V1\PayrollSetupController::class, 'status']);
});

Route::middleware('perm:hr.payroll.configure')->group(function () {
    Route::post('payroll/components', [V1\PayrollSetupController::class, 'storeComponent']);
    Route::patch('payroll/components/{id}', [V1\PayrollSetupController::class, 'updateComponent']);
    Route::post('payroll/schemes', [V1\PayrollSetupController::class, 'storeScheme']);
    Route::patch('payroll/schemes/{id}', [V1\PayrollSetupController::class, 'updateScheme']);
    Route::post('payroll/schemes/{id}/versions', [V1\PayrollSetupController::class, 'storeVersion']);
    Route::post('payroll/scheme-versions/{id}/end', [V1\PayrollSetupController::class, 'endVersion']);
    Route::delete('payroll/scheme-versions/{id}', [V1\PayrollSetupController::class, 'destroyVersion']);
    Route::post('payroll/assignments', [V1\PayrollSetupController::class, 'storeAssignment']);
    Route::post('payroll/assignments/{id}/end', [V1\PayrollSetupController::class, 'endAssignment']);
    Route::delete('payroll/assignments/{id}', [V1\PayrollSetupController::class, 'destroyAssignment']);
    Route::post('payroll/items', [V1\PayrollSetupController::class, 'storeItem']);
    Route::post('payroll/items/{id}/end', [V1\PayrollSetupController::class, 'endItem']);
    Route::delete('payroll/items/{id}', [V1\PayrollSetupController::class, 'destroyItem']);
});

Route::middleware('perm:hr.payroll.verify')->group(function () {
    Route::post('payroll/scheme-versions/{id}/verify', [V1\PayrollSetupController::class, 'verifyVersion']);
    Route::post('payroll/assignments/{id}/verify', [V1\PayrollSetupController::class, 'verifyAssignment']);
    Route::post('payroll/items/{id}/verify', [V1\PayrollSetupController::class, 'verifyItem']);
});

Route::post('payroll/dry-run', [V1\PayrollSetupController::class, 'dryRun'])->middleware('perm:hr.payroll.prepare');
