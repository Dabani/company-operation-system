<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7f routes (online meetings, account holders). Required from routes/api.php inside the authenticated "org.context" group, next to meetings.php. */
Route::middleware('perm:meeting.view')->group(function () {
    Route::get('meetings/{id}/online', [V1\MeetingOnlineController::class, 'show']);
    Route::post('meetings/{id}/online/enable', [V1\MeetingOnlineController::class, 'enable']);
    Route::post('meetings/{id}/online/disable', [V1\MeetingOnlineController::class, 'disable']);
    Route::post('meetings/{id}/online/join', [V1\MeetingOnlineController::class, 'join'])->middleware('throttle:60,1');
    Route::post('meetings/{id}/online/leave', [V1\MeetingOnlineController::class, 'leave']);
    Route::post('meetings/{id}/interventions', [V1\MeetingOnlineController::class, 'request'])->middleware('throttle:30,1');
    Route::post('meeting-interventions/{interventionId}/grant', [V1\MeetingOnlineController::class, 'grant']);
    Route::post('meeting-interventions/{interventionId}/finish', [V1\MeetingOnlineController::class, 'finish']);
    Route::post('meeting-interventions/{interventionId}/dismiss', [V1\MeetingOnlineController::class, 'dismiss']);
    Route::post('meeting-interventions/{interventionId}/withdraw', [V1\MeetingOnlineController::class, 'withdraw']);
});
