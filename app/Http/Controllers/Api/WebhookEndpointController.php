<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookEndpointRequest;
use App\Models\WebhookEndpoint;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DatabaseManager $database,
    ) {}

    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('webhooks.view'), 403, 'You do not have permission to view webhooks.');

        return ApiResponse::success(WebhookEndpoint::query()->orderBy('name')->get());
    }

    public function store(WebhookEndpointRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('webhooks.manage'), 403, 'You do not have permission to manage webhooks.');
        $data = $request->validated();
        $secret = $data['secret'] ?? Str::random(48);
        $data['secret'] = $secret;
        $endpoint = $this->database->transaction(function () use ($data): WebhookEndpoint {
            $endpoint = WebhookEndpoint::create($data);
            DB::table('webhook_ingress_routes')->insert([
                'endpoint_id' => $endpoint->id,
                'tenant_id' => $endpoint->tenant_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $endpoint;
        });
        $this->audit->record('integration_change', $endpoint, newValues: ['name' => $endpoint->name, 'url' => $endpoint->url, 'events' => $endpoint->events]);

        return ApiResponse::success(['endpoint' => $endpoint, 'signing_secret' => $secret], [], 201);
    }

    public function update(WebhookEndpointRequest $request, int $id): JsonResponse
    {
        abort_unless($request->user()->hasPermission('webhooks.manage'), 403, 'You do not have permission to manage webhooks.');
        $endpoint = WebhookEndpoint::findOrFail($id);
        $data = $request->validated();
        if (array_key_exists('secret', $data) && $data['secret'] === null) {
            unset($data['secret']);
        }
        $old = $endpoint->getAttributes();
        $endpoint->update($data);
        $this->audit->record('integration_change', $endpoint, oldValues: ['name' => $old['name'], 'url' => $old['url'], 'events' => json_decode($old['events'] ?? 'null', true)], newValues: ['name' => $endpoint->name, 'url' => $endpoint->url, 'events' => $endpoint->events]);

        return ApiResponse::success($endpoint);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('webhooks.manage'), 403, 'You do not have permission to manage webhooks.');
        $endpoint = WebhookEndpoint::findOrFail($id);
        $endpoint->delete();
        $this->audit->record('integration_change', $endpoint, oldValues: ['name' => $endpoint->name, 'url' => $endpoint->url]);

        return ApiResponse::success(['deleted' => true]);
    }
}
