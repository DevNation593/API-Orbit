<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Services\WorkflowEngine;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $automationId,
        public readonly string $eventType,
        public readonly string $eventId,
        public readonly string $modelType,
        public readonly int $modelId,
    ) {}

    public function handle(WorkflowEngine $engine): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        try {
            $run = AutomationRun::query()->firstOrCreate(
                ['automation_id' => $this->automationId, 'event_id' => $this->eventId],
                ['status' => 'running', 'attempts' => 1, 'started_at' => now()],
            );
            if ($run->status === 'completed') {
                return;
            }

            $automation = Automation::query()->whereKey($this->automationId)->where('active', true)->firstOrFail();
            /** @var Model $model */
            $model = $this->modelType::query()->findOrFail($this->modelId);
            $engine->run($automation, $model);
            $run->update(['status' => 'completed', 'finished_at' => now(), 'attempts' => $run->attempts + 1]);
        } catch (\Throwable $exception) {
            AutomationRun::query()->where('automation_id', $this->automationId)->where('event_id', $this->eventId)->update([
                'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'finished_at' => now(),
            ]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
