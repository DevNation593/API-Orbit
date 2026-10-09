<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IntegrationRequest;
use App\Models\Integration;
use App\Services\IntegrationManager;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly IntegrationManager $manager,
    ) {}

    public function index(): JsonResponse
    {
        $this->assertPermission('integrations.view');

        return ApiResponse::success(Integration::query()->orderBy('provider')->orderBy('name')->get());
    }

    public function providers(): JsonResponse
    {
        $this->assertPermission('integrations.view');

        return ApiResponse::success($this->manager->catalog());
    }

    public function store(IntegrationRequest $request): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $data = $request->validated();
        $credentials = $data['credentials'] ?? [];
        $settings = $data['settings'] ?? [];
        $this->assertProvider($data['provider']);
        $integration = Integration::create([
            ...$data,
            'credentials' => $credentials,
            'settings' => $settings,
            'status' => 'pending',
        ]);
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
        $data = $request->validated();
        if (isset($data['provider']) && $data['provider'] !== $integration->provider) {
            throw ValidationException::withMessages(['provider' => 'The provider cannot be changed after creation.']);
        }

        $old = $this->publicValues($integration);
        $submittedCredentials = array_filter(
            $data['credentials'] ?? [],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
        $credentials = array_replace($integration->credentials ?? [], $submittedCredentials);
        $settings = array_replace($integration->settings ?? [], $data['settings'] ?? []);
        $this->assertProvider($integration->provider);
        $status = $data['status'] ?? 'pending';
        unset($data['provider'], $data['credentials'], $data['settings']);
        $integration->update([
            ...$data,
            'credentials' => $credentials,
            'settings' => $settings,
            'status' => $status,
        ]);
        $this->audit->record('integration_change', $integration, oldValues: $old, newValues: $this->publicValues($integration));

        return ApiResponse::success($integration->fresh());
    }

    public function health(int $id): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::findOrFail($id);

        return ApiResponse::success($this->manager->health($integration));
    }

    public function connect(int $id): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::findOrFail($id);

        try {
            $this->manager->connect($integration);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['connection' => $exception->getMessage()]);
        }

        $integration->update(['status' => 'active', 'last_synced_at' => now()]);
        $this->audit->record('integration_connect', $integration, newValues: $this->publicValues($integration));

        return ApiResponse::success($integration->fresh());
    }

    public function disconnect(int $id): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::findOrFail($id);
        $this->manager->disconnect($integration);
        $integration->update(['status' => 'disabled']);
        $this->audit->record('integration_disconnect', $integration, newValues: $this->publicValues($integration));

        return ApiResponse::success($integration->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->assertPermission('integrations.manage');
        $integration = Integration::findOrFail($id);
        $old = $this->publicValues($integration);
        $this->manager->disconnect($integration);
        $integration->delete();
        $this->audit->record('integration_change', $integration, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function assertPermission(string $permission): void
    {
        abort_unless(request()->user()->hasPermission($permission), 403, 'You do not have permission to manage integrations.');
    }

    private function assertProvider(string $provider): void
    {
        try {
            $this->manager->provider($provider);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['provider' => 'The selected integration provider is not configured.']);
        }
    }

    /** @return array<string, mixed> */
    private function publicValues(Integration $integration): array
    {
        return [
            'provider' => $integration->provider,
            'name' => $integration->name,
            'settings' => $integration->settings,
            'status' => $integration->status,
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
        ];
    }
}
