<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7e routes (events). Required from routes/api.php inside the authenticated "org.context" group. */
Route::middleware('perm:event.view')->group(function () {
    Route::get('events', [V1\EventController::class, 'index']);
    Route::get('events/people', [V1\EventController::class, 'people'])->middleware('perm:event.create');
    Route::get('events/{id}', [V1\EventController::class, 'show']);
    Route::post('events/{id}/register', [V1\EventController::class, 'register']);
    Route::delete('events/{id}/register', [V1\EventController::class, 'cancelMine']);

    Route::post('events/{id}/status', [V1\EventController::class, 'status']);
    Route::patch('events/{id}', [V1\EventController::class, 'update']);
    Route::post('events/{id}/link/rotate', [V1\EventController::class, 'rotateLink']);
    Route::get('events/{id}/registrations', [V1\EventController::class, 'registrations']);
    Route::get('events/{id}/registrations/export', [V1\EventController::class, 'export']);
    Route::post('events/{id}/registrations', [V1\EventController::class, 'addRegistrations']);
    Route::post('events/{id}/scan', [V1\EventController::class, 'scan']);
    Route::delete('event-registrations/{registrationId}', [V1\EventController::class, 'cancelRegistration']);
    Route::post('event-registrations/{registrationId}/check-in', [V1\EventController::class, 'checkIn']);
    Route::delete('event-registrations/{registrationId}/check-in', [V1\EventController::class, 'undoCheckIn']);
});
Route::post('events', [V1\EventController::class, 'store'])->middleware('perm:event.create');
