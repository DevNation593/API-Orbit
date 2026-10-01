<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EntityRelation;
use App\Models\Organization;
use App\Models\RecordMerge;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordMergeService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
        private readonly TimelineEventRecorder $timeline,
    ) {}

    /** @param array<string, mixed> $overrides */
    public function mergeContacts(Contact $target, Contact $source, array $overrides = []): Contact
    {
        $relations = ['owner:id,name', 'organizations:id,name'];
        if (auth()->user()?->hasPermission('tags.view')) {
            $relations[] = 'tags:id,name,color';
        }

        /** @var Contact $merged */
        $merged = $this->merge(
            $target,
            $source,
            'contact',
            ['first_name', 'last_name', 'email', 'phone', 'owner_id', 'status'],
            ['companies' => ['contact_id', 'organization_id']],
            ['opportunities' => ['deals', 'contact_id'], 'leads' => ['leads', 'contact_id'], 'quotes' => ['quotes', 'contact_id']],
            $overrides,
            $relations,
        );

        return $merged;
    }

    /** @param array<string, mixed> $overrides */
    public function mergeOrganizations(Organization $target, Organization $source, array $overrides = []): Organization
    {
        $relations = ['owner:id,name', 'contacts:id,first_name,last_name'];
        if (auth()->user()?->hasPermission('tags.view')) {
            $relations[] = 'tags:id,name,color';
        }

        /** @var Organization $merged */
        $merged = $this->merge(
            $target,
            $source,
            'organization',
            ['name', 'legal_name', 'email', 'phone', 'website', 'owner_id'],
            ['contacts' => ['organization_id', 'contact_id']],
            ['opportunities' => ['deals', 'organization_id'], 'leads' => ['leads', 'organization_id'], 'quotes' => ['quotes', 'organization_id']],
            $overrides,
            $relations,
        );

        return $merged;
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<string, array{0: string, 1: string}>  $pivots
     * @param  array<string, array{0: string, 1: string}>  $foreignKeys
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>  $relations
     */
    private function merge(
        Model $target,
        Model $source,
        string $type,
        array $fields,
        array $pivots,
        array $foreignKeys,
        array $overrides,
        array $relations,
    ): Model {
        if ($target->is($source)) {
            throw ValidationException::withMessages(['duplicate_id' => 'A record cannot be merged into itself.']);
        }

        return $this->database->transaction(function () use (
            $target, $source, $type, $fields, $pivots, $foreignKeys, $overrides, $relations,
        ): Model {
            [$target, $source] = $this->lockPair($target::class, (int) $target->getKey(), (int) $source->getKey());
            $tenantId = app(TenantContext::class)->requireId();
            $oldTarget = $target->getAttributes();
            $target->fill($this->mergedAttributes($target, $source, $fields, $overrides));
            $target->setAttribute('custom_fields', array_replace(
                is_array($source->getAttribute('custom_fields')) ? $source->getAttribute('custom_fields') : [],
                is_array($target->getAttribute('custom_fields')) ? $target->getAttribute('custom_fields') : [],
                is_array($overrides['custom_fields'] ?? null) ? $overrides['custom_fields'] : [],
            ));
            $target->save();

            $moved = [];
            foreach ($pivots as $label => [$recordColumn, $relatedColumn]) {
                $moved[$label] = $this->mergePivot(
                    $tenantId,
                    $recordColumn,
                    $relatedColumn,
                    (int) $target->getKey(),
                    (int) $source->getKey(),
                );
            }
            foreach ($foreignKeys as $label => [$table, $column]) {
                $moved[$label] = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where($column, $source->getKey())
                    ->update([$column => $target->getKey(), 'updated_at' => now()]);
            }
            foreach ([
                'activities' => ['activities', 'activityable'],
                'tasks' => ['tasks', 'related'],
                'documents' => ['file_records', 'related'],
            ] as $label => [$table, $prefix]) {
                $moved[$label] = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where($prefix.'_type', $type)
                    ->where($prefix.'_id', $source->getKey())
                    ->update([$prefix.'_id' => $target->getKey(), 'updated_at' => now()]);
            }

            $moved['tags'] = $this->mergeTags($tenantId, $type, (int) $target->getKey(), (int) $source->getKey());
            $moved['relationships'] = $this->mergeEntityRelations($type, (int) $target->getKey(), (int) $source->getKey());
            RecordMerge::query()->where('entity_type', $type)->where('target_id', $source->getKey())
                ->update(['target_id' => $target->getKey(), 'updated_at' => now()]);
            RecordMerge::create([
                'entity_type' => $type,
                'source_id' => (int) $source->getKey(),
                'target_id' => (int) $target->getKey(),
                'merged_by' => auth()->id(),
                'source_snapshot' => [
                    'id' => (int) $source->getKey(),
                    'created_at' => $source->getAttribute('created_at')?->toISOString(),
                    'updated_at' => $source->getAttribute('updated_at')?->toISOString(),
                ],
                'moved_relations' => $moved,
            ]);

            $source->delete();
            $this->audit->record('merge', $target, oldValues: $oldTarget, newValues: [
                'source_id' => (int) $source->getKey(), 'moved_relations' => $moved,
            ]);
            $this->audit->record('merged_into', $source, newValues: ['target_id' => (int) $target->getKey()]);
            $this->timeline->record($target, ($type === 'contact' ? 'contact' : 'company').'.merged', [
                'source_id' => (int) $source->getKey(), 'moved_relations' => $moved,
            ]);

            return $target->fresh($relations);
        }, 3);
    }

    /** @return array{0: Model, 1: Model} */
    private function lockPair(string $model, int $targetId, int $sourceId): array
    {
        $records = $model::query()->whereIn('id', [$targetId, $sourceId])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (! $records->has($targetId) || ! $records->has($sourceId)) {
            abort(404, 'One of the merge records no longer exists.');
        }

        return [$records->get($targetId), $records->get($sourceId)];
    }

    /** @param array<int, string> $fields
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergedAttributes(Model $target, Model $source, array $fields, array $overrides): array
    {
        $merged = [];
        foreach ($fields as $field) {
            $targetValue = $target->getAttribute($field);
            $merged[$field] = $targetValue === null || $targetValue === ''
                ? $source->getAttribute($field)
                : $targetValue;
            if (array_key_exists($field, $overrides)) {
                $merged[$field] = $overrides[$field];
            }
        }

        return $merged;
    }

    private function mergePivot(
        int $tenantId,
        string $recordColumn,
        string $relatedColumn,
        int $targetId,
        int $sourceId,
    ): int {
        $rows = DB::table('contact_organization')->where('tenant_id', $tenantId)
            ->where($recordColumn, $sourceId)->get();
        foreach ($rows as $row) {
            DB::table('contact_organization')->insertOrIgnore([
                $recordColumn => $targetId,
                $relatedColumn => $row->{$relatedColumn},
                'tenant_id' => $tenantId,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('contact_organization')->where('tenant_id', $tenantId)
            ->where($recordColumn, $sourceId)->delete();

        return $rows->count();
    }

    private function mergeTags(int $tenantId, string $type, int $targetId, int $sourceId): int
    {
        $rows = DB::table('tag_assignments')->where('tenant_id', $tenantId)
            ->where('taggable_type', $type)->where('taggable_id', $sourceId)->get();
        foreach ($rows as $row) {
            $duplicate = DB::table('tag_assignments')->where('tenant_id', $tenantId)
                ->where('tag_id', $row->tag_id)->where('taggable_type', $type)
                ->where('taggable_id', $targetId)->exists();
            if ($duplicate) {
                DB::table('tag_assignments')->where('id', $row->id)->delete();
            } else {
                DB::table('tag_assignments')->where('id', $row->id)
                    ->update(['taggable_id' => $targetId, 'updated_at' => now()]);
            }
        }

        return $rows->count();
    }

    private function mergeEntityRelations(string $type, int $targetId, int $sourceId): int
    {
        $relations = EntityRelation::query()->where(function ($query) use ($type, $sourceId): void {
            $query->where(fn ($side) => $side->where('from_type', $type)->where('from_id', (string) $sourceId))
                ->orWhere(fn ($side) => $side->where('to_type', $type)->where('to_id', (string) $sourceId));
        })->lockForUpdate()->get();

        foreach ($relations as $relation) {
            $fromId = $relation->from_type === $type && $relation->from_id === (string) $sourceId
                ? (string) $targetId : $relation->from_id;
            $toId = $relation->to_type === $type && $relation->to_id === (string) $sourceId
                ? (string) $targetId : $relation->to_id;
            if ($relation->from_type === $relation->to_type && $fromId === $toId) {
                $relation->delete();

                continue;
            }

            $duplicate = EntityRelation::query()->whereKeyNot($relation->id)
                ->where('relation_type', $relation->relation_type)
                ->where('from_type', $relation->from_type)->where('from_id', $fromId)
                ->where('to_type', $relation->to_type)->where('to_id', $toId)->first();
            if ($duplicate !== null) {
                $duplicate->update(['metadata' => array_replace($relation->metadata ?? [], $duplicate->metadata ?? [])]);
                $relation->delete();
            } else {
                $relation->update(['from_id' => $fromId, 'to_id' => $toId]);
            }
        }

        return $relations->count();
    }
}
