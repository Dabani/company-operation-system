<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 2a routes - required inside the authenticated "org.context" group by apply-bundle-10.py. */
Route::middleware('perm:time.track')->group(function () {
    Route::get('timer', [V1\TimeController::class, 'timer']);
    Route::post('timer/start', [V1\TimeController::class, 'start']);
    Route::post('timer/pause', [V1\TimeController::class, 'pause']);
    Route::post('timer/resume', [V1\TimeController::class, 'resume']);
    Route::post('timer/stop', [V1\TimeController::class, 'stop']);
    Route::get('time-week', [V1\TimeController::class, 'week']);
    Route::post('time-entries', [V1\TimeController::class, 'store']);
    Route::patch('time-entries/{id}', [V1\TimeController::class, 'update']);
    Route::delete('time-entries/{id}', [V1\TimeController::class, 'destroy']);
    Route::post('timesheets/{week}/submit', [V1\TimeController::class, 'submit']);
});
Route::post('time-entries/{id}/adjust', [V1\TimeController::class, 'adjust'])->middleware('perm:time.adjust');
Route::get('timesheets', [V1\TimeController::class, 'team'])->middleware('perm:time.view_team');
Route::get('projects/{id}/time-summary', [V1\TimeController::class, 'projectSummary'])->middleware('perm:project.view');
Route::patch('tasks/{id}/billable', [V1\TimeController::class, 'setBillable'])->middleware('perm:project.view');

Route::get('rate-cards', [V1\RateCardController::class, 'index'])->middleware('perm:rates.view');
Route::middleware('perm:rates.manage')->group(function () {
    Route::post('rate-cards', [V1\RateCardController::class, 'store']);
    Route::post('rate-cards/{id}/lines', [V1\RateCardController::class, 'storeLine']);
    Route::patch('rate-cards/{id}/lines/{lineId}/end', [V1\RateCardController::class, 'endLine']);
    Route::put('projects/{id}/rate-card', [V1\RateCardController::class, 'setProjectCard']);
});
