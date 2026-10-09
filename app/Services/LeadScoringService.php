<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadScore;
use App\Models\ScoringEvent;
use App\Models\ScoringModel;
use App\Models\ScoringRule;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

final class LeadScoringService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuleConditionEvaluator $conditions,
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, LeadScore> */
    public function recalculate(Lead $lead, ?ScoringModel $onlyModel = null): Collection
    {
        $models = $onlyModel === null
            ? ScoringModel::query()->with(['rules.conditions'])->where('active', true)->orderByDesc('is_default')->orderBy('id')->get()
            : collect([$onlyModel->loadMissing(['rules.conditions'])]);
        $primaryId = $models->firstWhere('is_default', true)?->id ?? $models->first()?->id;

        return $models->map(function (ScoringModel $model) use ($lead, $primaryId): LeadScore {
            return $this->database->transaction(function () use ($model, $lead, $primaryId): LeadScore {
                $lockedLead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
                $explicit = [];
                $explicitTotal = 0;
                foreach ($model->rules->where('active', true)->whereNull('event_type') as $rule) {
                    if (! $this->conditions->matches($rule->conditions, $lockedLead, $rule->match_type)) {
                        continue;
                    }
                    $points = $this->points($rule);
                    $explicitTotal += $points;
                    $explicit[] = ['rule_id' => (int) $rule->id, 'name' => $rule->name, 'points' => $points];
                }
                $eventTotal = (int) ScoringEvent::query()
                    ->where('scoring_model_id', $model->id)->where('lead_id', $lockedLead->id)->sum('points');
                $raw = $explicitTotal + $eventTotal;
                $score = max($model->minimum_score, min($model->maximum_score, $raw));
                $classification = $score >= $model->hot_threshold ? 'hot' : ($score >= $model->warm_threshold ? 'warm' : 'cold');
                $leadScore = LeadScore::query()->updateOrCreate(
                    ['scoring_model_id' => $model->id, 'lead_id' => $lockedLead->id],
                    [
                        'score' => $score,
                        'classification' => $classification,
                        'breakdown' => ['explicit' => $explicit, 'explicit_total' => $explicitTotal, 'event_total' => $eventTotal, 'raw_score' => $raw],
                        'calculated_at' => now(),
                    ],
                );
                if ((int) $model->id === (int) $primaryId) {
                    $lockedLead->update(['score' => $score, 'score_classification' => $classification, 'scored_at' => now()]);
                }
                $this->audit->record('lead_scored', $leadScore, newValues: [
                    'lead_id' => (int) $lockedLead->id,
                    'scoring_model_id' => (int) $model->id,
                    'score' => $score,
                    'classification' => $classification,
                ]);

                return $leadScore->load('model:id,name,is_default');
            });
        })->values();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return Collection<int, LeadScore>
     */
    public function recordEvent(Lead $lead, string $eventType, string $eventKey, array $metadata = []): Collection
    {
        $this->database->transaction(function () use ($lead, $eventType, $eventKey, $metadata): void {
            $lockedLead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $rules = ScoringRule::query()->with(['conditions', 'model'])
                ->where('active', true)->where('event_type', $eventType)
                ->whereHas('model', fn ($query) => $query->where('active', true))->get();
            foreach ($rules as $rule) {
                if (! $this->conditions->matches($rule->conditions, $lockedLead, $rule->match_type, ['event' => $metadata])) {
                    continue;
                }
                if (! $rule->repeatable && ScoringEvent::query()
                    ->where('scoring_rule_id', $rule->id)->where('lead_id', $lockedLead->id)->exists()) {
                    continue;
                }
                ScoringEvent::query()->firstOrCreate([
                    'scoring_rule_id' => $rule->id,
                    'lead_id' => $lockedLead->id,
                    'event_key' => mb_substr($eventKey, 0, 190),
                ], [
                    'scoring_model_id' => $rule->scoring_model_id,
                    'event_type' => $eventType,
                    'points' => $this->points($rule),
                    'metadata' => $this->safeMetadata($metadata),
                    'occurred_at' => now(),
                ]);
            }
        });

        return $this->recalculate($lead->fresh());
    }

    private function points(ScoringRule $rule): int
    {
        return $rule->type === 'negative' ? -abs($rule->points) : abs($rule->points);
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        return collect($metadata)->reject(fn ($value, $key) => preg_match('/password|token|secret|credential|api[_-]?key/i', (string) $key))
            ->map(fn ($value) => is_string($value) ? mb_substr($value, 0, 500) : $value)->all();
    }
}
