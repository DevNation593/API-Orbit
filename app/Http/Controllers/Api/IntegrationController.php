<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IntegrationRequest;
use App\Models\Integration;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;

class IntegrationController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        $this->assertPermission('integrations.view');

        return ApiResponse::success(Integration::query()->orderBy('provider')->orderBy('name')->get());
    }

    public function store(IntegrationRequest $request): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::create($request->validated());
        $this->audit->record('integration_change', $integration, newValues: $this->publicValues($integration));

        return ApiResponse::success($integration, [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $this->assertPermission('integrations.view');

        return ApiResponse::success(Integration::findOrFail($id));
    }

    public function update(IntegrationRequest $request, int $id): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::findOrFail($id);
        $old = $this->publicValues($integration);
        $integration->update($request->validated());
        $this->audit->record('integration_change', $integration, oldValues: $old, newValues: $this->publicValues($integration));

        return ApiResponse::success($integration->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::findOrFail($id);
        $old = $this->publicValues($integration);
        $integration->delete();
        $this->audit->record('integration_change', $integration, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function assertPermission(string $permission): void
    {
        abort_unless(request()->user()->hasPermission($permission), 403, 'You do not have permission to manage integrations.');
    }

    /** @return array<string, mixed> */
    private function publicValues(Integration $integration): array
    {
        return [
            'provider' => $integration->provider,
            'name' => $integration->name,
            'settings' => $integration->settings,
            'status' => $integration->status,
        ];
    }
}
