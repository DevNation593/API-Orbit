<?php

namespace App\Http\Requests;

use App\Models\InboxChannel;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SequenceRequest extends BaseApiRequest
{
    public const STEP_TYPES = [
        'email', 'whatsapp', 'sms', 'call_task', 'task', 'wait', 'condition', 'notification',
    ];

    public const STOP_CONDITIONS = [
        'email_reply', 'whatsapp_reply', 'meeting_booked', 'opportunity_created', 'deal_won', 'manual_stop',
    ];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sequences.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('sequences', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('sequence')),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused', 'archived'])],
            'stop_conditions' => ['sometimes', 'array', 'max:6'],
            'stop_conditions.*' => ['string', 'distinct', Rule::in(self::STOP_CONDITIONS)],
            'settings' => ['nullable', 'array', 'max:30'],
            'steps' => [$required, 'array', 'between:1,100'],
            'steps.*' => ['array', 'max:20'],
            'steps.*.position' => ['sometimes', 'integer', 'between:0,65535', 'distinct'],
            'steps.*.type' => ['required', Rule::in(self::STEP_TYPES)],
            'steps.*.delay_minutes' => ['sometimes', 'integer', 'between:0,525600'],
            'steps.*.active' => ['sometimes', 'boolean'],
            'steps.*.config' => ['required', 'array', 'max:50'],
            'steps.*.config.inbox_channel_id' => ['sometimes', 'integer'],
            'steps.*.config.subject' => ['nullable', 'string', 'max:255'],
            'steps.*.config.body' => ['nullable', 'string', 'max:100000'],
            'steps.*.config.template' => ['nullable', 'array', 'max:20'],
            'steps.*.config.title' => ['nullable', 'string', 'max:255'],
            'steps.*.config.description' => ['nullable', 'string', 'max:10000'],
            'steps.*.config.priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'steps.*.config.wait_minutes' => ['sometimes', 'integer', 'between:1,525600'],
            'steps.*.config.conditions' => ['sometimes', 'array', 'max:50'],
            'steps.*.config.conditions.*.field' => ['required_with:steps.*.config.conditions', 'string', 'max:190', 'regex:/^[A-Za-z][A-Za-z0-9_.]*$/'],
            'steps.*.config.conditions.*.operator' => ['required_with:steps.*.config.conditions', Rule::in(LeadRoutingRuleRequest::OPERATORS)],
            'steps.*.config.conditions.*.value' => ['nullable'],
            'steps.*.config.match_type' => ['sometimes', Rule::in(['all', 'any'])],
            'steps.*.config.on_false' => ['sometimes', Rule::in(['continue', 'stop', 'skip_next'])],
            'steps.*.config.recipient' => ['sometimes', Rule::in(['sender', 'owner', 'user'])],
            'steps.*.config.user_id' => ['nullable', 'integer', $this->memberRule()],
            'steps.*.config.event' => ['nullable', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_.-]*$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('steps', []) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }
                $type = $step['type'] ?? null;
                $config = is_array($step['config'] ?? null) ? $step['config'] : [];
                if (in_array($type, ['email', 'whatsapp', 'sms'], true)) {
                    $channelId = $config['inbox_channel_id'] ?? null;
                    $channel = $channelId === null ? null : InboxChannel::query()->find($channelId);
                    if ($channel === null || $channel->channel !== $type) {
                        $validator->errors()->add("steps.$index.config.inbox_channel_id", "An active {$type} channel from this tenant is required.");
                    } elseif ($channel->status !== 'active') {
                        $validator->errors()->add("steps.$index.config.inbox_channel_id", 'The selected channel is not active.');
                    }
                }
                if ($type === 'email' && blank($config['subject'] ?? null)) {
                    $validator->errors()->add("steps.$index.config.subject", 'Email steps require a subject.');
                }
                if (in_array($type, ['email', 'sms'], true) && blank($config['body'] ?? null)) {
                    $validator->errors()->add("steps.$index.config.body", 'This message step requires a body.');
                }
                if ($type === 'whatsapp' && blank($config['body'] ?? null) && ! is_array($config['template'] ?? null)) {
                    $validator->errors()->add("steps.$index.config.body", 'WhatsApp steps require a body or template.');
                }
                if (in_array($type, ['task', 'call_task'], true) && blank($config['title'] ?? null)) {
                    $validator->errors()->add("steps.$index.config.title", 'Task steps require a title.');
                }
                if ($type === 'wait' && empty($config['wait_minutes'])) {
                    $validator->errors()->add("steps.$index.config.wait_minutes", 'Wait steps require wait_minutes.');
                }
                if ($type === 'condition' && ! is_array($config['conditions'] ?? null)) {
                    $validator->errors()->add("steps.$index.config.conditions", 'Condition steps require conditions.');
                }
                if ($type === 'notification' && (blank($config['title'] ?? null) || blank($config['body'] ?? null))) {
                    $validator->errors()->add("steps.$index.config", 'Notification steps require title and body.');
                }
                if (($config['recipient'] ?? null) === 'user' && empty($config['user_id'])) {
                    $validator->errors()->add("steps.$index.config.user_id", 'A user recipient is required.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('status'))) {
            $data['status'] = mb_strtolower(trim($this->input('status')));
        }
        $steps = $this->input('steps');
        if (is_array($steps)) {
            foreach ($steps as &$step) {
                if (is_array($step) && is_string($step['type'] ?? null)) {
                    $step['type'] = mb_strtolower(trim($step['type']));
                }
            }
            unset($step);
            $data['steps'] = $steps;
        }
        if (is_array($this->input('stop_conditions'))) {
            $data['stop_conditions'] = array_map(fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value, $this->input('stop_conditions'));
        }
        $this->merge($data);
    }
}
