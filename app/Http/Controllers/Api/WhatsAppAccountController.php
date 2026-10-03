<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsAppAccountRequest;
use App\Models\InboxChannel;
use App\Models\Integration;
use App\Models\WhatsAppAccount;
use App\Services\WhatsAppTemplateSyncService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppAccountController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly WhatsAppTemplateSyncService $templates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permission($request, 'whatsapp.view');

        return ApiResponse::paginated(WhatsAppAccount::query()->with($this->relations())
            ->orderBy('display_phone_number')->paginate(ApiResponse::perPage($request->input('per_page', 25)))
            ->withQueryString());
    }

    public function store(WhatsAppAccountRequest $request): JsonResponse
    {
        $this->permission($request, 'whatsapp.manage');
        $data = $request->validated();
        $channel = InboxChannel::findOrFail($data['inbox_channel_id']);
        $integration = Integration::findOrFail($data['integration_id']);
        $this->assertLinks($channel, $integration, $data['phone_number_id']);
        $token = $data['verify_token'];
        unset($data['verify_token']);
        $data['verify_token_hash'] = hash('sha256', $token);
        $data['status'] ??= $integration->status === 'active' ? 'active' : 'disconnected';
        $account = WhatsAppAccount::create($data);
        if ($channel->integration_id === null) {
            $channel->update([
                'integration_id' => $integration->id,
                'external_identifier' => $account->phone_number_id,
                'address' => $account->display_phone_number,
            ]);
        }
        $this->audit->record('create', $account, newValues: $this->auditValues($account));

        return ApiResponse::success($account->load($this->relations()), [
            'webhook_url' => url('/api/v1/webhooks/whatsapp'),
            'verify_token_stored' => false,
        ], 201);
    }

    public function show(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'whatsapp.view');

        return ApiResponse::success(WhatsAppAccount::with($this->relations())->findOrFail($account), [
            'webhook_url' => url('/api/v1/webhooks/whatsapp'),
        ]);
    }

    public function update(WhatsAppAccountRequest $request, int $account): JsonResponse
    {
        $this->permission($request, 'whatsapp.manage');
        $model = WhatsAppAccount::findOrFail($account);
        $data = $request->validated();
        foreach (['integration_id', 'inbox_channel_id', 'phone_number_id'] as $immutable) {
            if (isset($data[$immutable]) && (string) $data[$immutable] !== (string) $model->{$immutable}) {
                throw ValidationException::withMessages([$immutable => 'This field cannot change after account creation.']);
            }
        }
        if (isset($data['verify_token'])) {
            $data['verify_token_hash'] = hash('sha256', $data['verify_token']);
            unset($data['verify_token']);
        }
        $old = $this->auditValues($model);
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load($this->relations()));
    }

    public function templates(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'whatsapp.view');
        $model = WhatsAppAccount::findOrFail($account);

        return ApiResponse::paginated($model->templates()->orderBy('name')->orderBy('language')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function syncTemplates(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'whatsapp.manage');
        $model = WhatsAppAccount::with('integration')->findOrFail($account);
        try {
            $result = $this->templates->sync($model);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['templates' => $exception->getMessage()]);
        }
        $this->audit->record('whatsapp_templates_sync', $model, newValues: $result);

        return ApiResponse::success($result);
    }

    private function assertLinks(InboxChannel $channel, Integration $integration, string $phoneNumberId): void
    {
        if ($channel->channel !== 'whatsapp') {
            throw ValidationException::withMessages(['inbox_channel_id' => 'The selected inbox channel is not WhatsApp.']);
        }
        if ($integration->provider !== 'whatsapp') {
            throw ValidationException::withMessages(['integration_id' => 'The selected integration is not WhatsApp.']);
        }
        if ((string) data_get($integration->credentials, 'phone_number_id') !== $phoneNumberId) {
            throw ValidationException::withMessages(['phone_number_id' => 'The phone number id does not match the encrypted integration credentials.']);
        }
        if (blank(data_get($integration->credentials, 'app_secret'))) {
            throw ValidationException::withMessages(['integration_id' => 'An app_secret is required to validate WhatsApp webhook signatures.']);
        }
        if ($channel->integration_id !== null && (int) $channel->integration_id !== (int) $integration->id) {
            throw ValidationException::withMessages(['integration_id' => 'The inbox channel is linked to another integration.']);
        }
    }

    private function permission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403, 'You cannot manage WhatsApp accounts.');
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'channel:id,inbox_id,channel,name,address,status',
            'integration:id,provider,name,status,last_synced_at',
        ];
    }

    /** @return array<string, mixed> */
    private function auditValues(WhatsAppAccount $account): array
    {
        return [
            'phone_number_id' => $account->phone_number_id,
            'business_account_id' => $account->business_account_id,
            'inbox_channel_id' => $account->inbox_channel_id,
            'integration_id' => $account->integration_id,
            'status' => $account->status,
        ];
    }
}
