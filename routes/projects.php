<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 1 routes. Required from routes/api.php inside the authenticated "org.context" group (apply-bundle-09.py does it). */
Route::get('projects', [V1\ProjectController::class, 'index'])->middleware('perm:project.view');
Route::post('projects', [V1\ProjectController::class, 'store'])->middleware('perm:project.create');
Route::get('projects/{id}', [V1\ProjectController::class, 'show'])->middleware('perm:project.view');
Route::patch('projects/{id}', [V1\ProjectController::class, 'update'])->middleware('perm:project.view');
Route::delete('projects/{id}', [V1\ProjectController::class, 'destroy'])->middleware('perm:project.manage_all');

Route::middleware('perm:project.view')->group(function () {
    Route::post('projects/{id}/members', [V1\ProjectController::class, 'storeMember']);
    Route::delete('projects/{id}/members/{userId}', [V1\ProjectController::class, 'destroyMember']);
    Route::post('projects/{id}/milestones', [V1\ProjectController::class, 'storeMilestone']);
    Route::patch('projects/{id}/milestones/{milestoneId}', [V1\ProjectController::class, 'updateMilestone']);
    Route::delete('projects/{id}/milestones/{milestoneId}', [V1\ProjectController::class, 'destroyMilestone']);

    Route::get('projects/{id}/tasks', [V1\TaskController::class, 'board']);
    Route::post('projects/{id}/tasks', [V1\TaskController::class, 'store']);
    Route::get('my-tasks', [V1\TaskController::class, 'mine']);
    Route::get('tasks/{id}', [V1\TaskController::class, 'show']);
    Route::patch('tasks/{id}', [V1\TaskController::class, 'update']);
    Route::post('tasks/{id}/move', [V1\TaskController::class, 'move']);
    Route::delete('tasks/{id}', [V1\TaskController::class, 'destroy']);
    Route::post('tasks/{id}/dependencies', [V1\TaskController::class, 'addDependency']);
    Route::delete('tasks/{id}/dependencies/{dependsOn}', [V1\TaskController::class, 'removeDependency']);
    Route::post('tasks/{id}/comments', [V1\TaskController::class, 'comment']);
    Route::delete('tasks/{id}/comments/{commentId}', [V1\TaskController::class, 'deleteComment']);

    Route::get('{kind}-files/{id}', [V1\ProjectFileController::class, 'index'])->whereIn('kind', ['project', 'task']);
    Route::post('{kind}-files/{id}', [V1\ProjectFileController::class, 'store'])->whereIn('kind', ['project', 'task']);
});
