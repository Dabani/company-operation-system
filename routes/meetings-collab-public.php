<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7g: chat and polls for external guests. Public (the personal link is the credential) and rate limited. Required from routes/api.php next to meetings-online-public.php. */
Route::middleware('throttle:90,1')->group(function () {
    Route::get('public/meeting-online/{token}/chat', [V1\MeetingCollabPublicController::class, 'chat']);
    Route::post('public/meeting-online/{token}/chat', [V1\MeetingCollabPublicController::class, 'post']);
    Route::get('public/meeting-online/{token}/polls', [V1\MeetingCollabPublicController::class, 'polls']);
    Route::post('public/meeting-online/{token}/polls/{pollId}/vote', [V1\MeetingCollabPublicController::class, 'vote']);
});
