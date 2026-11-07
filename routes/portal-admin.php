<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/* COPS Phase 3 staff routes - required inside the authenticated "org.context" group by apply-bundle-12.py. */
Route::middleware('perm:client.manage')->group(function () {
    Route::get('portal-admin/clients', [V1\PortalAdminController::class, 'overview']);
    Route::put('clients/{id}/portal', [V1\PortalAdminController::class, 'setClientPortal']);
    Route::post('clients/{id}/contacts/{contactId}/invite', [V1\PortalAdminController::class, 'invite']);
    Route::put('client-identities/{identityId}/active', [V1\PortalAdminController::class, 'setIdentityActive']);
    Route::post('client-identities/{identityId}/reset-mfa', [V1\PortalAdminController::class, 'resetMfa']);
});
Route::middleware('perm:project.view')->group(function () {
    Route::get('projects/{id}/portal', [V1\PortalAdminController::class, 'projectPortal']);
    Route::put('projects/{id}/portal', [V1\PortalAdminController::class, 'setProjectPortal']);
    Route::put('projects/{id}/portal-shares/{fileId}', [V1\PortalAdminController::class, 'setShare']);
    Route::post('projects/{id}/client-comments', [V1\PortalAdminController::class, 'comment']);
});
