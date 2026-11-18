<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7e routes (event expenses). Required from routes/api.php inside the authenticated "org.context" group, next to events.php. */
Route::middleware('perm:event.view')->group(function () {
    Route::get('events/{id}/expenses/export', [V1\EventExpenseController::class, 'export']);
    Route::get('events/{id}/expenses', [V1\EventExpenseController::class, 'index']);
    Route::post('events/{id}/expenses', [V1\EventExpenseController::class, 'store']);
    Route::patch('event-expenses/{id}', [V1\EventExpenseController::class, 'update']);
    Route::delete('event-expenses/{id}', [V1\EventExpenseController::class, 'destroy']);
    Route::post('event-expenses/{id}/submit', [V1\EventExpenseController::class, 'submit']);
    Route::get('event-expense-files/{id}', [V1\EventExpenseController::class, 'files']);
    Route::post('event-expense-files/{id}', [V1\EventExpenseController::class, 'storeFile']);
});
