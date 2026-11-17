<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7a: the reply page for external guests. Public (the personal link is the credential) and rate limited. Required from routes/api.php next to portal.php. */
Route::middleware('throttle:30,1')->group(function () {
    Route::get('public/meeting-rsvp/{token}', [V1\MeetingPublicController::class, 'show']);
    Route::post('public/meeting-rsvp/{token}', [V1\MeetingPublicController::class, 'respond']);
    Route::get('public/meeting-rsvp/{token}/ics', [V1\MeetingPublicController::class, 'ics']);
});
