<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 6b routes (payroll runs and payslips). Required once from routes/api.php next to payroll.php, inside the authenticated "org.context" group.
 | One permission per stage; the service additionally enforces separation of duties and refuses any change to a locked run.
 */
Route::middleware('perm:hr.payroll.view,hr.payroll.prepare,hr.payroll.review,hr.payroll.approve,hr.payroll.approve_md,hr.payroll.lock,hr.payroll.pay,hr.payroll.declare')->group(function () {
    Route::get('payroll/runs', [V1\PayrollRunController::class, 'index']);
    Route::get('payroll/runs/{id}', [V1\PayrollRunController::class, 'show']);
    Route::get('payroll/runs/{id}/payslips', [V1\PayrollRunController::class, 'payslips']);
    Route::get('payroll/payslips/{payslipId}', [V1\PayrollRunController::class, 'payslip']);
    Route::get('payroll/payslips/{payslipId}/pdf', [V1\PayrollRunController::class, 'payslipPdf']);
});

Route::middleware('perm:hr.payroll.prepare')->group(function () {
    Route::post('payroll/runs', [V1\PayrollRunController::class, 'store']);
    Route::post('payroll/runs/{id}/adjustments', [V1\PayrollRunController::class, 'adjustment']);
    Route::post('payroll/runs/{id}/inputs', [V1\PayrollRunController::class, 'addInput']);
    Route::delete('payroll/runs/{id}/inputs/{inputId}', [V1\PayrollRunController::class, 'removeInput']);
    Route::post('payroll/runs/{id}/exclusions', [V1\PayrollRunController::class, 'exclude']);
    Route::delete('payroll/runs/{id}/exclusions/{employeeId}', [V1\PayrollRunController::class, 'include']);
    Route::post('payroll/runs/{id}/calculate', [V1\PayrollRunController::class, 'calculate']);
    Route::post('payroll/runs/{id}/cancel', [V1\PayrollRunController::class, 'cancel']);
});

Route::post('payroll/runs/{id}/review', [V1\PayrollRunController::class, 'review'])->middleware('perm:hr.payroll.review');
Route::post('payroll/runs/{id}/approve-finance', [V1\PayrollRunController::class, 'approveFinance'])->middleware('perm:hr.payroll.approve');
Route::post('payroll/runs/{id}/approve-md', [V1\PayrollRunController::class, 'approveMd'])->middleware('perm:hr.payroll.approve_md');
Route::post('payroll/runs/{id}/return', [V1\PayrollRunController::class, 'returnToDraft'])->middleware('perm:hr.payroll.review,hr.payroll.approve,hr.payroll.approve_md');
Route::post('payroll/runs/{id}/lock', [V1\PayrollRunController::class, 'lock'])->middleware('perm:hr.payroll.lock');
Route::post('payroll/runs/{id}/pay', [V1\PayrollRunController::class, 'pay'])->middleware('perm:hr.payroll.pay');
Route::post('payroll/runs/{id}/declare', [V1\PayrollRunController::class, 'declare'])->middleware('perm:hr.payroll.declare');

// ---- employee self-service: only my own payslips of paid runs --------------------------------------------------------------------------------
Route::middleware('perm:hr.payslip.own')->group(function () {
    Route::get('payroll/me/payslips', [V1\PayslipSelfController::class, 'index']);
    Route::get('payroll/me/payslips/{id}', [V1\PayslipSelfController::class, 'show']);
    Route::get('payroll/me/payslips/{id}/pdf', [V1\PayslipSelfController::class, 'pdf']);
});
