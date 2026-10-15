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

        Route::get('audit-logs', [V1\AuditLogController::class, 'index'])->middleware('perm:audit_log.view');
        Route::get('audit-logs/{id}', [V1\AuditLogController::class, 'show'])->middleware('perm:audit_log.view');
    });
});
