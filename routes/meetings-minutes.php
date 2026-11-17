<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7c routes (meeting minutes). Required from routes/api.php inside the authenticated "org.context" group, next to meetings.php. */
Route::middleware('perm:meeting.view')->group(function () {
    Route::get('minutes', [V1\MeetingMinutesController::class, 'library']);
    Route::get('meetings/{id}/minutes', [V1\MeetingMinutesController::class, 'forMeeting']);
    Route::post('meetings/{id}/minutes', [V1\MeetingMinutesController::class, 'store']);
    Route::post('meetings/{id}/minutes/revise', [V1\MeetingMinutesController::class, 'revise']);
    Route::get('minutes/{id}', [V1\MeetingMinutesController::class, 'show']);
    Route::patch('minutes/{id}', [V1\MeetingMinutesController::class, 'update']);
    Route::put('minutes/{id}/reviewers', [V1\MeetingMinutesController::class, 'reviewers']);
    Route::post('minutes/{id}/comments', [V1\MeetingMinutesController::class, 'comment']);
    Route::post('minute-comments/{id}/resolve', [V1\MeetingMinutesController::class, 'resolve']);
    Route::post('minutes/{id}/submit', [V1\MeetingMinutesController::class, 'submit']);
    Route::post('minutes/{id}/publish', [V1\MeetingMinutesController::class, 'publish']);
    Route::get('minutes/{id}/pdf', [V1\MeetingMinutesController::class, 'pdf']);
});
