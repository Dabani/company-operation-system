<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 0 routes. Required once from routes/api.php inside the authenticated "org.context" group:
 |     require __DIR__ . '/foundation.php';
 | Portal (external client) routes are registered by routes/portal.php outside that group.
 */

// ---- approvals ---------------------------------------------------------------------------------------------------------
Route::get('approvals/inbox', [V1\ApprovalController::class, 'inbox'])->middleware('perm:approval.act');
Route::get('approvals/mine', [V1\ApprovalController::class, 'mine'])->middleware('perm:approval.view,approval.act');
Route::get('approvals', [V1\ApprovalController::class, 'index'])->middleware('perm:approval.view_all');
Route::get('approvals/{id}', [V1\ApprovalController::class, 'show'])->middleware('perm:approval.view,approval.act,approval.view_all');
Route::post('approvals/{id}/act', [V1\ApprovalController::class, 'act'])->middleware('perm:approval.act');
Route::post('approvals/{id}/resubmit', [V1\ApprovalController::class, 'resubmit'])->middleware('perm:approval.view,approval.act');
Route::post('approvals/{id}/cancel', [V1\ApprovalController::class, 'cancel'])->middleware('perm:approval.view,approval.act');
Route::get('approval-delegations', [V1\ApprovalController::class, 'delegations'])->middleware('perm:approval.act');
Route::post('approval-delegations', [V1\ApprovalController::class, 'storeDelegation'])->middleware('perm:approval.act');
Route::delete('approval-delegations/{id}', [V1\ApprovalController::class, 'destroyDelegation'])->middleware('perm:approval.act');

Route::get('approval-flows', [V1\ApprovalFlowController::class, 'index'])->middleware('perm:approval.configure,approval.view_all');
Route::middleware('perm:approval.configure')->group(function () {
    Route::post('approval-flows', [V1\ApprovalFlowController::class, 'store']);
    Route::post('approval-flows/{id}/activate', [V1\ApprovalFlowController::class, 'activate']);
    Route::delete('approval-flows/{id}', [V1\ApprovalFlowController::class, 'destroy']);
});

// ---- rules and policy engine -------------------------------------------------------------------------------------------
Route::get('rule-sets', [V1\RuleController::class, 'sets'])->middleware('perm:rules.view');
Route::get('rule-versions/{id}', [V1\RuleController::class, 'showVersion'])->middleware('perm:rules.view');
Route::get('legal-parameters', [V1\RuleController::class, 'legal'])->middleware('perm:rules.view');
Route::middleware('perm:rules.configure')->group(function () {
    Route::post('rule-sets', [V1\RuleController::class, 'storeSet']);
    Route::post('rule-sets/{id}/versions', [V1\RuleController::class, 'storeVersion']);
    Route::put('rule-versions/{id}', [V1\RuleController::class, 'updateVersion']);
    Route::get('rule-versions/{id}/validation', [V1\RuleController::class, 'validateVersion']);
    Route::post('rule-versions/{id}/impact', [V1\RuleController::class, 'impact']);
    Route::post('rule-versions/{id}/submit', [V1\RuleController::class, 'submit']);
    Route::post('legal-parameters', [V1\RuleController::class, 'storeLegal']);
});
Route::post('rule-versions/{id}/signoff', [V1\RuleController::class, 'signOff'])->middleware('perm:rules.signoff');
Route::post('legal-parameters/{id}/verify', [V1\RuleController::class, 'verifyLegal'])->middleware('perm:rules.signoff');

// ---- department scope ---------------------------------------------------------------------------------------------------
Route::middleware('perm:scope.manage')->group(function () {
    Route::get('department-access', [V1\DepartmentAccessController::class, 'index']);
    Route::post('department-access', [V1\DepartmentAccessController::class, 'store']);
    Route::delete('department-access/{id}', [V1\DepartmentAccessController::class, 'destroy']);
});

// ---- employee master ---------------------------------------------------------------------------------------------------------
Route::get('employees', [V1\EmployeeController::class, 'index'])->middleware('perm:employee.view');
Route::get('employees/{id}', [V1\EmployeeController::class, 'show'])->middleware('perm:employee.view');
Route::get('employees/{id}/sensitive', [V1\EmployeeController::class, 'sensitive'])->middleware('perm:employee.view_sensitive');
Route::middleware('perm:employee.manage')->group(function () {
    Route::post('employees', [V1\EmployeeController::class, 'store']);
    Route::patch('employees/{id}', [V1\EmployeeController::class, 'update']);
    Route::delete('employees/{id}', [V1\EmployeeController::class, 'destroy']);
    Route::post('employees/{id}/user', [V1\EmployeeController::class, 'linkUser']);
});

// ---- clients ------------------------------------------------------------------------------------------------------------------
Route::get('clients', [V1\ClientController::class, 'index'])->middleware('perm:client.view');
Route::get('clients/{id}', [V1\ClientController::class, 'show'])->middleware('perm:client.view');
Route::middleware('perm:client.manage')->group(function () {
    Route::post('clients', [V1\ClientController::class, 'store']);
    Route::patch('clients/{id}', [V1\ClientController::class, 'update']);
    Route::delete('clients/{id}', [V1\ClientController::class, 'destroy']);
    Route::post('clients/{id}/contacts', [V1\ClientController::class, 'storeContact']);
    Route::delete('clients/{id}/contacts/{contactId}', [V1\ClientController::class, 'destroyContact']);
});
