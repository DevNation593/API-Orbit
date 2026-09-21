<?php

use App\Http\Controllers\Api\CustomerPortalSettingsController;
use App\Http\Controllers\Api\CustomerPortalUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('customer-portal')
    ->middleware(['auth:sanctum', 'tenant.context:required'])
    ->group(function (): void {
        Route::get('settings', [CustomerPortalSettingsController::class, 'show']);
        Route::put('settings', [CustomerPortalSettingsController::class, 'upsert']);
        Route::get('users', [CustomerPortalUserController::class, 'index']);
        Route::patch('users/{portalUser}', [CustomerPortalUserController::class, 'update'])
            ->whereNumber('portalUser');
    });
