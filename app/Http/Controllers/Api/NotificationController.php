<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DatabaseNotification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->permission($request);
        $request->validate([
            'state' => ['sometimes', 'in:read,unread'],
            'event' => ['sometimes', 'string', 'max:120'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $query = $request->user()->notifications();
        if ($request->input('state') === 'read') {
            $query->read();
        } elseif ($request->input('state') === 'unread') {
            $query->unread();
        }
        foreach (['event', 'priority'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }
        $paginator = $query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn (DatabaseNotification $notification): array => $this->present($notification),
        ));

        return ApiResponse::paginated($paginator);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->permission($request);

        return ApiResponse::success(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $this->permission($request);
        $model = $this->find($request, $notification);
        $model->markAsRead();

        return ApiResponse::success($this->present($model->fresh()));
    }

    public function markUnread(Request $request, string $notification): JsonResponse
    {
        $this->permission($request);
        $model = $this->find($request, $notification);
        $model->markAsUnread();

        return ApiResponse::success($this->present($model->fresh()));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->permission($request);
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['updated' => $updated]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->permission($request);
        $this->find($request, $notification)->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    private function permission(Request $request): void
    {
        abort_unless($request->user()->hasPermission('notifications.view'), 403, 'You cannot access notifications.');
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'event' => $notification->event ?? data_get($notification->data, 'event'),
            'title' => data_get($notification->data, 'title') ?? str($notification->event)->replace('.', ' ')->title(),
            'body' => data_get($notification->data, 'body'),
            'priority' => $notification->priority,
            'action_url' => data_get($notification->data, 'action_url'),
            'context' => data_get($notification->data, 'context', []),
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
