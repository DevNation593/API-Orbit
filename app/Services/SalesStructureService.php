<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchMember;
use App\Models\Deal;
use App\Models\GoalTarget;
use App\Models\SalesTeam;
use App\Models\SalesTeamMember;
use App\Models\TenantUser;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class SalesStructureService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveBranch(Branch $branch, array $data): Branch
    {
        return $this->database->transaction(function () use ($branch, $data): Branch {
            $this->assertValidParent($branch, $data['parent_id'] ?? $branch->parent_id);
            $exists = $branch->exists;
            $old = $exists ? $branch->getAttributes() : null;

            $branch->fill($data)->save();
            $this->audit->record(
                $exists ? 'branch_updated' : 'branch_created',
                $branch,
                oldValues: $old,
                newValues: $branch->getAttributes(),
            );

            return $branch->fresh(['parent:id,name', 'currency:id,code,name', 'manager:id,name,email']);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveTeam(SalesTeam $team, array $data): SalesTeam
    {
        return $this->database->transaction(function () use ($team, $data): SalesTeam {
            $parentId = $data['parent_id'] ?? $team->parent_id;
            $this->assertValidParent($team, $parentId);
            $data = $this->normalizeTeamBranch($team, $data, $parentId);
            $exists = $team->exists;
            $old = $exists ? $team->getAttributes() : null;

            $team->fill($data)->save();
            $this->audit->record(
                $exists ? 'sales_team_updated' : 'sales_team_created',
                $team,
                oldValues: $old,
                newValues: $team->getAttributes(),
            );

            return $team->fresh(['parent:id,name', 'branch:id,code,name', 'manager:id,name,email']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveMember(Branch|SalesTeam $structure, array $data): BranchMember|SalesTeamMember
    {
        return $this->database->transaction(function () use ($structure, $data): BranchMember|SalesTeamMember {
            $this->assertActiveTenantMember((int) $structure->tenant_id, (int) $data['user_id']);
            $isBranch = $structure instanceof Branch;
            $memberClass = $isBranch ? BranchMember::class : SalesTeamMember::class;
            $foreignKey = $isBranch ? 'branch_id' : 'sales_team_id';

            /** @var BranchMember|SalesTeamMember|null $member */
            $member = $memberClass::query()
                ->where($foreignKey, $structure->getKey())
                ->where('user_id', $data['user_id'])
                ->lockForUpdate()
                ->first();
            $exists = $member !== null;
            $member ??= new $memberClass;
            $old = $exists ? $member->getAttributes() : null;
            $member->setAttribute($foreignKey, $structure->getKey());
            $member->fill($data)->save();

            $this->audit->record(
                ($isBranch ? 'branch_member_' : 'sales_team_member_').($exists ? 'updated' : 'created'),
                $member,
                oldValues: $old,
                newValues: $member->getAttributes(),
            );

            return $member->fresh(['user:id,name,email']);
        });
    }

    public function removeMember(Branch|SalesTeam $structure, int $userId): void
    {
        $this->database->transaction(function () use ($structure, $userId): void {
            $isBranch = $structure instanceof Branch;
            $memberClass = $isBranch ? BranchMember::class : SalesTeamMember::class;
            $foreignKey = $isBranch ? 'branch_id' : 'sales_team_id';
            /** @var BranchMember|SalesTeamMember $member */
            $member = $memberClass::query()
                ->where($foreignKey, $structure->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            $old = $member->getAttributes();
            $member->delete();

            $this->audit->record(
                ($isBranch ? 'branch_member_' : 'sales_team_member_').'deleted',
                $member,
                oldValues: $old,
            );
        });
    }

    public function deleteBranch(Branch $branch): void
    {
        $this->database->transaction(function () use ($branch): void {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());
            $references = [
                'children' => $branch->children()->exists(),
                'teams' => $branch->teams()->exists(),
                'territories' => $branch->territories()->exists(),
                'deals' => Deal::query()->where('branch_id', $branch->id)->exists(),
                'goals' => GoalTarget::query()->where('target_type', 'branch')->where('target_id', $branch->id)->exists(),
            ];
            $this->assertNoReferences($references, 'branch');

            $old = $branch->getAttributes();
            $branch->delete();
            $this->audit->record('branch_deleted', $branch, oldValues: $old);
        });
    }

    public function deleteTeam(SalesTeam $team): void
    {
        $this->database->transaction(function () use ($team): void {
            $team = SalesTeam::query()->lockForUpdate()->findOrFail($team->getKey());
            $references = [
                'children' => $team->children()->exists(),
                'deals' => Deal::query()->where('sales_team_id', $team->id)->exists(),
                'goals' => GoalTarget::query()->where('target_type', 'team')->where('target_id', $team->id)->exists(),
            ];
            $this->assertNoReferences($references, 'sales team');

            $old = $team->getAttributes();
            $team->delete();
            $this->audit->record('sales_team_deleted', $team, oldValues: $old);
        });
    }

    /** @param array<string, mixed> $data */
    private function normalizeTeamBranch(SalesTeam $team, array $data, mixed $parentId): array
    {
        if ($parentId === null) {
            return $data;
        }

        $parent = SalesTeam::query()->findOrFail($parentId);
        $branchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : $team->branch_id;
        if (! $team->exists && ! array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $parent->branch_id;
            $branchId = $parent->branch_id;
        }
        if ($parent->branch_id !== null && (int) $branchId !== (int) $parent->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'A child sales team must belong to the same branch as its parent.',
            ]);
        }

        return $data;
    }

    private function assertValidParent(Model $structure, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($structure->exists && (int) $parentId === (int) $structure->getKey()) {
            throw ValidationException::withMessages(['parent_id' => 'A record cannot be its own parent.']);
        }

        $class = $structure::class;
        /** @var Model|null $cursor */
        $cursor = $class::query()->find($parentId);
        $visited = [];
        while ($cursor !== null && ! isset($visited[$cursor->getKey()])) {
            if ($structure->exists && (int) $cursor->getKey() === (int) $structure->getKey()) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent would create a hierarchy cycle.',
                ]);
            }
            $visited[$cursor->getKey()] = true;
            $cursor = $cursor->getAttribute('parent_id') === null
                ? null
                : $class::query()->find($cursor->getAttribute('parent_id'));
        }
    }

    private function assertActiveTenantMember(int $tenantId, int $userId): void
    {
        if (! TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['user_id' => 'The selected user is not an active tenant member.']);
        }
    }

    /** @param array<string, bool> $references */
    private function assertNoReferences(array $references, string $label): void
    {
        $usedBy = array_keys(array_filter($references));
        if ($usedBy !== []) {
            throw ValidationException::withMessages([
                'record' => sprintf('The %s cannot be deleted while referenced by: %s.', $label, implode(', ', $usedBy)),
            ]);
        }
    }
}
