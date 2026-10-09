<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\GoalTarget;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\TenantUser;
use App\Models\Territory;
use App\Models\TerritoryAssignment;
use App\Models\TerritoryMember;
use App\Models\TerritoryRule;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class TerritoryService
{
    /** @var array<string, class-string<Model>> */
    private const ENTITY_MODELS = [
        'contact' => Contact::class,
        'organization' => Organization::class,
        'lead' => Lead::class,
        'deal' => Deal::class,
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuleConditionEvaluator $conditions,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveTerritory(Territory $territory, array $data): Territory
    {
        return $this->database->transaction(function () use ($territory, $data): Territory {
            $parentId = $data['parent_id'] ?? $territory->parent_id;
            $this->assertValidParent($territory, $parentId);
            $data = $this->normalizeBranch($territory, $data, $parentId);
            $exists = $territory->exists;
            $old = $exists ? $territory->getAttributes() : null;

            $territory->fill($data)->save();
            $this->audit->record(
                $exists ? 'territory_updated' : 'territory_created',
                $territory,
                oldValues: $old,
                newValues: $territory->getAttributes(),
            );

            return $territory->fresh(['parent:id,code,name', 'branch:id,code,name', 'manager:id,name,email']);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveRule(TerritoryRule $rule, array $data): TerritoryRule
    {
        return $this->database->transaction(function () use ($rule, $data): TerritoryRule {
            $exists = $rule->exists;
            $old = $exists ? $rule->getAttributes() : null;
            $rule->fill($data)->save();
            $this->audit->record(
                $exists ? 'territory_rule_updated' : 'territory_rule_created',
                $rule,
                oldValues: $old,
                newValues: $rule->getAttributes(),
            );

            return $rule->fresh(['territory:id,code,name']);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveMember(Territory $territory, array $data): TerritoryMember
    {
        return $this->database->transaction(function () use ($territory, $data): TerritoryMember {
            $this->assertActiveTenantMember((int) $territory->tenant_id, (int) $data['user_id']);
            $member = TerritoryMember::query()
                ->where('territory_id', $territory->id)
                ->where('user_id', $data['user_id'])
                ->lockForUpdate()
                ->first();
            $exists = $member !== null;
            $member ??= new TerritoryMember;
            $old = $exists ? $member->getAttributes() : null;
            $member->territory_id = $territory->id;
            $member->fill($data)->save();
            $this->audit->record(
                'territory_member_'.($exists ? 'updated' : 'created'),
                $member,
                oldValues: $old,
                newValues: $member->getAttributes(),
            );

            return $member->fresh(['user:id,name,email']);
        });
    }

    public function removeMember(Territory $territory, int $userId): void
    {
        $this->database->transaction(function () use ($territory, $userId): void {
            $member = TerritoryMember::query()
                ->where('territory_id', $territory->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            $old = $member->getAttributes();
            $member->delete();
            $this->audit->record('territory_member_deleted', $member, oldValues: $old);
        });
    }

    public function deleteRule(TerritoryRule $rule): void
    {
        $this->database->transaction(function () use ($rule): void {
            $rule = TerritoryRule::query()->lockForUpdate()->findOrFail($rule->getKey());
            $old = $rule->getAttributes();
            $rule->delete();
            $this->audit->record('territory_rule_deleted', $rule, oldValues: $old);
        });
    }

    /**
     * @return array{entity: Model, assignment: TerritoryAssignment|null, matched_rule: TerritoryRule|null}
     */
    public function assign(
        string $entityType,
        int $entityId,
        ?int $territoryId,
        bool $evaluateRules,
        ?int $userId,
    ): array {
        return $this->database->transaction(function () use ($entityType, $entityId, $territoryId, $evaluateRules, $userId): array {
            $entity = $this->findEntity($entityType, $entityId, true);
            $matchedRule = null;

            if ($evaluateRules) {
                $matchedRule = $this->matchingRule($entityType, $entity);
                $territoryId = $matchedRule?->territory_id;
            }

            if ($territoryId === null) {
                $this->removeAssignment($entity, $userId);

                return ['entity' => $entity->fresh(), 'assignment' => null, 'matched_rule' => null];
            }

            $territory = Territory::query()->where('active', true)->findOrFail($territoryId);
            $oldTerritoryId = $entity->getAttribute('territory_id');
            $entity->setAttribute('territory_id', $territory->id);
            $entity->save();

            $assignment = TerritoryAssignment::query()->updateOrCreate(
                [
                    'assignable_type' => $entity->getMorphClass(),
                    'assignable_id' => $entity->getKey(),
                ],
                [
                    'territory_id' => $territory->id,
                    'territory_rule_id' => $matchedRule?->id,
                    'source' => $matchedRule === null ? 'manual' : 'rule',
                    'assigned_by' => $userId,
                    'assigned_at' => now(),
                    'metadata' => $matchedRule === null ? null : ['rule_name' => $matchedRule->name],
                ],
            );
            $this->audit->record(
                'territory_assigned',
                $entity,
                oldValues: ['territory_id' => $oldTerritoryId],
                newValues: [
                    'territory_id' => $territory->id,
                    'source' => $assignment->source,
                    'territory_rule_id' => $assignment->territory_rule_id,
                ],
            );

            return [
                'entity' => $entity->fresh(['territory:id,code,name']),
                'assignment' => $assignment->fresh(['territory:id,code,name', 'rule:id,name']),
                'matched_rule' => $matchedRule,
            ];
        });
    }

    public function deleteTerritory(Territory $territory): void
    {
        $this->database->transaction(function () use ($territory): void {
            $territory = Territory::query()->lockForUpdate()->findOrFail($territory->getKey());
            $references = [
                'children' => $territory->children()->exists(),
                'assignments' => $territory->assignments()->exists(),
                'deals' => Deal::query()->where('territory_id', $territory->id)->exists(),
                'goals' => GoalTarget::query()->where('target_type', 'territory')->where('target_id', $territory->id)->exists(),
            ];
            $usedBy = array_keys(array_filter($references));
            if ($usedBy !== []) {
                throw ValidationException::withMessages([
                    'territory' => 'The territory cannot be deleted while referenced by: '.implode(', ', $usedBy).'.',
                ]);
            }

            $old = $territory->getAttributes();
            $territory->delete();
            $this->audit->record('territory_deleted', $territory, oldValues: $old);
        });
    }

    private function findEntity(string $entityType, int $entityId, bool $lock = false): Model
    {
        $class = self::ENTITY_MODELS[$entityType] ?? null;
        if ($class === null) {
            throw ValidationException::withMessages(['entity_type' => 'Unsupported territory entity type.']);
        }

        $query = $class::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($entityId);
    }

    private function matchingRule(string $entityType, Model $entity): ?TerritoryRule
    {
        $candidate = null;
        $rules = TerritoryRule::query()
            ->where('entity_type', $entityType)
            ->where('active', true)
            ->whereHas('territory', fn ($query) => $query->where('active', true))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->conditions->matches($rule->conditions ?? [], $entity, $rule->match_type)) {
                continue;
            }
            $candidate = $rule;
            if ($rule->stop_processing) {
                break;
            }
        }

        return $candidate;
    }

    private function removeAssignment(Model $entity, ?int $userId): void
    {
        $assignment = TerritoryAssignment::query()
            ->where('assignable_type', $entity->getMorphClass())
            ->where('assignable_id', $entity->getKey())
            ->lockForUpdate()
            ->first();
        $oldTerritoryId = $entity->getAttribute('territory_id');
        $entity->setAttribute('territory_id', null);
        $entity->save();
        $assignment?->delete();

        if ($oldTerritoryId !== null || $assignment !== null) {
            $this->audit->record(
                'territory_unassigned',
                $entity,
                oldValues: ['territory_id' => $oldTerritoryId],
                newValues: ['territory_id' => null, 'unassigned_by' => $userId],
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function normalizeBranch(Territory $territory, array $data, mixed $parentId): array
    {
        if ($parentId === null) {
            return $data;
        }

        $parent = Territory::query()->findOrFail($parentId);
        $branchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : $territory->branch_id;
        if (! $territory->exists && ! array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $parent->branch_id;
            $branchId = $parent->branch_id;
        }
        if ($parent->branch_id !== null && (int) $branchId !== (int) $parent->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'A child territory must belong to the same branch as its parent.',
            ]);
        }

        return $data;
    }

    private function assertValidParent(Territory $territory, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($territory->exists && (int) $parentId === (int) $territory->id) {
            throw ValidationException::withMessages(['parent_id' => 'A territory cannot be its own parent.']);
        }

        $cursor = Territory::query()->find($parentId);
        $visited = [];
        while ($cursor !== null && ! isset($visited[$cursor->id])) {
            if ($territory->exists && (int) $cursor->id === (int) $territory->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent would create a territory cycle.',
                ]);
            }
            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id === null ? null : Territory::query()->find($cursor->parent_id);
        }
    }

    private function assertActiveTenantMember(int $tenantId, int $userId): void
    {
        if (! TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['user_id' => 'The selected user is not an active tenant member.']);
        }
    }
}
