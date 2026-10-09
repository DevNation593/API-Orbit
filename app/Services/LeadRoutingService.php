<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadRoutingAction;
use App\Models\LeadRoutingExecution;
use App\Models\LeadRoutingRule;
use App\Models\TenantUser;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

final class LeadRoutingService
{
    public const STRATEGIES = [
        'round_robin', 'load_balancing', 'territory', 'industry', 'product',
        'location', 'score', 'availability', 'manual', 'custom_rule',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuleConditionEvaluator $conditions,
        private readonly AuditService $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function route(Lead $lead, string $eventId, bool $force = false): LeadRoutingExecution
    {
        $existing = LeadRoutingExecution::query()->where('event_id', $eventId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $execution = $this->database->transaction(function () use ($lead, $eventId, $force): LeadRoutingExecution {
            $lockedLead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $existing = LeadRoutingExecution::query()->where('event_id', $eventId)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($lockedLead->owner_id !== null && ! $force) {
                return $this->execution($lockedLead, $eventId, null, null, 'skipped', 'Lead already has an owner.');
            }

            $rules = LeadRoutingRule::with(['conditions', 'actions'])
                ->where('active', true)->orderBy('priority')->orderBy('id')->get();
            foreach ($rules as $candidateRule) {
                if (! $this->conditions->matches($candidateRule->conditions, $lockedLead, $candidateRule->match_type)) {
                    continue;
                }

                $rule = LeadRoutingRule::query()->whereKey($candidateRule->id)->lockForUpdate()->firstOrFail();
                $rule->load(['conditions', 'actions']);
                $action = $this->selectAction($rule, $lockedLead);
                $userId = $action?->user_id ?? $this->activeUserId($rule->fallback_user_id);
                if ($userId === null) {
                    if ($rule->stop_on_match) {
                        $rule->update(['last_executed_at' => now()]);

                        return $this->execution($lockedLead, $eventId, $rule, null, 'unassigned', 'Matching rule has no available candidate.');
                    }

                    continue;
                }

                $oldOwner = $lockedLead->owner_id;
                $lockedLead->update(['owner_id' => $userId, 'routed_at' => now()]);
                if ($action !== null) {
                    $action->increment('assignments_count');
                    $action->update(['last_assigned_at' => now()]);
                }
                $rule->update(['last_executed_at' => now()]);
                $execution = $this->execution($lockedLead, $eventId, $rule, $userId, 'assigned', 'Lead assigned by routing rule.');
                $this->audit->record('lead_routed', $lockedLead, oldValues: ['owner_id' => $oldOwner], newValues: [
                    'owner_id' => $userId,
                    'routing_rule_id' => (int) $rule->id,
                    'strategy' => $rule->strategy,
                ]);

                return $execution;
            }

            return $this->execution($lockedLead, $eventId, null, null, 'unassigned', 'No active routing rule matched.');
        });

        if ($execution->selectedUser !== null && $execution->status === 'assigned') {
            $this->notifications->send(
                $execution->selectedUser,
                'lead.assigned',
                'Nuevo lead asignado',
                'Se te asignó el lead #'.$execution->lead_id.'.',
                ['lead_id' => (int) $execution->lead_id, 'routing_execution_id' => (int) $execution->id],
                '/leads/'.$execution->lead_id,
            );
        }

        return $execution;
    }

    private function selectAction(LeadRoutingRule $rule, Lead $lead): ?LeadRoutingAction
    {
        $actions = $this->availableActions($rule->actions, $lead);
        if ($actions->isEmpty()) {
            return null;
        }

        $strategy = $rule->strategy;
        if (in_array($strategy, ['territory', 'industry', 'product', 'location', 'score', 'custom_rule'], true)) {
            $strategy = (string) data_get($rule->config, 'candidate_strategy', 'round_robin');
        }

        return match ($strategy) {
            'manual' => $actions->firstWhere('type', 'assign') ?? $actions->first(),
            'load_balancing' => $this->leastLoaded($actions),
            'availability' => $actions
                ->filter(fn (LeadRoutingAction $action) => (bool) data_get($action->config, 'available', true))
                ->sortBy(fn (LeadRoutingAction $action): string => sprintf(
                    '%010d-%010d',
                    $action->last_assigned_at?->getTimestamp() ?? 0,
                    $action->position,
                ))->first(),
            default => $this->roundRobin($rule, $actions),
        };
    }

    /** @param Collection<int, LeadRoutingAction> $actions
     * @return Collection<int, LeadRoutingAction>
     */
    private function availableActions(Collection $actions, Lead $lead): Collection
    {
        $members = TenantUser::query()->where('tenant_id', app(TenantContext::class)->requireId())->where('status', 'active')
            ->whereIn('user_id', $actions->pluck('user_id')->filter()->all())->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return $actions->filter(function (LeadRoutingAction $action) use ($members, $lead): bool {
            if ($action->user_id === null || ! in_array((int) $action->user_id, $members, true)) {
                return false;
            }
            $minimum = data_get($action->config, 'minimum_score');
            $maximum = data_get($action->config, 'maximum_score');
            if ($minimum !== null && (int) ($lead->score ?? 0) < (int) $minimum) {
                return false;
            }
            if ($maximum !== null && (int) ($lead->score ?? 0) > (int) $maximum) {
                return false;
            }
            if ($action->capacity === null) {
                return true;
            }

            return Lead::query()->where('owner_id', $action->user_id)
                ->whereNull('converted_at')->whereNotIn('status', ['converted', 'lost', 'disqualified'])
                ->count() < $action->capacity;
        })->values();
    }

    /** @param Collection<int, LeadRoutingAction> $actions */
    private function roundRobin(LeadRoutingRule $rule, Collection $actions): LeadRoutingAction
    {
        $weighted = collect();
        foreach ($actions as $action) {
            for ($position = 0; $position < max(1, min(100, $action->weight)); $position++) {
                $weighted->push($action);
            }
        }
        $selected = $weighted->get($rule->cursor % $weighted->count());
        $rule->update(['cursor' => ($rule->cursor + 1) % $weighted->count()]);

        return $selected;
    }

    /** @param Collection<int, LeadRoutingAction> $actions */
    private function leastLoaded(Collection $actions): LeadRoutingAction
    {
        $counts = Lead::query()->whereIn('owner_id', $actions->pluck('user_id')->all())
            ->whereNull('converted_at')->whereNotIn('status', ['converted', 'lost', 'disqualified'])
            ->selectRaw('owner_id, COUNT(*) as aggregate')->groupBy('owner_id')->pluck('aggregate', 'owner_id');

        return $actions->sortBy(fn (LeadRoutingAction $action): string => sprintf(
            '%010d-%010d-%010d',
            (int) ($counts[$action->user_id] ?? 0),
            $action->last_assigned_at?->getTimestamp() ?? 0,
            $action->position,
        ))->first();
    }

    private function activeUserId(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        return TenantUser::query()->where('tenant_id', app(TenantContext::class)->requireId())
            ->where('user_id', $userId)->where('status', 'active')->exists() ? $userId : null;
    }

    private function execution(
        Lead $lead,
        string $eventId,
        ?LeadRoutingRule $rule,
        ?int $userId,
        string $status,
        string $reason,
    ): LeadRoutingExecution {
        return LeadRoutingExecution::create([
            'lead_routing_rule_id' => $rule?->id,
            'lead_id' => $lead->id,
            'selected_user_id' => $userId,
            'event_id' => $eventId,
            'strategy' => $rule?->strategy,
            'status' => $status,
            'reason' => $reason,
            'snapshot' => [
                'source' => $lead->source,
                'capture_origin' => $lead->capture_origin,
                'score' => $lead->score,
                'rule_id' => $rule?->id,
            ],
            'executed_at' => now(),
        ])->load(['rule:id,name,strategy', 'selectedUser:id,name,email']);
    }
}
