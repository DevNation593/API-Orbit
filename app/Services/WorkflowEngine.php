<?php

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Models\Automation;
use App\Models\Task;
use App\Models\TenantUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class WorkflowEngine
{
    public function run(Automation $automation, Model $model): void
    {
        $config = $automation->config ?? [];
        if (! $this->conditionsPass($config['conditions'] ?? [], $model)) {
            return;
        }

        foreach ($config['actions'] ?? [] as $action) {
            $this->executeAction($action, $model);
        }
    }

    private function conditionsPass(array $conditions, Model $model): bool
    {
        foreach ($conditions as $condition) {
            $actual = $this->value($model, (string) ($condition['field'] ?? ''));
            $expected = $condition['value'] ?? null;
            $operator = $condition['operator'] ?? 'eq';
            $pass = match ($operator) {
                'eq' => $actual == $expected,
                'neq' => $actual != $expected,
                'contains' => is_string($actual) && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected)),
                'starts_with' => is_string($actual) && str_starts_with(mb_strtolower($actual), mb_strtolower((string) $expected)),
                'gt' => $actual > $expected,
                'gte' => $actual >= $expected,
                'lt' => $actual < $expected,
                'lte' => $actual <= $expected,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
                'is_null' => $actual === null,
                'not_null' => $actual !== null,
                default => false,
            };

            if (! $pass) {
                return false;
            }
        }

        return true;
    }

    private function value(Model $model, string $field): mixed
    {
        if (str_starts_with($field, 'custom_fields.')) {
            return Arr::get($model->getAttribute('custom_fields') ?? [], substr($field, 14));
        }
        if (str_starts_with($field, 'data.')) {
            return Arr::get($model->getAttribute('data') ?? [], substr($field, 5));
        }

        return $model->getAttribute($field);
    }

    private function executeAction(array $action, Model $model): void
    {
        match ($action['type']) {
            'assign_user' => $this->assignUser($action, $model),
            'create_task' => $this->createTask($action, $model),
            'send_email' => $this->sendEmail($action, $model),
            'webhook' => $this->webhook($action, $model),
            default => null,
        };
    }

    private function assignUser(array $action, Model $model): void
    {
        if (! in_array('owner_id', $model->getFillable(), true) || ! TenantUser::query()
            ->where('tenant_id', app(TenantContext::class)->requireId())
            ->where('user_id', $action['user_id'])
            ->where('status', 'active')
            ->exists()) {
            return;
        }
        $model->update(['owner_id' => (int) $action['user_id']]);
    }

    private function createTask(array $action, Model $model): void
    {
        $assignedTo = null;
        if (isset($action['assigned_to']) && TenantUser::query()
            ->where('tenant_id', app(TenantContext::class)->requireId())
            ->where('user_id', $action['assigned_to'])
            ->where('status', 'active')
            ->exists()) {
            $assignedTo = (int) $action['assigned_to'];
        }

        Task::create([
            'title' => (string) $action['title'],
            'description' => $action['description'] ?? null,
            'assigned_to' => $assignedTo,
            'status' => 'pending',
            'priority' => $action['priority'] ?? 'normal',
            'due_at' => $action['due_at'] ?? null,
            'related_type' => $model->getMorphClass(),
            'related_id' => (string) $model->getKey(),
        ]);
    }

    private function sendEmail(array $action, Model $model): void
    {
        $to = $action['to'];
        if (str_starts_with($to, 'field:')) {
            $to = $this->value($model, substr($to, 6));
        }
        if (! is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        Mail::raw((string) ($action['body'] ?? 'An automation was triggered.'), function ($message) use ($to, $action): void {
            $message->to($to)->subject((string) ($action['subject'] ?? 'CRM notification'));
        });
    }

    private function webhook(array $action, Model $model): void
    {
        $endpoint = WebhookEndpoint::query()->whereKey($action['endpoint_id'])->where('active', true)->first();
        if ($endpoint === null) {
            return;
        }
        $payload = ['event' => 'automation.triggered', 'data' => ['type' => $model->getMorphClass(), 'id' => $model->getKey()]];
        $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $endpoint->secret);
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'automation.triggered',
            'event_id' => (string) Str::uuid(),
            'payload' => $payload,
            'signature' => $signature,
            'status' => 'pending',
        ]);
        DeliverWebhookJob::dispatch((int) $delivery->tenant_id, (int) $delivery->id)->onQueue('integrations');
    }
}
