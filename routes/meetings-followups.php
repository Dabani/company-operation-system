<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7d routes (resolutions and action items). Required from routes/api.php inside the authenticated "org.context" group. */
Route::middleware('perm:meeting.view')->group(function () {
    Route::get('meeting-followups/summary', [V1\MeetingFollowUpController::class, 'summary']);
    Route::get('meeting-followups', [V1\MeetingFollowUpController::class, 'index']);
    Route::get('meetings/{id}/followups', [V1\MeetingFollowUpController::class, 'forMeeting']);

    Route::post('meetings/{id}/resolutions', [V1\MeetingFollowUpController::class, 'storeResolution']);
    Route::patch('meeting-resolutions/{id}', [V1\MeetingFollowUpController::class, 'updateResolution']);
    Route::delete('meeting-resolutions/{id}', [V1\MeetingFollowUpController::class, 'destroyResolution']);
    Route::post('meeting-resolutions/{id}/status', [V1\MeetingFollowUpController::class, 'resolutionStatus']);
    Route::post('meeting-resolutions/{id}/convert-task', [V1\MeetingFollowUpController::class, 'convertTask']);
    Route::post('meeting-resolutions/{id}/convert-obligation', [V1\MeetingFollowUpController::class, 'convertObligation']);

    Route::post('meetings/{id}/actions', [V1\MeetingFollowUpController::class, 'storeAction']);
    Route::patch('meeting-actions/{id}', [V1\MeetingFollowUpController::class, 'updateAction']);
    Route::delete('meeting-actions/{id}', [V1\MeetingFollowUpController::class, 'destroyAction']);
    Route::post('meeting-actions/{id}/status', [V1\MeetingFollowUpController::class, 'actionStatus']);

    Route::get('meeting-followup-files/{kind}/{id}', [V1\MeetingFollowUpController::class, 'files']);
    Route::post('meeting-followup-files/{kind}/{id}', [V1\MeetingFollowUpController::class, 'storeFile']);
});
