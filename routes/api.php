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

        // ---- IT infrastructure: assets, equipment movement, access register, incidents -------------
        Route::get('assets', [V1\AssetController::class, 'index'])->middleware('perm:asset.view');
        Route::post('assets', [V1\AssetController::class, 'store'])->middleware('perm:asset.manage');
        Route::post('assets/import', [V1\AssetController::class, 'import'])->middleware('perm:asset.manage');
        Route::get('assets/{id}', [V1\AssetController::class, 'show'])->middleware('perm:asset.view');
        Route::patch('assets/{id}', [V1\AssetController::class, 'update'])->middleware('perm:asset.manage');
        Route::post('assets/{id}/assign', [V1\AssetController::class, 'assign'])->middleware('perm:asset.manage');
        Route::post('assets/{id}/report-lost', [V1\AssetController::class, 'reportLost'])->middleware('perm:asset.view');

        Route::get('equipment-movements', [V1\MovementController::class, 'index'])->middleware('perm:asset.request_movement,asset.approve_movement,asset.manage');
        Route::get('equipment-movements/candidates', [V1\MovementController::class, 'candidates'])->middleware('perm:asset.request_movement');
        Route::post('equipment-movements', [V1\MovementController::class, 'store'])->middleware('perm:asset.request_movement');
        Route::get('equipment-movements/{id}', [V1\MovementController::class, 'show'])->middleware('perm:asset.request_movement,asset.approve_movement,asset.manage');
        Route::get('equipment-movements/{id}/pdf', [V1\MovementController::class, 'pdf'])->middleware('perm:asset.request_movement,asset.approve_movement,asset.manage');
        Route::post('equipment-movements/{id}/approve', [V1\MovementController::class, 'approve'])->middleware('perm:asset.approve_movement');
        Route::post('equipment-movements/{id}/reject', [V1\MovementController::class, 'reject'])->middleware('perm:asset.approve_movement');
        Route::post('equipment-movements/{id}/checkout', [V1\MovementController::class, 'checkout'])->middleware('perm:asset.manage');
        Route::post('equipment-movements/{id}/return', [V1\MovementController::class, 'returnBack'])->middleware('perm:asset.manage');
        Route::post('equipment-movements/{id}/cancel', [V1\MovementController::class, 'cancel'])->middleware('perm:asset.request_movement,asset.manage');

        Route::get('access-grants', [V1\AccessGrantController::class, 'index'])->middleware('perm:access.view');
        Route::post('access-grants', [V1\AccessGrantController::class, 'store'])->middleware('perm:access.manage');
        Route::post('access-grants/{id}/revoke', [V1\AccessGrantController::class, 'revoke'])->middleware('perm:access.manage');
        Route::post('access-grants/{id}/review', [V1\AccessGrantController::class, 'review'])->middleware('perm:access.manage');

        Route::get('incidents', [V1\IncidentController::class, 'index'])->middleware('perm:case.view_own,case.view_all');
        Route::post('incidents', [V1\IncidentController::class, 'store'])->middleware('perm:case.create');
        Route::get('cases/{caseId}/incident', [V1\IncidentController::class, 'show'])->middleware('perm:case.view_own,case.view_all');
        Route::put('cases/{caseId}/incident', [V1\IncidentController::class, 'update'])->middleware('perm:incident.manage');

        // ---- archive & records ---------------------------------------------------------------------
        Route::get('records', [V1\RecordController::class, 'index'])->middleware('perm:archive.view');
        Route::post('records', [V1\RecordController::class, 'store'])->middleware('perm:archive.manage');
        Route::post('records/import', [V1\RecordController::class, 'import'])->middleware('perm:archive.manage');
        Route::get('records/{id}', [V1\RecordController::class, 'show'])->middleware('perm:archive.view');
        Route::patch('records/{id}', [V1\RecordController::class, 'update'])->middleware('perm:archive.manage');
        Route::post('records/{id}/inactive', [V1\RecordController::class, 'inactive'])->middleware('perm:archive.manage');
        Route::post('records/{id}/transfer', [V1\RecordController::class, 'transfer'])->middleware('perm:archive.manage');
        Route::post('records/{id}/move', [V1\RecordController::class, 'move'])->middleware('perm:archive.manage');
        Route::post('records/{id}/hold', [V1\RecordController::class, 'hold'])->middleware('perm:archive.manage');
        Route::post('records/{id}/release', [V1\RecordController::class, 'release'])->middleware('perm:archive.approve_disposal');
        Route::get('records/{id}/attachments', [V1\RecordController::class, 'attachments'])->middleware('perm:archive.view');
        Route::post('records/{id}/attachments', [V1\RecordController::class, 'attach'])->middleware('perm:archive.manage,file.upload');

        Route::get('archive-access', [V1\ArchiveAccessController::class, 'index'])->middleware('perm:archive.view');
        Route::post('archive-access', [V1\ArchiveAccessController::class, 'store'])->middleware('perm:archive.view');
        Route::post('archive-access/{id}/authorize', [V1\ArchiveAccessController::class, 'authorizeAccess'])->middleware('perm:archive.manage');
        Route::post('archive-access/{id}/deny', [V1\ArchiveAccessController::class, 'deny'])->middleware('perm:archive.manage');
        Route::post('archive-access/{id}/return', [V1\ArchiveAccessController::class, 'returned'])->middleware('perm:archive.manage');

        Route::get('inventory-counts', [V1\InventoryController::class, 'index'])->middleware('perm:archive.manage');
        Route::post('inventory-counts', [V1\InventoryController::class, 'store'])->middleware('perm:archive.manage');
        Route::get('inventory-counts/{id}', [V1\InventoryController::class, 'show'])->middleware('perm:archive.manage');
        Route::get('inventory-counts/{id}/items', [V1\InventoryController::class, 'items'])->middleware('perm:archive.manage');
        Route::post('inventory-counts/{id}/items/{itemId}', [V1\InventoryController::class, 'mark'])->middleware('perm:archive.manage');
        Route::post('inventory-counts/{id}/complete', [V1\InventoryController::class, 'complete'])->middleware('perm:archive.manage');

        Route::get('disposal/eligible', [V1\DisposalController::class, 'eligible'])->middleware('perm:archive.manage,archive.approve_disposal');
        Route::get('disposal-batches', [V1\DisposalController::class, 'index'])->middleware('perm:archive.manage,archive.approve_disposal');
        Route::post('disposal-batches', [V1\DisposalController::class, 'propose'])->middleware('perm:archive.manage');
        Route::get('disposal-batches/{id}', [V1\DisposalController::class, 'show'])->middleware('perm:archive.manage,archive.approve_disposal');
        Route::post('disposal-batches/{id}/approve', [V1\DisposalController::class, 'approve'])->middleware('perm:archive.approve_disposal');
        Route::post('disposal-batches/{id}/reject', [V1\DisposalController::class, 'reject'])->middleware('perm:archive.approve_disposal');
        Route::post('disposal-batches/{id}/execute', [V1\DisposalController::class, 'execute'])->middleware('perm:archive.manage');
        Route::get('disposal-batches/{id}/certificate', [V1\DisposalController::class, 'certificate'])->middleware('perm:archive.manage,archive.approve_disposal');

        Route::get('retention-rules', [V1\RetentionController::class, 'index'])->middleware('perm:archive.view');
        Route::put('retention-rules/{categoryId}', [V1\RetentionController::class, 'upsert'])->middleware('perm:compliance.manage');
        Route::delete('retention-rules/{categoryId}', [V1\RetentionController::class, 'destroy'])->middleware('perm:compliance.manage');
        Route::put('archive-settings', [V1\RetentionController::class, 'settings'])->middleware('perm:compliance.manage');

        Route::get('legal-references', [V1\LegalReferenceController::class, 'index'])->middleware('perm:archive.view,compliance.view');
        Route::post('legal-references', [V1\LegalReferenceController::class, 'store'])->middleware('perm:compliance.manage');
        Route::patch('legal-references/{id}', [V1\LegalReferenceController::class, 'update'])->middleware('perm:compliance.manage');
        Route::post('legal-references/{id}/verify', [V1\LegalReferenceController::class, 'verify'])->middleware('perm:compliance.manage');

        // ---- compliance: backups, training, calendar, audits, management reports ----------------
        Route::get('backup-verifications', [V1\BackupController::class, 'index'])->middleware('perm:backup.manage,compliance.view');
        Route::get('backup-verifications/summary', [V1\BackupController::class, 'summary'])->middleware('perm:backup.manage,compliance.view');
        Route::post('backup-verifications', [V1\BackupController::class, 'store'])->middleware('perm:backup.manage');
        Route::get('backup-verifications/{id}/attachments', [V1\BackupController::class, 'attachments'])->middleware('perm:backup.manage,compliance.view');
        Route::post('backup-verifications/{id}/attachments', [V1\BackupController::class, 'attach'])->middleware('perm:backup.manage');

        Route::get('training-sessions', [V1\TrainingController::class, 'index'])->middleware('perm:training.view,training.manage');
        Route::post('training-sessions', [V1\TrainingController::class, 'store'])->middleware('perm:training.manage');
        Route::get('training-sessions/{id}', [V1\TrainingController::class, 'show'])->middleware('perm:training.view,training.manage');
        Route::patch('training-sessions/{id}', [V1\TrainingController::class, 'update'])->middleware('perm:training.manage');
        Route::put('training-sessions/{id}/attendees', [V1\TrainingController::class, 'setAttendees'])->middleware('perm:training.manage');
        Route::post('training-sessions/{id}/attendance', [V1\TrainingController::class, 'mark'])->middleware('perm:training.manage');
        Route::post('training-sessions/{id}/confirm', [V1\TrainingController::class, 'confirm'])->middleware('perm:training.view,training.manage');
        Route::post('training-sessions/{id}/complete', [V1\TrainingController::class, 'complete'])->middleware('perm:training.manage');
        Route::post('training-sessions/{id}/cancel', [V1\TrainingController::class, 'cancel'])->middleware('perm:training.manage');
        Route::get('training-attendance', [V1\TrainingController::class, 'register'])->middleware('perm:training.view,training.manage,compliance.view');
        Route::get('training-report', [V1\TrainingController::class, 'report'])->middleware('perm:training.manage,compliance.view');
        Route::post('training-report/submit', [V1\TrainingController::class, 'submitReport'])->middleware('perm:training.manage,compliance.manage');

        Route::get('obligations', [V1\ObligationController::class, 'index'])->middleware('perm:compliance.view');
        Route::post('obligations', [V1\ObligationController::class, 'store'])->middleware('perm:compliance.manage');
        Route::patch('obligations/{id}', [V1\ObligationController::class, 'update'])->middleware('perm:compliance.manage');
        Route::post('obligations/{id}/complete', [V1\ObligationController::class, 'complete'])->middleware('perm:compliance.view');
        Route::get('obligations/{id}/completions', [V1\ObligationController::class, 'completions'])->middleware('perm:compliance.view');

        Route::get('audits', [V1\AuditController::class, 'index'])->middleware('perm:compliance.view');
        Route::post('audits', [V1\AuditController::class, 'store'])->middleware('perm:compliance.manage');
        Route::get('audits/{id}', [V1\AuditController::class, 'show'])->middleware('perm:compliance.view');
        Route::patch('audits/{id}', [V1\AuditController::class, 'update'])->middleware('perm:compliance.manage');
        Route::post('audits/{id}/start', [V1\AuditController::class, 'start'])->middleware('perm:compliance.manage');
        Route::post('audits/{id}/complete', [V1\AuditController::class, 'complete'])->middleware('perm:compliance.manage');
        Route::post('audits/{id}/report', [V1\AuditController::class, 'report'])->middleware('perm:compliance.manage');
        Route::post('audits/{id}/findings', [V1\AuditController::class, 'addFinding'])->middleware('perm:compliance.manage');
        Route::patch('audit-findings/{id}', [V1\AuditController::class, 'updateFinding'])->middleware('perm:compliance.view');
        Route::get('management-reports', [V1\ManagementReportController::class, 'index'])->middleware('perm:report.receive,compliance.view');

        Route::get('audit-logs', [V1\AuditLogController::class, 'index'])->middleware('perm:audit_log.view');
        Route::get('audit-logs/{id}', [V1\AuditLogController::class, 'show'])->middleware('perm:audit_log.view');
    });
});
