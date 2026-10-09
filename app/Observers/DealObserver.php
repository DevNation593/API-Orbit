<?php

namespace App\Observers;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\TimelineEventRecorder;

class DealObserver
{
    public function __construct(private readonly TimelineEventRecorder $timeline) {}

    public function saving(Deal $deal): void
    {
        $stage = PipelineStage::query()->find($deal->stage_id);
        $status = strtolower((string) $deal->status);
        if ($status === 'won' || $stage?->is_won) {
            $deal->status = 'won';
            $deal->forecast_category = 'closed';
            $deal->closed_at ??= now();
        } elseif ($status === 'lost' || $stage?->is_lost) {
            $deal->status = 'lost';
            $deal->forecast_category = 'omitted';
            $deal->closed_at ??= now();
        } else {
            $deal->closed_at = null;
            if (! in_array($deal->forecast_category, ['omitted', 'pipeline', 'best_case', 'commit'], true)) {
                $deal->forecast_category = 'pipeline';
            }
        }
    }

    public function created(Deal $deal): void
    {
        $this->timeline->record($deal, 'opportunity.created', [
            'pipeline_id' => (int) $deal->pipeline_id,
            'stage_id' => (int) $deal->stage_id,
        ]);
        $this->recordOutcome($deal, true);
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('stage_id')) {
            $this->timeline->record($deal, 'opportunity.stage_changed', [
                'old_stage_id' => (int) $deal->getOriginal('stage_id'),
                'new_stage_id' => (int) $deal->stage_id,
            ]);
        }

        if ($deal->wasChanged(['stage_id', 'status'])) {
            $this->recordOutcome($deal);
        }
    }

    private function recordOutcome(Deal $deal, bool $onCreate = false): void
    {
        $status = strtolower((string) $deal->status);
        $stage = $deal->stage()->first(['id', 'is_won', 'is_lost']);
        if ($status === 'won' || $stage?->is_won) {
            if ($onCreate || $deal->getOriginal('status') !== 'won' || $deal->wasChanged('stage_id')) {
                $this->timeline->record($deal, 'opportunity.won', ['stage_id' => (int) $deal->stage_id]);
            }
        } elseif ($status === 'lost' || $stage?->is_lost) {
            if ($onCreate || $deal->getOriginal('status') !== 'lost' || $deal->wasChanged('stage_id')) {
                $this->timeline->record($deal, 'opportunity.lost', ['stage_id' => (int) $deal->stage_id]);
            }
        }
    }
}
