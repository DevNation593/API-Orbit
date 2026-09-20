<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CrmEventNotification;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

final class NotificationDispatcher
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    /** @param array<string, mixed> $data */
    public function send(
        User $user,
        string $event,
        string $title,
        string $body,
        array $data = [],
        ?string $actionUrl = null,
        string $priority = 'normal',
    ): bool {
        $tenantId = app(TenantContext::class)->requireId();
        $isMember = $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->exists();
        if (! $isMember) {
            throw ValidationException::withMessages(['user_id' => 'The notification recipient is not an active tenant member.']);
        }

        $channels = $this->preferences->immediateChannels($user, $event);
        if ($channels === []) {
            return false;
        }

        $notification = new CrmEventNotification(
            $tenantId, $event, $title, $body, $data, $actionUrl, $priority, $channels,
        );
        if ($notification->via($user) === []) {
            return false;
        }
        $user->notify($notification);

        return true;
    }
}
