<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailAccountRequest;
use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\InboxChannel;
use App\Models\Integration;
use App\Services\HtmlSanitizer;
use App\Services\IntegrationManager;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailAccountController extends Controller
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly HtmlSanitizer $html,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permission($request, 'email_accounts.view');
        $request->validate(['provider' => ['sometimes', 'in:google,microsoft,smtp'], 'status' => ['sometimes', 'string', 'max:30']]);
        $query = EmailAccount::query()->with($this->relations());
        foreach (['provider', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return ApiResponse::paginated($query->orderBy('email_address')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(EmailAccountRequest $request): JsonResponse
    {
        $this->permission($request, 'email_accounts.manage');
        $data = $request->validated();
        [$channel, $integration] = $this->assertLinks($data);
        $this->assertUnique($data['provider'], $data['email_address']);
        $data['signature_html'] = $this->html->sanitize($data['signature_html'] ?? null);
        $data['status'] ??= $integration->status === 'active' ? 'active' : 'disconnected';
        $account = EmailAccount::create($data);
        if ($channel->integration_id === null) {
            $channel->update(['integration_id' => $integration->id, 'address' => $account->email_address]);
        }
        $this->audit->record('create', $account, newValues: $this->auditValues($account));

        return ApiResponse::success($account->load($this->relations()), [], 201);
    }

    public function show(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'email_accounts.view');

        return ApiResponse::success(EmailAccount::with($this->relations())->findOrFail($account));
    }

    public function update(EmailAccountRequest $request, int $account): JsonResponse
    {
        $this->permission($request, 'email_accounts.manage');
        $model = EmailAccount::findOrFail($account);
        $data = $request->validated();
        foreach (['provider', 'integration_id', 'inbox_channel_id'] as $immutable) {
            if (isset($data[$immutable]) && (string) $data[$immutable] !== (string) $model->{$immutable}) {
                throw ValidationException::withMessages([$immutable => 'This field cannot change after account creation.']);
            }
        }
        if (isset($data['email_address'])) {
            $this->assertUnique($model->provider, $data['email_address'], $model->id);
        }
        if (array_key_exists('signature_html', $data)) {
            $data['signature_html'] = $this->html->sanitize($data['signature_html']);
        }
        $old = $this->auditValues($model);
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load($this->relations()));
    }

    public function connect(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'email_accounts.manage');
        $model = EmailAccount::with('integration')->findOrFail($account);
        try {
            $this->integrations->connect($model->integration);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['connection' => $exception->getMessage()]);
        }
        $model->integration->update(['status' => 'active', 'last_synced_at' => now()]);
        $model->update(['status' => 'active']);
        $this->audit->record('connect', $model, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load($this->relations()));
    }

    public function disconnect(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'email_accounts.manage');
        $model = EmailAccount::with('integration')->findOrFail($account);
        $this->integrations->disconnect($model->integration);
        $model->integration->update(['status' => 'disabled']);
        $model->update(['status' => 'disconnected']);
        $this->audit->record('disconnect', $model, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load($this->relations()));
    }

    public function sync(Request $request, int $account): JsonResponse
    {
        $this->permission($request, 'email_accounts.manage');
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,100']]);
        $model = EmailAccount::with('integration')->findOrFail($account);
        if ($model->status !== 'active' || $model->integration?->status !== 'active') {
            throw ValidationException::withMessages(['account' => 'The email account must be connected before synchronization.']);
        }
        SyncEmailAccountJob::dispatch(
            (int) $model->tenant_id,
            (int) $model->id,
            (int) ($data['limit'] ?? 100),
        );
        $this->audit->record('email_sync_requested', $model, newValues: ['limit' => (int) ($data['limit'] ?? 100)]);

        return ApiResponse::success([
            'account_id' => (int) $model->id,
            'status' => 'queued',
        ], [], 202);
    }

    /** @param array<string, mixed> $data
     * @return array{0: InboxChannel, 1: Integration}
     */
    private function assertLinks(array $data): array
    {
        $channel = InboxChannel::findOrFail($data['inbox_channel_id']);
        $integration = Integration::findOrFail($data['integration_id']);
        if ($channel->channel !== 'email') {
            throw ValidationException::withMessages(['inbox_channel_id' => 'The selected inbox channel is not email.']);
        }
        if ($integration->provider !== $data['provider']) {
            throw ValidationException::withMessages(['integration_id' => 'The integration provider does not match the email account.']);
        }
        if ($channel->integration_id !== null && (int) $channel->integration_id !== (int) $integration->id) {
            throw ValidationException::withMessages(['integration_id' => 'The inbox channel is linked to another integration.']);
        }

        return [$channel, $integration];
    }

    private function assertUnique(string $provider, string $email, ?int $ignore = null): void
    {
        $exists = EmailAccount::query()->where('provider', $provider)->whereRaw('LOWER(email_address) = ?', [mb_strtolower($email)])
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['email_address' => 'This provider account is already registered.']);
        }
    }

    private function permission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403, 'You cannot manage email accounts.');
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['channel:id,inbox_id,channel,name,address,status', 'integration:id,provider,name,status,last_synced_at', 'user:id,name,email'];
    }

    /** @return array<string, mixed> */
    private function auditValues(EmailAccount $account): array
    {
        return [
            'provider' => $account->provider, 'email_address' => $account->email_address,
            'inbox_channel_id' => $account->inbox_channel_id, 'integration_id' => $account->integration_id,
            'status' => $account->status,
        ];
    }
}
