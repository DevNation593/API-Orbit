<?php

namespace App\Jobs;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\Segment;
use App\Models\Tenant;
use App\Services\CampaignService;
use App\Services\JourneyRunner;
use App\Services\JourneyService;
use App\Services\MarketingAudienceService;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMarketingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $tenantId, public readonly string $kind, public readonly int $recordId)
    {
        $this->onQueue('marketing');
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->kind.':'.$this->recordId;
    }

    public function handle(TenantContext $context): void
    {
        $previous = $context->id();
        $context->set($this->tenantId);
        try {
            if (! Tenant::whereKey($this->tenantId)->where('status', 'active')->exists()) {
                return;
            }
            switch ($this->kind) {
                case 'segment':
                    app(MarketingAudienceService::class)->refreshSegment(Segment::findOrFail($this->recordId));
                    break;
                case 'audience':
                    app(MarketingAudienceService::class)->refreshAudience(Audience::findOrFail($this->recordId));
                    break;
                case 'campaign':
                    app(CampaignService::class)->process(Campaign::findOrFail($this->recordId));
                    break;
                case 'journey':
                    app(JourneyRunner::class)->run(JourneyEnrollment::findOrFail($this->recordId));
                    break;
                case 'journey_resume':
                    $journey = Journey::findOrFail($this->recordId);
                    if ($journey->status === 'active') {
                        foreach ($journey->enrollments()->lazyById(500) as $enrollment) {
                            app(JourneyService::class)->resumeMessages($enrollment);
                        }
                    }
                    break;
                default:
                    throw new \InvalidArgumentException('Unsupported marketing job.');
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        try {
            if ($this->kind === 'journey') {
                $model = JourneyEnrollment::whereKey($this->recordId)->where('status', 'active')->first();
                $model?->update(['status' => 'failed', 'next_run_at' => null, 'stop_reason' => 'execution_failed']);
            } elseif ($this->kind === 'campaign') {
                $model = Campaign::whereKey($this->recordId)->whereIn('status', ['sending', 'scheduled'])->first();
                $model?->update(['status' => 'paused']);
            }
            app(AuditService::class)->record('marketing_job_failed', 'marketing_job', $this->recordId,
                newValues: ['kind' => $this->kind, 'error_class' => $exception === null ? null : $exception::class]);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
