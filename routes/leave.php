<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 5a routes (leave). Required once from routes/api.php next to attendance.php, inside the authenticated "org.context" group.
 | Every route names its permission; people-scoped data is additionally filtered by Employee::visibleTo (404 outside scope).
 */

// ---- my leave ---------------------------------------------------------------------------------------------------------------------
Route::get('leave/me/summary', [V1\LeaveController::class, 'summary'])->middleware('perm:hr.leave.request');
Route::middleware('perm:hr.leave.request,hr.leave.manage')->group(function () {
    Route::post('leave/requests/preview', [V1\LeaveController::class, 'preview']);
    Route::post('leave/requests', [V1\LeaveController::class, 'store']);
});

// ---- requests (own, or people in my scope) ------------------------------------------------------------------------------------------
Route::middleware('perm:hr.leave.request,hr.leave.view,hr.leave.manage')->group(function () {
    Route::get('leave/requests', [V1\LeaveController::class, 'index']);
    Route::get('leave/requests/{id}', [V1\LeaveController::class, 'show']);
    Route::post('leave/requests/{id}/cancel', [V1\LeaveController::class, 'cancel']);
    Route::get('leave/requests/{id}/files', [V1\LeaveFileController::class, 'index']);
    Route::post('leave/requests/{id}/files', [V1\LeaveFileController::class, 'store']);
    Route::get('leave/balances/{employeeId}/ledger', [V1\LeaveController::class, 'ledger']);
    Route::get('leave/types', [V1\LeaveSetupController::class, 'types']);
    Route::get('leave/periods', [V1\LeaveSetupController::class, 'periods']);
});

// ---- team ---------------------------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.leave.view,hr.leave.manage')->group(function () {
    Route::get('leave/calendar', [V1\LeaveController::class, 'calendar']);
    Route::get('leave/balances', [V1\LeaveController::class, 'balancesList']);
});

// ---- HR -----------------------------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.leave.manage')->group(function () {
    Route::post('leave/adjustments', [V1\LeaveController::class, 'adjust']);
    Route::patch('leave/types/{id}', [V1\LeaveSetupController::class, 'updateType']);
    Route::post('leave/periods', [V1\LeaveSetupController::class, 'storePeriod']);
    Route::put('leave/periods/{id}', [V1\LeaveSetupController::class, 'updatePeriod']);
    Route::delete('leave/periods/{id}', [V1\LeaveSetupController::class, 'destroyPeriod']);
});
Route::middleware('perm:hr.leave.profile')->group(function () {
    Route::get('leave/profiles/{employeeId}', [V1\LeaveSetupController::class, 'profile']);
    Route::put('leave/profiles/{employeeId}', [V1\LeaveSetupController::class, 'saveProfile']);
});

// ---- statutory rules, policy preview and audit ---------------------------------------------------------------------------------------
Route::middleware('perm:hr.leave.legal.manage,hr.leave.manage,rules.view')->group(function () {
    Route::get('leave/legal-rules', [V1\LeaveSetupController::class, 'legalRules']);
    Route::get('leave/policy/keys', [V1\LeaveSetupController::class, 'policyKeys']);
    Route::get('leave/policy/preview', [V1\LeaveSetupController::class, 'policyPreview']);
    Route::get('leave/policy/violations', [V1\LeaveSetupController::class, 'policyViolations']);
    Route::get('leave/setup-status', [V1\LeaveSetupController::class, 'status']);
});
Route::middleware('perm:hr.leave.legal.manage')->group(function () {
    Route::post('leave/legal-rules', [V1\LeaveSetupController::class, 'storeLegalRule']);
    Route::post('leave/legal-rules/{id}/end', [V1\LeaveSetupController::class, 'endLegalRule']);
});
Route::post('leave/legal-rules/{id}/verify', [V1\LeaveSetupController::class, 'verifyLegalRule'])->middleware('perm:rules.signoff');
