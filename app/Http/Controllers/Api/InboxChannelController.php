<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InboxChannelRequest;
use App\Models\Inbox;
use App\Models\InboxChannel;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxChannelController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function store(InboxChannelRequest $request, int $inbox): JsonResponse
    {
        $parent = Inbox::findOrFail($inbox);
        $this->authorize('update', $parent);
        $channel = $parent->channels()->create($request->validated());
        $this->audit->record('create', $channel, newValues: $this->auditValues($channel));

        return ApiResponse::success($channel->load('integration:id,provider,name,status'), [], 201);
    }

    public function update(InboxChannelRequest $request, int $channel): JsonResponse
    {
        $model = InboxChannel::with('inbox')->findOrFail($channel);
        $this->authorize('update', $model->inbox);
        $data = $request->validated();
        if (isset($data['channel']) && $data['channel'] !== $model->channel && $model->conversations()->exists()) {
            abort(409, 'A channel with conversations cannot change transport type.');
        }
        $old = $this->auditValues($model);
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load('integration:id,provider,name,status'));
    }

    public function destroy(Request $request, int $channel): JsonResponse
    {
        $model = InboxChannel::with('inbox')->findOrFail($channel);
        $this->authorize('update', $model->inbox);
        abort_if($model->conversations()->exists(), 409, 'A channel with conversations cannot be deleted; deactivate it instead.');
        $old = $this->auditValues($model);
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function auditValues(InboxChannel $channel): array
    {
        return [
            'inbox_id' => $channel->inbox_id,
            'channel' => $channel->channel,
            'name' => $channel->name,
            'integration_id' => $channel->integration_id,
            'status' => $channel->status,
        ];
    }
}
