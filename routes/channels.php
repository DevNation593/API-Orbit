<?php

use App\Models\TenantUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', fn ($user, int $userId): bool => (int) $user->id === $userId);
Broadcast::channel('App.Models.User.{userId}', fn ($user, int $userId): bool => (int) $user->id === $userId);
Broadcast::channel('tenants.{tenantId}.inbox', fn ($user, int $tenantId): bool => TenantUser::query()
    ->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'active')->exists());
