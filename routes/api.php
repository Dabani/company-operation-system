<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---- public ------------------------------------------------------------------------------
    Route::get('public/config', [V1\PublicController::class, 'config']);
    Route::get('public/logo', [V1\PublicController::class, 'logo']);

    Route::post('auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [V1\AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('auth/reset-password', [V1\AuthController::class, 'resetPassword'])->middleware('throttle:login');

    // ---- authenticated + tenant context -------------------------------------------------------
    Route::middleware(['auth:sanctum', 'org.context'])->group(function () {
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);

        Route::get('me', [V1\MeController::class, 'show']);
        Route::patch('me', [V1\MeController::class, 'update']);
        Route::post('me/password', [V1\MeController::class, 'password']);
        Route::post('me/avatar', [V1\MeController::class, 'avatar']);
        Route::post('me/organization', [V1\MeController::class, 'switchOrganization']);
        Route::get('users/{user}/avatar', [V1\MeController::class, 'avatarFile']);

        Route::get('menu', [V1\MenuController::class, 'index']);
        Route::get('dashboard', [V1\DashboardController::class, 'index'])->middleware('perm:dashboard.view');

        Route::get('languages', [V1\LookupController::class, 'languages']);
        Route::get('lookups', [V1\LookupController::class, 'index']);
        Route::get('lookup-types', [V1\LookupController::class, 'types'])->middleware('perm:lookup.view');
        Route::middleware('perm:lookup.manage')->group(function () {
            Route::post('lookup-values', [V1\LookupController::class, 'store']);
            Route::patch('lookup-values/{id}', [V1\LookupController::class, 'update']);
            Route::delete('lookup-values/{id}', [V1\LookupController::class, 'destroy']);
        });

        Route::get('organizations/current', [V1\OrganizationController::class, 'show'])->middleware('perm:organization.view');
        Route::middleware('perm:organization.update')->group(function () {
            Route::patch('organizations/current', [V1\OrganizationController::class, 'update']);
            Route::post('organizations/current/logo', [V1\OrganizationController::class, 'logo']);
        });

        // Departments/locations: read for anyone who may pick them; write needs manage.
        Route::get('departments', [V1\DepartmentController::class, 'index'])->middleware('perm:department.view');
        Route::middleware('perm:department.manage')->group(function () {
            Route::post('departments', [V1\DepartmentController::class, 'store']);
            Route::patch('departments/{id}', [V1\DepartmentController::class, 'update']);
            Route::delete('departments/{id}', [V1\DepartmentController::class, 'destroy']);
        });
        Route::get('locations', [V1\LocationController::class, 'index'])->middleware('perm:location.view');
        Route::middleware('perm:location.manage')->group(function () {
            Route::post('locations', [V1\LocationController::class, 'store']);
            Route::patch('locations/{id}', [V1\LocationController::class, 'update']);
            Route::delete('locations/{id}', [V1\LocationController::class, 'destroy']);
        });

        Route::get('users', [V1\UserController::class, 'index'])->middleware('perm:user.view');
        Route::get('users/{id}', [V1\UserController::class, 'show'])->middleware('perm:user.view');
        Route::post('users', [V1\UserController::class, 'store'])->middleware('perm:user.create');
        Route::post('users/import', [V1\UserController::class, 'import'])->middleware('perm:data.import,user.create');
        Route::patch('users/{id}', [V1\UserController::class, 'update'])->middleware('perm:user.update');
        Route::post('users/{id}/status', [V1\UserController::class, 'setStatus'])->middleware('perm:user.suspend');

        Route::get('roles', [V1\RoleController::class, 'index'])->middleware('perm:role.view');
        Route::get('permissions', [V1\RoleController::class, 'permissions'])->middleware('perm:role.view');
        Route::get('dashboard-widgets', [V1\RoleController::class, 'widgets'])->middleware('perm:role.view');
        Route::middleware('perm:role.manage')->group(function () {
            Route::post('roles', [V1\RoleController::class, 'store']);
            Route::patch('roles/{id}', [V1\RoleController::class, 'update']);
            Route::delete('roles/{id}', [V1\RoleController::class, 'destroy']);
        });

        // ---- service desk: cases, notifications, chat ----------------------------------------------
        Route::get('notifications', [V1\NotificationController::class, 'index']);
        Route::post('notifications/read-all', [V1\NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [V1\NotificationController::class, 'read']);

        Route::get('cases', [V1\CaseController::class, 'index'])->middleware('perm:case.view_own,case.view_all');
        Route::get('cases/assignees', [V1\CaseController::class, 'assignees'])->middleware('perm:case.assign');
        Route::post('cases', [V1\CaseController::class, 'store'])->middleware('perm:case.create');
        Route::get('cases/{id}', [V1\CaseController::class, 'show'])->middleware('perm:case.view_own,case.view_all');
        Route::patch('cases/{id}', [V1\CaseController::class, 'update'])->middleware('perm:case.update_status,case.assign');
        Route::post('cases/{id}/comments', [V1\CaseController::class, 'comment'])->middleware('perm:case.comment');

        Route::middleware('perm:chat.use')->group(function () {
            Route::get('chat/conversation', [V1\ChatController::class, 'mine']);
            Route::post('chat/conversation/messages', [V1\ChatController::class, 'send'])->middleware('throttle:30,1');
            Route::post('chat/conversation/escalate', [V1\ChatController::class, 'escalate']);
            Route::post('chat/conversation/close', [V1\ChatController::class, 'close']);
        });
        Route::middleware('perm:chat.moderate')->prefix('chat/conversations')->group(function () {
            Route::get('/', [V1\ChatController::class, 'agentIndex']);
            Route::get('{id}', [V1\ChatController::class, 'agentShow']);
            Route::post('{id}/messages', [V1\ChatController::class, 'agentSend'])->middleware('throttle:60,1');
            Route::post('{id}/close', [V1\ChatController::class, 'agentClose']);
        });

        // ---- files ---------------------------------------------------------------------------------
        Route::get('files', [V1\FileController::class, 'index'])->middleware('perm:file.view');
        Route::post('files', [V1\FileController::class, 'store'])->middleware('perm:file.upload');
        Route::get('files/{id}', [V1\FileController::class, 'show'])->middleware('perm:file.view');
        Route::patch('files/{id}', [V1\FileController::class, 'update'])->middleware('perm:file.view');
        Route::get('files/{id}/download', [V1\FileController::class, 'download'])->middleware('perm:file.download');
        Route::get('files/{id}/preview', [V1\FileController::class, 'preview'])->middleware('perm:file.view');
        Route::post('files/{id}/versions', [V1\FileController::class, 'addVersion'])->middleware('perm:file.replace');
        Route::post('files/{id}/archive', [V1\FileController::class, 'archive'])->middleware('perm:file.archive');
        Route::post('files/{id}/restore', [V1\FileController::class, 'restore'])->middleware('perm:file.archive,file.restore');
        Route::post('files/{id}/legal-hold', [V1\FileController::class, 'legalHold'])->middleware('perm:file.legal_hold');
        Route::get('files/{id}/comments', [V1\FileController::class, 'comments'])->middleware('perm:file.view');
        Route::post('files/{id}/comments', [V1\FileController::class, 'addComment'])->middleware('perm:file.view');
        Route::get('files/{id}/activity', [V1\FileController::class, 'activity'])->middleware('perm:file.view');
        Route::post('files/{id}/deletion-requests', [V1\FileController::class, 'requestDeletion'])->middleware('perm:file.delete_request');
        Route::get('file-deletion-requests', [V1\FileController::class, 'deletionRequests'])->middleware('perm:file.view');
        Route::post('file-deletion-requests/{id}/approve', [V1\FileController::class, 'approveDeletion'])->middleware('perm:file.view');
        Route::post('file-deletion-requests/{id}/reject', [V1\FileController::class, 'rejectDeletion'])->middleware('perm:file.view');
        Route::get('cases/{id}/attachments', [V1\FileController::class, 'caseAttachments'])->middleware('perm:case.view_own,case.view_all');
        Route::post('cases/{id}/attachments', [V1\FileController::class, 'caseUpload'])->middleware('perm:file.upload');

        Route::get('audit-logs', [V1\AuditLogController::class, 'index'])->middleware('perm:audit_log.view');
        Route::get('audit-logs/{id}', [V1\AuditLogController::class, 'show'])->middleware('perm:audit_log.view');
    });
});
