<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7f: online meeting for external guests. Public (the personal link is the credential) and rate limited. Required from routes/api.php next to meetings-public.php. */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('public/meeting-online/{token}', [V1\MeetingOnlinePublicController::class, 'show']);
    Route::post('public/meeting-online/{token}/join', [V1\MeetingOnlinePublicController::class, 'join']);
    Route::post('public/meeting-online/{token}/leave', [V1\MeetingOnlinePublicController::class, 'leave']);
    Route::post('public/meeting-online/{token}/interventions', [V1\MeetingOnlinePublicController::class, 'request']);
    Route::post('public/meeting-online/{token}/interventions/{interventionId}/withdraw', [V1\MeetingOnlinePublicController::class, 'withdraw']);
});
