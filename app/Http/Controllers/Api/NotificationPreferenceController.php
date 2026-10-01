<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationPreferenceRequest;
use App\Models\NotificationPreference;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\InboxCatalog;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('notifications.view'), 403, 'You cannot access notification preferences.');
        $preferences = NotificationPreference::query()->where('user_id', $request->user()->id)
            ->orderBy('event')->orderBy('channel')->get();

        return ApiResponse::success($preferences, [
            'events' => InboxCatalog::NOTIFICATION_EVENTS,
            'channels' => [
                ['key' => 'in_app', 'operational' => true],
                ['key' => 'email', 'operational' => true],
                ['key' => 'push', 'operational' => false],
                ['key' => 'whatsapp', 'operational' => false],
                ['key' => 'sms', 'operational' => false],
            ],
            'defaults' => ['in_app' => true, 'email' => false, 'push' => false, 'whatsapp' => false, 'sms' => false],
        ]);
    }

    public function update(NotificationPreferenceRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('notifications.manage'), 403, 'You cannot change notification preferences.');
        $preferences = $request->validated('preferences');
        $keys = collect($preferences)->map(fn (array $preference): string => $preference['event'].':'.$preference['channel'],
        );
        if ($keys->unique()->count() !== $keys->count()) {
            throw ValidationException::withMessages(['preferences' => 'Each event and channel pair must be unique.']);
        }

        $this->database->transaction(function () use ($preferences, $request): void {
            foreach ($preferences as $preference) {
                NotificationPreference::updateOrCreate([
                    'user_id' => $request->user()->id,
                    'event' => $preference['event'],
                    'channel' => $preference['channel'],
                ], [
                    'enabled' => $preference['enabled'],
                    'delivery' => $preference['delivery'] ?? 'immediate',
                ]);
            }
            $this->audit->record('update_preferences', 'notification_preferences', $request->user()->id,
                newValues: ['count' => count($preferences)]);
        });

        return $this->index($request);
    }
}
