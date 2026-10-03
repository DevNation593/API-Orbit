<?php

namespace App\Jobs;

use App\Models\SequenceEnrollment;
use App\Services\SequenceRunner;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSequenceEnrollmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $tenantId, public readonly int $enrollmentId) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->enrollmentId;
    }

    public function handle(SequenceRunner $runner, TenantContext $context): void
    {
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            $runner->run(SequenceEnrollment::findOrFail($this->enrollmentId));
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            SequenceEnrollment::query()->whereKey($this->enrollmentId)->where('status', 'active')->update([
                'status' => 'failed',
                'stop_reason' => 'sequence_execution_failed',
                'next_run_at' => null,
                'stopped_at' => now(),
            ]);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
