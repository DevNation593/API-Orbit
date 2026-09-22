<?php

use App\Http\Controllers\Api\CustomerPortalInvitationController;
use App\Http\Controllers\Api\CustomerPortalSettingsController;
use App\Http\Controllers\Api\CustomerPortalUserController;
use App\Http\Controllers\Api\PortalInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('customer-portal')
    ->middleware(['auth:sanctum', 'tenant.context:required'])
    ->group(function (): void {
        Route::get('settings', [CustomerPortalSettingsController::class, 'show']);
        Route::put('settings', [CustomerPortalSettingsController::class, 'upsert']);
        Route::get('invitations', [CustomerPortalInvitationController::class, 'index']);
        Route::post('invitations', [CustomerPortalInvitationController::class, 'store']);
        Route::delete('invitations/{invitation}', [CustomerPortalInvitationController::class, 'destroy'])
            ->whereNumber('invitation');
        Route::get('users', [CustomerPortalUserController::class, 'index']);
        Route::patch('users/{portalUser}', [CustomerPortalUserController::class, 'update'])
            ->whereNumber('portalUser');
    });

Route::prefix('portal/invitations')
    ->middleware('throttle:portal-invitation')
    ->where(['token' => '[A-Za-z0-9]{64}'])
    ->group(function (): void {
        Route::get('{token}', [PortalInvitationController::class, 'show']);
        Route::post('{token}/accept', [PortalInvitationController::class, 'accept']);
    });
