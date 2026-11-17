<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7e: registration and ticket pages for people without an account. Public (the personal links are the credentials) and rate limited. Required from routes/api.php next to meetings-public.php. */
Route::middleware('throttle:30,1')->group(function () {
    Route::get('public/event/{token}', [V1\EventPublicController::class, 'show']);
    Route::post('public/event/{token}/register', [V1\EventPublicController::class, 'register']);
    Route::get('public/event-ticket/{ticket}', [V1\EventPublicController::class, 'ticketShow']);
    Route::delete('public/event-ticket/{ticket}', [V1\EventPublicController::class, 'ticketCancel']);
});
