<?php

use App\Http\Controllers\Api\V1 as V1;
use App\Http\Middleware\EnsurePortalMfa;
use Illuminate\Support\Facades\Route;

/*
 | External client portal. Required from routes/api.php inside Route::prefix('v1'), OUTSIDE the staff "org.context" group.
 | Own guard ("client"), own throttling, two-factor gate, client-scoped data. Replaces the Bundle 08 version of this file.
 */
Route::prefix('portal')->group(function () {
    Route::post('auth/login', [V1\PortalAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot', [V1\PortalAuthController::class, 'forgot'])->middleware('throttle:portal-auth');
    Route::get('auth/activation', [V1\PortalAuthController::class, 'activationCheck'])->middleware('throttle:portal-auth');
    Route::post('auth/activate', [V1\PortalAuthController::class, 'activate'])->middleware('throttle:portal-auth');

    Route::middleware(['auth:client', 'throttle:portal'])->group(function () {
        Route::post('auth/logout', [V1\PortalAuthController::class, 'logout']);
        Route::get('auth/status', [V1\PortalAuthController::class, 'status']);
        Route::post('auth/mfa/setup', [V1\PortalAuthController::class, 'mfaSetup'])->middleware('throttle:portal-mfa');
        Route::post('auth/mfa/confirm', [V1\PortalAuthController::class, 'mfaConfirm'])->middleware('throttle:portal-mfa');
        Route::post('auth/mfa/verify', [V1\PortalAuthController::class, 'mfaVerify'])->middleware('throttle:portal-mfa');
    });

    Route::middleware(['auth:client', 'client.context', EnsurePortalMfa::class, 'throttle:portal'])->group(function () {
        Route::get('me', [V1\PortalController::class, 'me']);
        Route::get('contacts', [V1\PortalController::class, 'contacts']);
        Route::get('contacts/{id}', [V1\PortalController::class, 'contact']);
        Route::get('projects', [V1\PortalController::class, 'projects']);
        Route::get('projects/{id}', [V1\PortalController::class, 'showProject']);
        Route::post('projects/{id}/comments', [V1\PortalController::class, 'comment']);
        Route::post('projects/{id}/files', [V1\PortalController::class, 'upload'])->middleware('throttle:20,1');
        Route::get('projects/{id}/files/{fileId}/download', [V1\PortalController::class, 'download']);
        Route::get('projects/{id}/invoices', [V1\PortalController::class, 'invoices']);
        Route::get('projects/{id}/invoices/{invoiceId}/pdf', [V1\PortalController::class, 'invoicePdf']);
    });
});
