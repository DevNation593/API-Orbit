<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\InboxChannel;
use App\Models\SequenceEnrollment;
use App\Models\SequenceExecution;
use App\Models\SequenceStep;
use App\Models\Task;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SequenceRunner
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConversationMessageService $messages,
        private readonly SequenceTemplateRenderer $templates,
        private readonly RuleConditionEvaluator $conditions,
        private readonly NotificationDispatcher $notifications,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    public function run(SequenceEnrollment $enrollment): ?SequenceExecution
    {
        $claim = $this->claim($enrollment);
        if ($claim === null) {
            return null;
        }
        [$enrollment, $step, $execution] = $claim;

        try {
            $output = $this->execute($enrollment, $step, $execution);
            $this->complete($enrollment, $step, $execution, $output);

            return $execution->fresh(['step']);
        } catch (Throwable $exception) {
            $execution->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
            $enrollment->update(['next_run_at' => now()->addMinutes(5)]);
            throw $exception;
        }
    }

    /** @return array{0: SequenceEnrollment, 1: SequenceStep, 2: SequenceExecution}|null */
    private function claim(SequenceEnrollment $enrollment): ?array
    {
        return $this->database->transaction(function () use ($enrollment): ?array {
            $model = SequenceEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            if ($model->status !== 'active' || $model->next_run_at === null || $model->next_run_at->isFuture()) {
                return null;
            }
            $model->load('sequence.steps');
            if ($model->sequence->status !== 'active') {
                $model->update(['status' => 'paused', 'stop_reason' => 'sequence_not_active']);

                return null;
            }
            $step = $model->sequence->steps->where('active', true)
                ->where('position', '>=', $model->current_position)->sortBy('position')->first();
            if ($step === null) {
                $model->update(['status' => 'completed', 'completed_at' => now(), 'next_run_at' => null]);

                return null;
            }
            $key = 'enrollment:'.$model->id.':step:'.$step->id;
            $execution = SequenceExecution::query()->firstOrCreate([
                'idempotency_key' => $key,
            ], [
                'sequence_enrollment_id' => $model->id,
                'sequence_step_id' => $step->id,
                'status' => 'queued',
                'scheduled_for' => $model->next_run_at,
            ]);
            if ($execution->status === 'completed') {
                $this->advance($model, $step, $execution->output ?? []);

                return null;
            }
            $execution->update([
                'status' => 'running',
                'attempts' => $execution->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'error' => null,
            ]);
            $model->update(['next_run_at' => null]);

            return [$model, $step, $execution];
        });
    }

    /** @return array<string, mixed> */
    private function execute(SequenceEnrollment $enrollment, SequenceStep $step, SequenceExecution $execution): array
    {
        return match ($step->type) {
            'email', 'whatsapp', 'sms' => $this->message($enrollment, $step, $execution),
            'task', 'call_task' => $this->task($enrollment, $step, $execution),
            'wait' => ['wait_minutes' => (int) data_get($step->config, 'wait_minutes', 1)],
            'condition' => $this->condition($enrollment, $step),
            'notification' => $this->notification($enrollment, $step),
            default => throw ValidationException::withMessages(['step' => 'Unsupported sequence step type.']),
        };
    }

    /** @return array<string, mixed> */
    private function message(SequenceEnrollment $enrollment, SequenceStep $step, SequenceExecution $execution): array
    {
        $channel = InboxChannel::with('inbox')->whereKey(data_get($step->config, 'inbox_channel_id'))
            ->where('channel', $step->type)->where('status', 'active')->firstOrFail();
        $enrollment->loadMissing(['lead.contact', 'contact', 'sender']);
        $contact = $enrollment->contact ?? $enrollment->lead?->contact;
        $recipient = $step->type === 'email'
            ? ($contact?->email ?? $enrollment->lead?->email)
            : ($contact?->phone ?? $enrollment->lead?->phone);
        $conversation = Conversation::query()->firstOrCreate([
            'inbox_channel_id' => $channel->id,
            'external_identifier' => 'sequence:'.$enrollment->id.':'.$channel->id,
        ], [
            'inbox_id' => $channel->inbox_id,
            'contact_id' => $contact?->id,
            'assigned_user_id' => $enrollment->sender_user_id,
            'channel' => $channel->channel,
            'subject' => $this->templates->render((string) data_get($step->config, 'subject', ''), $enrollment) ?: null,
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $conversation->participants()->firstOrCreate([
            'type' => 'external',
            'external_identifier' => (string) $recipient,
        ], [
            'contact_id' => $contact?->id,
            'role' => 'customer',
            'display_name' => trim(($contact?->first_name ?? $enrollment->lead?->first_name ?? '').' '.($contact?->last_name ?? $enrollment->lead?->last_name ?? '')),
        ]);
        $template = data_get($step->config, 'template');
        $data = [
            'type' => $step->type === 'email' ? 'email' : (is_array($template) ? 'template' : 'text'),
            'subject' => $this->templates->render((string) data_get($step->config, 'subject', ''), $enrollment) ?: null,
            'body' => $this->templates->render((string) data_get($step->config, 'body', ''), $enrollment),
            'content' => array_filter([
                'to' => $recipient,
                'template' => is_array($template) ? $this->templates->renderValue($template, $enrollment) : null,
            ], fn ($value) => $value !== null),
            'client_message_id' => 'sequence-execution-'.$execution->id,
        ];
        $result = $this->messages->send($conversation, $enrollment->sender, $data);

        return ['message_id' => (int) $result['message']->id, 'conversation_id' => (int) $conversation->id, 'replayed' => $result['replayed']];
    }

    /** @return array<string, mixed> */
    private function task(SequenceEnrollment $enrollment, SequenceStep $step, SequenceExecution $execution): array
    {
        $target = $enrollment->lead ?? $enrollment->contact;
        $task = Task::create([
            'title' => $this->templates->render((string) data_get($step->config, 'title'), $enrollment),
            'description' => $this->templates->render((string) data_get($step->config, 'description', ''), $enrollment) ?: null,
            'assigned_to' => $enrollment->sender_user_id,
            'created_by' => $enrollment->enrolled_by,
            'status' => 'pending',
            'priority' => data_get($step->config, 'priority', 'normal'),
            'due_at' => now()->addMinutes((int) data_get($step->config, 'due_in_minutes', 1440)),
            'related_type' => $target?->getMorphClass(),
            'related_id' => $target?->getKey(),
            'custom_fields' => ['sequence_execution_id' => $execution->id, 'task_type' => $step->type],
        ]);

        return ['task_id' => (int) $task->id];
    }

    /** @return array<string, mixed> */
    private function condition(SequenceEnrollment $enrollment, SequenceStep $step): array
    {
        $target = $enrollment->lead ?? $enrollment->contact;
        $passed = $target !== null && $this->conditions->matches(
            (array) data_get($step->config, 'conditions', []),
            $target,
            (string) data_get($step->config, 'match_type', 'all'),
        );

        return [
            'condition_passed' => $passed,
            'directive' => $passed ? 'continue' : (string) data_get($step->config, 'on_false', 'continue'),
        ];
    }

    /** @return array<string, mixed> */
    private function notification(SequenceEnrollment $enrollment, SequenceStep $step): array
    {
        $recipient = match (data_get($step->config, 'recipient', 'owner')) {
            'user' => User::find(data_get($step->config, 'user_id')),
            'sender' => $enrollment->sender,
            default => $enrollment->lead?->owner ?? $enrollment->contact?->owner ?? $enrollment->sender,
        };
        if ($recipient === null || ! TenantUser::query()->where('tenant_id', $this->context->requireId())
            ->where('user_id', $recipient->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['recipient' => 'The sequence notification has no active recipient.']);
        }
        $this->notifications->send(
            $recipient,
            (string) data_get($step->config, 'event', 'sequence.notification'),
            $this->templates->render((string) data_get($step->config, 'title'), $enrollment),
            $this->templates->render((string) data_get($step->config, 'body'), $enrollment),
            ['sequence_id' => (int) $enrollment->sequence_id, 'enrollment_id' => (int) $enrollment->id],
        );

        return ['notified_user_id' => (int) $recipient->id];
    }

    /** @param array<string, mixed> $output */
    private function complete(SequenceEnrollment $enrollment, SequenceStep $step, SequenceExecution $execution, array $output): void
    {
        $this->database->transaction(function () use ($enrollment, $step, $execution, $output): void {
            $model = SequenceEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $execution->update(['status' => 'completed', 'output' => $output, 'error' => null, 'finished_at' => now()]);
            if ($model->status === 'active') {
                $this->advance($model, $step, $output);
            }
            $this->audit->record('sequence_step_completed', $execution, newValues: [
                'enrollment_id' => (int) $model->id,
                'step_id' => (int) $step->id,
                'step_type' => $step->type,
            ]);
        });
    }

    /** @param array<string, mixed> $output */
    private function advance(SequenceEnrollment $enrollment, SequenceStep $step, array $output): void
    {
        if (($output['directive'] ?? null) === 'stop') {
            $enrollment->update([
                'status' => 'stopped', 'stop_reason' => 'condition_not_met',
                'stopped_at' => now(), 'next_run_at' => null, 'last_executed_at' => now(),
            ]);

            return;
        }
        $steps = $enrollment->sequence->steps->where('active', true)->where('position', '>', $step->position)->sortBy('position')->values();
        if (($output['directive'] ?? null) === 'skip_next') {
            $steps = $steps->slice(1)->values();
        }
        $next = $steps->first();
        if ($next === null) {
            $enrollment->update([
                'status' => 'completed', 'completed_at' => now(),
                'last_executed_at' => now(), 'next_run_at' => null,
            ]);

            return;
        }
        $wait = (int) ($output['wait_minutes'] ?? 0);
        $enrollment->update([
            'current_position' => $next->position,
            'last_executed_at' => now(),
            'next_run_at' => now()->addMinutes($wait + $next->delay_minutes),
        ]);
    }
}
