<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 7a routes (meetings and events, core). Required from routes/api.php inside the authenticated "org.context" group.
 | Every route names its permission; the data scope (who may see which meeting) is applied inside the controllers (MeetingAccess).
 */

// ---- rooms ----------------------------------------------------------------------------------------------------------------------
Route::get('meeting-rooms', [V1\MeetingRoomController::class, 'index'])->middleware('perm:meeting.view,meeting.room.manage');
Route::get('meeting-rooms/{id}/availability', [V1\MeetingRoomController::class, 'availability'])->middleware('perm:meeting.view,meeting.room.manage');
Route::middleware('perm:meeting.room.manage')->group(function () {
    Route::post('meeting-rooms', [V1\MeetingRoomController::class, 'store']);
    Route::patch('meeting-rooms/{id}', [V1\MeetingRoomController::class, 'update']);
    Route::delete('meeting-rooms/{id}', [V1\MeetingRoomController::class, 'destroy']);
});

// ---- meetings -------------------------------------------------------------------------------------------------------------------
Route::middleware('perm:meeting.view')->group(function () {
    Route::get('meetings', [V1\MeetingController::class, 'index']);
    Route::get('meetings/people', [V1\MeetingController::class, 'people'])->middleware('perm:meeting.create');
    Route::get('meetings/{id}', [V1\MeetingController::class, 'show']);
    Route::get('meetings/{id}/ics', [V1\MeetingController::class, 'ics']);
    Route::post('meetings/{id}/rsvp', [V1\MeetingInvitationController::class, 'rsvp']);

    // changes: the controller requires organizer / secretary / "manage all" (404 when the meeting is not visible at all)
    Route::patch('meetings/{id}', [V1\MeetingController::class, 'update']);
    Route::post('meetings/{id}/schedule', [V1\MeetingController::class, 'schedule']);
    Route::post('meetings/{id}/cancel', [V1\MeetingController::class, 'cancel']);
    Route::post('meetings/{id}/start', [V1\MeetingController::class, 'start']);
    Route::post('meetings/{id}/hold', [V1\MeetingController::class, 'hold']);

    Route::post('meetings/{id}/invitations', [V1\MeetingInvitationController::class, 'store']);
    Route::delete('meeting-invitations/{invitationId}', [V1\MeetingInvitationController::class, 'destroy']);

    Route::post('meetings/{id}/agenda-items', [V1\MeetingAgendaController::class, 'store']);
    Route::patch('agenda-items/{itemId}', [V1\MeetingAgendaController::class, 'update']);
    Route::delete('agenda-items/{itemId}', [V1\MeetingAgendaController::class, 'destroy']);
    Route::post('meetings/{id}/agenda/reorder', [V1\MeetingAgendaController::class, 'reorder']);
    Route::post('meetings/{id}/agenda/circulate', [V1\MeetingAgendaController::class, 'circulate']);
    Route::post('meetings/{id}/agenda/approve', [V1\MeetingAgendaController::class, 'approve']);

    Route::get('{kind}-papers/{id}', [V1\MeetingFileController::class, 'index'])->whereIn('kind', ['meeting', 'agenda-item']);
    Route::post('{kind}-papers/{id}', [V1\MeetingFileController::class, 'store'])->whereIn('kind', ['meeting', 'agenda-item']);
});
Route::post('meetings', [V1\MeetingController::class, 'store'])->middleware('perm:meeting.create');
