<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | External client portal. Required from routes/api.php inside Route::prefix('v1'), OUTSIDE the staff "org.context" group:
 |     require __DIR__ . '/portal.php';
 */

Route::prefix('portal')->group(function () {
  Route::post('auth/login', [V1\PortalController::class, 'login'])->middleware('throttle:login');
  Route::middleware(['auth:client', 'client.context', 'throttle:portal'])->group(function () {
    Route::post('auth/logout', [V1\PortalController::class, 'logout']);
    Route::get('me', [V1\PortalController::class, 'me']);
    Route::get('contacts', [V1\PortalController::class, 'contacts']);
    Route::get('contacts/{id}', [V1\PortalController::class, 'contact']);
  });
});
