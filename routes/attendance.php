<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 4c routes (attendance and working time). Required once from routes/api.php next to hr.php and recruitment.php,
 | inside the authenticated "org.context" group.
 */

// ---- my attendance -------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.attendance.clock')->group(function () {
    Route::get('attendance/me/today', [V1\AttendanceController::class, 'today']);
    Route::post('attendance/me/clock', [V1\AttendanceController::class, 'clock']);
    Route::get('attendance/me/days', [V1\AttendanceController::class, 'myDays']);
});

// ---- corrections ---------------------------------------------------------------------------------------------------------
Route::get('attendance/corrections', [V1\AttendanceController::class, 'corrections'])->middleware('perm:hr.attendance.clock,hr.attendance.correct,hr.attendance.view,hr.attendance.manage');
Route::middleware('perm:hr.attendance.clock,hr.attendance.correct')->group(function () {
    Route::post('attendance/corrections', [V1\AttendanceController::class, 'storeCorrection']);
    Route::post('attendance/corrections/{id}/cancel', [V1\AttendanceController::class, 'cancelCorrection']);
});

// ---- people in my scope ------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.attendance.view,hr.attendance.manage')->group(function () {
    Route::get('attendance/days', [V1\AttendanceController::class, 'days']);
    Route::get('attendance/summary', [V1\AttendanceController::class, 'summary']);
    Route::get('attendance/periods', [V1\AttendanceAdminController::class, 'periods']);
    Route::get('attendance/shifts', [V1\AttendanceSetupController::class, 'shifts']);
    Route::get('attendance/schedules', [V1\AttendanceSetupController::class, 'schedules']);
    Route::get('attendance/schedule-assignments', [V1\AttendanceSetupController::class, 'assignments']);
    Route::get('attendance/overrides', [V1\AttendanceSetupController::class, 'overrides']);
    Route::get('attendance/networks', [V1\AttendanceSetupController::class, 'networks']);
});
Route::get('attendance/holidays', [V1\AttendanceSetupController::class, 'holidays'])->middleware('perm:hr.attendance.clock,hr.attendance.view,hr.attendance.manage');

// ---- HR setup and operations ----------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.attendance.manage')->group(function () {
    Route::post('attendance/shifts', [V1\AttendanceSetupController::class, 'storeShift']);
    Route::patch('attendance/shifts/{id}', [V1\AttendanceSetupController::class, 'updateShift']);
    Route::post('attendance/schedules', [V1\AttendanceSetupController::class, 'storeSchedule']);
    Route::patch('attendance/schedules/{id}', [V1\AttendanceSetupController::class, 'updateSchedule']);
    Route::post('attendance/schedule-assignments', [V1\AttendanceSetupController::class, 'assign']);
    Route::post('attendance/overrides', [V1\AttendanceSetupController::class, 'setOverride']);
    Route::delete('attendance/overrides/{id}', [V1\AttendanceSetupController::class, 'deleteOverride']);
    Route::post('attendance/holidays', [V1\AttendanceSetupController::class, 'storeHoliday']);
    Route::delete('attendance/holidays/{id}', [V1\AttendanceSetupController::class, 'deleteHoliday']);
    Route::post('attendance/networks', [V1\AttendanceSetupController::class, 'storeNetwork']);
    Route::patch('attendance/networks/{id}', [V1\AttendanceSetupController::class, 'updateNetwork']);
    Route::delete('attendance/networks/{id}', [V1\AttendanceSetupController::class, 'deleteNetwork']);
    Route::post('attendance/import', [V1\AttendanceAdminController::class, 'import']);
    Route::get('attendance/imports', [V1\AttendanceAdminController::class, 'imports']);
    Route::post('attendance/recompute', [V1\AttendanceAdminController::class, 'recompute']);
});
Route::post('attendance/periods/{month}/validate', [V1\AttendanceAdminController::class, 'validateMonth'])->middleware('perm:hr.attendance.validate');
Route::post('attendance/periods/{month}/reopen', [V1\AttendanceAdminController::class, 'reopenMonth'])->middleware('perm:hr.attendance.reopen');
