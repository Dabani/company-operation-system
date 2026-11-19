<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/** COPS Phase 8d: sourcing (requests for quotation). Buyers only. Mounted inside the authenticated, organization-scoped group of routes/api.php. */
Route::middleware('perm:procurement.manage')->group(function () {
    Route::get('rfqs', [V1\RfqController::class, 'index']);
    Route::post('rfqs', [V1\RfqController::class, 'store']);
    Route::get('rfqs/{id}', [V1\RfqController::class, 'show']);
    Route::patch('rfqs/{id}', [V1\RfqController::class, 'update']);
    Route::post('rfqs/{id}/status', [V1\RfqController::class, 'status']);
    Route::post('rfqs/{id}/quotes', [V1\RfqController::class, 'quote']);
    Route::post('rfqs/{id}/award', [V1\RfqController::class, 'award']);
    Route::post('rfqs/{id}/order', [V1\RfqController::class, 'createOrder']);
});
