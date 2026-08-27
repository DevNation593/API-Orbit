<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', fn ($user, int $userId): bool => (int) $user->id === $userId);
Broadcast::channel('App.Models.User.{userId}', fn ($user, int $userId): bool => (int) $user->id === $userId);
