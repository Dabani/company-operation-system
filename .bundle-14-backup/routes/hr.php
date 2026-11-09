<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 4a routes (HR core: structure, employment history, career, reviews, self-service).
 | Required once from routes/api.php inside the authenticated "org.context" group:   require __DIR__ . '/hr.php';
 */

// ---- position / grade framework ----------------------------------------------------------------------------------------
Route::get('hr/grades', [V1\HrStructureController::class, 'grades'])->middleware('perm:employee.view,hr.structure.manage');
Route::get('hr/positions', [V1\HrStructureController::class, 'positions'])->middleware('perm:employee.view,hr.structure.manage');
Route::get('hr/progression-criteria', [V1\HrStructureController::class, 'criteria'])->middleware('perm:employee.view,hr.structure.manage');
Route::middleware('perm:hr.structure.manage')->group(function () {
    Route::post('hr/grades', [V1\HrStructureController::class, 'storeGrade']);
    Route::patch('hr/grades/{id}', [V1\HrStructureController::class, 'updateGrade']);
    Route::post('hr/positions', [V1\HrStructureController::class, 'storePosition']);
    Route::patch('hr/positions/{id}', [V1\HrStructureController::class, 'updatePosition']);
    Route::post('hr/progression-criteria', [V1\HrStructureController::class, 'storeCriterion']);
    Route::delete('hr/progression-criteria/{id}', [V1\HrStructureController::class, 'destroyCriterion']);
});

// ---- employment history and dated changes -------------------------------------------------------------------------------
Route::middleware('perm:hr.history.view,hr.history.manage')->group(function () {
    Route::get('employees/{id}/history', [V1\EmploymentController::class, 'history']);
    Route::get('employees/{id}/as-of', [V1\EmploymentController::class, 'asOf']);
    Route::get('employees/{id}/career', [V1\EmploymentController::class, 'career']);
});
Route::middleware('perm:hr.history.manage')->group(function () {
    Route::post('employees/{id}/employment-changes', [V1\EmploymentController::class, 'propose']);
    Route::get('employment-changes', [V1\EmploymentController::class, 'changes']);
    Route::post('employment-changes/{id}/cancel', [V1\EmploymentController::class, 'cancelChange']);
});

// ---- performance reviews --------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.reviews.manage')->group(function () {
    Route::get('review-cycles', [V1\ReviewController::class, 'cycles']);
    Route::post('review-cycles', [V1\ReviewController::class, 'storeCycle']);
    Route::post('review-cycles/{id}/close', [V1\ReviewController::class, 'closeCycle']);
    Route::post('review-cycles/{id}/reviews', [V1\ReviewController::class, 'assign']);
});
Route::middleware('perm:hr.reviews.manage,hr.reviews.write,hr.self.view')->group(function () {
    Route::get('reviews', [V1\ReviewController::class, 'index']);
    Route::get('reviews/{id}', [V1\ReviewController::class, 'show']);
});
Route::middleware('perm:hr.reviews.write')->group(function () {
    Route::patch('reviews/{id}', [V1\ReviewController::class, 'update']);
    Route::post('reviews/{id}/submit', [V1\ReviewController::class, 'submit']);
});
Route::post('reviews/{id}/acknowledge', [V1\ReviewController::class, 'acknowledge'])->middleware('perm:hr.self.view');

// ---- self-service ---------------------------------------------------------------------------------------------------------
Route::get('my/employment', [V1\EmploymentController::class, 'mine'])->middleware('perm:hr.self.view');
