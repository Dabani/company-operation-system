<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7g routes (chat and polls of online meetings, account holders). Required from routes/api.php inside the authenticated "org.context" group. */
Route::middleware('perm:meeting.view')->group(function () {
    Route::get('meetings/{id}/chat', [V1\MeetingCollabController::class, 'chat']);
    Route::post('meetings/{id}/chat', [V1\MeetingCollabController::class, 'post'])->middleware('throttle:60,1');
    Route::delete('meeting-chat/{messageId}', [V1\MeetingCollabController::class, 'remove']);
    Route::get('meetings/{id}/polls', [V1\MeetingCollabController::class, 'polls']);
    Route::post('meetings/{id}/polls', [V1\MeetingCollabController::class, 'createPoll']);
    Route::post('meeting-polls/{pollId}/vote', [V1\MeetingCollabController::class, 'vote']);
    Route::post('meeting-polls/{pollId}/close', [V1\MeetingCollabController::class, 'closePoll']);
});
