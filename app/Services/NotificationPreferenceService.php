<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\InboxCatalog;

final class NotificationPreferenceService
{
    /** @return array<int, string> */
    public function immediateChannels(User $user, string $event): array
    {
        $rows = NotificationPreference::query()->where('user_id', $user->id)
            ->whereIn('event', ['*', $event])->get()->keyBy(fn (NotificationPreference $preference): string => $preference->event.':'.$preference->channel,
            );

        return collect(InboxCatalog::NOTIFICATION_CHANNELS)->filter(function (string $channel) use ($rows, $event): bool {
            $preference = $rows->get($event.':'.$channel) ?? $rows->get('*:'.$channel);
            if ($preference !== null) {
                return $preference->enabled && $preference->delivery === 'immediate';
            }

            return $channel === 'in_app';
        })->values()->all();
    }
}
