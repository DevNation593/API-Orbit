<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlaEscalation;
use App\Support\ApiResponse;
use App\Support\SupportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SlaEscalationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sla.view'), 403);
        $data = $request->validate([
            'ticket_id' => ['sometimes', 'integer', 'min:1'],
            'metric' => ['sometimes', Rule::in(SupportCatalog::METRICS)],
            'status' => ['sometimes', Rule::in(SupportCatalog::ESCALATION_STATUSES)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = SlaEscalation::query()->with('execution:id,ticket_id');
        foreach (['metric', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['ticket_id'])) {
            $query->whereHas('execution', fn ($execution) => $execution->where('ticket_id', $data['ticket_id']));
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 25)->withQueryString();
        $page->through(fn (SlaEscalation $row): array => $row->only([
            'id', 'tenant_id', 'execution_id', 'metric', 'breached_at', 'detected_at', 'status',
            'attempts', 'next_attempt_at', 'created_at', 'updated_at',
        ]) + [
            'ticket_id' => (int) $row->execution->ticket_id,
            'recipients' => array_map(fn (array $recipient): array => Arr::only($recipient, ['user_id', 'status', 'attempts']), $row->recipients),
        ]);

        return ApiResponse::paginated($page);
    }
}
