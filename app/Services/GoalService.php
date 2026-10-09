<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Goal;
use App\Models\GoalTarget;
use App\Models\Product;
use App\Models\SalesTeam;
use App\Models\TenantUser;
use App\Models\Territory;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final class GoalService
{
    private const STRUCTURAL_FIELDS = ['metric', 'currency_id', 'starts_at', 'ends_at', 'period_type'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveGoal(Goal $goal, array $data, int $userId): Goal
    {
        return $this->database->transaction(function () use ($goal, $data, $userId): Goal {
            $targetsProvided = array_key_exists('targets', $data);
            $targets = is_array($data['targets'] ?? null) ? $data['targets'] : [];
            unset($data['targets']);

            $exists = $goal->exists;
            $old = $exists ? $goal->getAttributes() : null;
            if (! $exists) {
                $data['created_by'] = $userId;
            }
            $goal->fill($data);
            $this->assertGoalDefinition($goal);
            if ($exists && $goal->isDirty(self::STRUCTURAL_FIELDS) && $this->hasProgress($goal)) {
                throw ValidationException::withMessages([
                    'goal' => 'A goal with calculated progress cannot change its metric, currency, period, or dates.',
                ]);
            }
            $goal->save();

            if ($targetsProvided) {
                if ($exists && $this->hasProgress($goal)) {
                    throw ValidationException::withMessages([
                        'targets' => 'Targets with calculated progress must be managed individually.',
                    ]);
                }
                $this->replaceTargets($goal, $targets);
            }

            $this->audit->record(
                $exists ? 'goal_updated' : 'goal_created',
                $goal,
                oldValues: $old,
                newValues: $goal->getAttributes(),
            );

            return $goal->fresh($this->relations());
        });
    }

    /** @param array<string, mixed> $data */
    public function saveTarget(Goal $goal, GoalTarget $target, array $data): GoalTarget
    {
        return $this->database->transaction(function () use ($goal, $target, $data): GoalTarget {
            if ($target->exists && (int) $target->goal_id !== (int) $goal->id) {
                throw ValidationException::withMessages(['target' => 'The target does not belong to this goal.']);
            }

            $exists = $target->exists;
            $old = $exists ? $target->getAttributes() : null;
            $target->goal_id = $goal->id;
            $target->fill($data);
            $this->normalizeAndValidateTarget($goal, $target);

            if ($exists && $target->isDirty(['target_type', 'target_id', 'target_key']) && $target->progress()->exists()) {
                throw ValidationException::withMessages([
                    'target' => 'A target with calculated progress cannot change its assignment scope.',
                ]);
            }

            $this->assertUniqueTarget($target);
            $target->save();
            $this->audit->record(
                $exists ? 'goal_target_updated' : 'goal_target_created',
                $target,
                oldValues: $old,
                newValues: $target->getAttributes(),
            );

            return $target->fresh(['goal:id,name,metric,currency_id,starts_at,ends_at,status']);
        });
    }

    public function deleteTarget(Goal $goal, GoalTarget $target): void
    {
        $this->database->transaction(function () use ($goal, $target): void {
            if ((int) $target->goal_id !== (int) $goal->id) {
                throw ValidationException::withMessages(['target' => 'The target does not belong to this goal.']);
            }
            $target = GoalTarget::query()->lockForUpdate()->findOrFail($target->id);
            if ($target->progress()->exists()) {
                throw ValidationException::withMessages([
                    'target' => 'A target with calculated progress cannot be deleted.',
                ]);
            }
            $old = $target->getAttributes();
            $target->delete();
            $this->audit->record('goal_target_deleted', $target, oldValues: $old);
        });
    }

    public function deleteGoal(Goal $goal): void
    {
        $this->database->transaction(function () use ($goal): void {
            $goal = Goal::query()->lockForUpdate()->findOrFail($goal->id);
            if (! in_array($goal->status, ['draft', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'goal' => 'Only draft or cancelled goals can be deleted.',
                ]);
            }
            if ($this->hasProgress($goal)) {
                throw ValidationException::withMessages([
                    'goal' => 'A goal with calculated progress cannot be deleted.',
                ]);
            }
            $old = $goal->getAttributes();
            $goal->delete();
            $this->audit->record('goal_deleted', $goal, oldValues: $old);
        });
    }

    /** @param array<int, array<string, mixed>> $targets */
    private function replaceTargets(Goal $goal, array $targets): void
    {
        $goal->targets()->delete();
        foreach ($targets as $data) {
            $target = new GoalTarget;
            $target->goal_id = $goal->id;
            $target->fill($data);
            $this->normalizeAndValidateTarget($goal, $target);
            $this->assertUniqueTarget($target);
            $target->save();
        }
    }

    private function assertGoalDefinition(Goal $goal): void
    {
        if ($goal->metric === 'revenue' && $goal->currency_id === null) {
            throw ValidationException::withMessages(['currency_id' => 'Revenue goals require a currency.']);
        }
        if ($goal->starts_at === null || $goal->ends_at === null || $goal->ends_at->lt($goal->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => 'The goal end must not be before its start.']);
        }
    }

    private function normalizeAndValidateTarget(Goal $goal, GoalTarget $target): void
    {
        $type = (string) $target->target_type;
        if ($type === 'tenant') {
            $target->target_id = null;
            $target->target_key = null;

            return;
        }
        if ($type === 'industry') {
            $target->target_id = null;
            $target->target_key = trim((string) $target->target_key);
            if ($target->target_key === '') {
                throw ValidationException::withMessages(['target_key' => 'Industry targets require target_key.']);
            }

            return;
        }

        $target->target_key = null;
        if ($target->target_id === null) {
            throw ValidationException::withMessages(['target_id' => 'This target type requires target_id.']);
        }

        $exists = match ($type) {
            'user' => TenantUser::query()
                ->where('tenant_id', $goal->tenant_id)
                ->where('user_id', $target->target_id)
                ->where('status', 'active')
                ->exists(),
            'team' => SalesTeam::query()->whereKey($target->target_id)->exists(),
            'branch' => Branch::query()->whereKey($target->target_id)->exists(),
            'territory' => Territory::query()->whereKey($target->target_id)->exists(),
            'product' => Product::query()->whereKey($target->target_id)->exists(),
            default => false,
        };
        if (! $exists) {
            throw ValidationException::withMessages(['target_id' => 'The selected goal target is not available in this tenant.']);
        }
    }

    private function assertUniqueTarget(GoalTarget $target): void
    {
        $query = GoalTarget::query()->where('goal_id', $target->goal_id)->where('target_type', $target->target_type);
        if ($target->target_id === null) {
            $query->whereNull('target_id');
        } else {
            $query->where('target_id', $target->target_id);
        }
        if ($target->target_key === null) {
            $query->whereNull('target_key');
        } else {
            $query->whereRaw('LOWER(target_key) = ?', [mb_strtolower($target->target_key)]);
        }
        if ($target->exists) {
            $query->whereKeyNot($target->id);
        }
        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['target' => 'This goal already has the same target scope.']);
        }
    }

    private function hasProgress(Goal $goal): bool
    {
        return GoalTarget::query()
            ->where('goal_id', $goal->id)
            ->whereHas('progress')
            ->exists();
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'currency:id,code,name,symbol,decimal_places',
            'creator:id,name,email',
            'targets' => fn ($query) => $query->orderBy('target_type')->orderBy('target_id')->orderBy('target_key'),
            'targets.progress' => fn ($query) => $query->latest('snapshot_date')->limit(1),
        ];
    }
}
