<?php

namespace App\Services;

use App\Events\JourneyNodeExecuted;
use App\Models\JourneyEnrollment;
use App\Models\JourneyExecution;
use App\Models\Task;
use App\Models\TenantUser;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

final class JourneyRunner
{
    public function __construct(
        private readonly SegmentEngine $entities,
        private readonly ConsentService $consent,
        private readonly MarketingMessageService $messages,
        private readonly AuditService $audit,
    ) {}

    public function run(JourneyEnrollment $enrollment): void
    {
        // One node per transaction: retries cannot duplicate committed tasks or queued messages.
        DB::transaction(function () use ($enrollment): void {
            $enrollment = JourneyEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            if ($enrollment->status !== 'active' || $enrollment->next_run_at === null ||
                $enrollment->next_run_at->isFuture() || $enrollment->journey->status !== 'active') {
                return;
            }
            $subject = $this->entities->base($enrollment->entity_type)->find($enrollment->entity_id);
            if ($subject === null || ! TenantUser::where('tenant_id', $enrollment->tenant_id)
                ->where('user_id', $enrollment->sender_user_id)->where('status', 'active')->exists()) {
                $enrollment->update(['status' => 'cancelled', 'stop_reason' => 'recipient_or_sender_unavailable', 'next_run_at' => null]);

                return;
            }
            $node = collect($enrollment->version->graph['nodes'])->firstWhere('id', $enrollment->current_node);
            abort_if($node === null, 409, 'The published journey node is missing.');
            $config = $node['config'] ?? [];
            $execution = JourneyExecution::firstOrCreate([
                'journey_enrollment_id' => $enrollment->id, 'node_id' => $node['id'],
            ], ['status' => 'running']);
            if ($execution->status === 'completed') {
                return;
            }
            $output = [];
            $next = $node['next'] ?? null;
            $delay = 0;
            $goal = false;
            switch ($node['type']) {
                case 'wait':
                    $delay = (int) $config['wait_minutes'];
                    $output = ['wait_minutes' => $delay];
                    break;
                case 'condition':
                case 'goal':
                    $passed = $this->entities->query($enrollment->entity_type, $config['definition'])->whereKey($subject->id)->exists();
                    $output = ['matched' => $passed];
                    if ($node['type'] === 'condition') {
                        $next = $passed ? $node['on_true'] : $node['on_false'];
                    } elseif ($passed) {
                        $goal = true;
                        $next = null;
                    }
                    break;
                case 'task':
                    $task = Task::create([
                        'title' => $config['title'], 'description' => $config['description'] ?? null,
                        'assigned_to' => $enrollment->sender_user_id, 'created_by' => $enrollment->sender_user_id,
                        'status' => 'pending', 'priority' => $config['priority'] ?? 'normal',
                        'due_at' => now()->addMinutes((int) ($config['due_in_minutes'] ?? 1440)),
                        'related_type' => $subject->getMorphClass(), 'related_id' => $subject->id,
                        'custom_fields' => ['journey_execution_id' => (int) $execution->id],
                    ]);
                    $output = ['task_id' => (int) $task->id];
                    break;
                case 'email':
                    if (! $this->consent->allowed($subject, 'email')) {
                        $output = ['skipped' => true, 'reason' => 'consent_required'];
                        break;
                    }
                    $message = $this->messages->enqueue($subject, $enrollment->entity_type,
                        (int) $enrollment->sender_user_id, $config, 'journey-execution-'.$execution->id,
                        ['journey_enrollment_id' => (int) $enrollment->id, 'journey_execution_id' => (int) $execution->id]);
                    $output = ['message_id' => (int) $message->id];
                    break;
            }
            $execution->update(['status' => 'completed', 'output' => $output, 'finished_at' => now()]);
            $enrollment->update([
                'current_node' => $next ?? $node['id'],
                'status' => $next === null ? 'completed' : 'active',
                'next_run_at' => $next === null ? null : now()->addMinutes($delay),
                'completed_at' => $next === null ? now() : null,
                'goal_reached_at' => $goal ? now() : $enrollment->goal_reached_at,
            ]);
            $this->audit->record('journey_node_completed', $execution, newValues: ['node_type' => $node['type'], 'output' => $output]);
            JourneyNodeExecuted::dispatch($execution);
        });
    }
}
