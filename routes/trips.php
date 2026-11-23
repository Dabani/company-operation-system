<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 5b routes (business trips and expenses). Required once from routes/api.php next to leave.php, inside the authenticated "org.context" group.
 | Static paths (rates, unretired-advances) come before trips/{id}. People-scoped data is filtered per trip (404 outside scope); finance sees all trips.
 */

// ---- finance ------------------------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.trip.finance')->group(function () {
    Route::get('trips/unretired-advances', [V1\TripController::class, 'unretired']);
    Route::post('trips/{id}/advances/{advanceId}/pay', [V1\TripController::class, 'payAdvance']);
    Route::post('trips/expenses/{expenseId}/review', [V1\TripController::class, 'review']);
    Route::get('trips/{id}/settlement-preview', [V1\TripController::class, 'settlementPreview']);
    Route::post('trips/{id}/settle', [V1\TripController::class, 'settle']);
});

// ---- rates -----------------------------------------------------------------------------------------------------------------------------
Route::get('trips/rates', [V1\TripSetupController::class, 'rates'])->middleware('perm:hr.trip.configure,hr.trip.finance');
Route::middleware('perm:hr.trip.configure')->group(function () {
    Route::post('trips/rates', [V1\TripSetupController::class, 'storeRate']);
    Route::post('trips/rates/{id}/end', [V1\TripSetupController::class, 'endRate']);
});

// ---- requesting, expenses, report -----------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.trip.request,hr.trip.finance')->group(function () {
    Route::post('trips', [V1\TripController::class, 'store']);
    Route::post('trips/estimate', [V1\TripController::class, 'estimate']);
    Route::post('trips/{id}/cancel', [V1\TripController::class, 'cancel']);
    Route::post('trips/{id}/bookings', [V1\TripController::class, 'addBooking']);
    Route::delete('trips/{id}/bookings/{bookingId}', [V1\TripController::class, 'deleteBooking']);
    Route::post('trips/{id}/per-diem', [V1\TripController::class, 'perDiem']);
    Route::post('trips/{id}/expenses', [V1\TripController::class, 'addExpense']);
    Route::patch('trips/expenses/{expenseId}', [V1\TripController::class, 'updateExpense']);
    Route::delete('trips/expenses/{expenseId}', [V1\TripController::class, 'deleteExpense']);
    Route::post('trips/expenses/{expenseId}/files', [V1\TripController::class, 'uploadReceipt']);
    Route::post('trips/{id}/report', [V1\TripController::class, 'submitReport']);
});

// ---- reading ---------------------------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.trip.request,hr.trip.view,hr.trip.finance')->group(function () {
    Route::get('trips', [V1\TripController::class, 'index']);
    Route::get('trips/expenses/{expenseId}/files', [V1\TripController::class, 'expenseFiles']);
    Route::get('trips/{id}', [V1\TripController::class, 'show']);
    Route::get('trips/{id}/authorization', [V1\TripController::class, 'authorization']);
});
