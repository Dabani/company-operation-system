<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 7b routes (meeting attendance). Required from routes/api.php inside the authenticated "org.context" group, next to meetings.php. */
Route::middleware('perm:meeting.view')->group(function () {
    Route::get('meetings/{id}/attendance', [V1\MeetingAttendanceController::class, 'index']);
    Route::post('meetings/{id}/attendance/generate', [V1\MeetingAttendanceController::class, 'generate']);
    Route::post('meetings/{id}/attendance/bulk', [V1\MeetingAttendanceController::class, 'bulk']);
    Route::post('meetings/{id}/attendance/walk-in', [V1\MeetingAttendanceController::class, 'walkIn']);
    Route::post('meetings/{id}/attendance/acknowledge', [V1\MeetingAttendanceController::class, 'acknowledge']);
    Route::get('meetings/{id}/attendance/export', [V1\MeetingAttendanceController::class, 'export']);
    Route::post('meetings/{id}/checkin-link/rotate', [V1\MeetingAttendanceController::class, 'rotateCheckin']);
    Route::patch('meeting-attendance/{id}', [V1\MeetingAttendanceController::class, 'update']);
    Route::get('meeting-checkin/{token}', [V1\MeetingAttendanceController::class, 'checkinPreview']);
    Route::post('meeting-checkin/{token}', [V1\MeetingAttendanceController::class, 'checkin'])->middleware('throttle:30,1');
});
