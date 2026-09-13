<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeConfigurationService
{
    public function __construct(
        private readonly DuplicateNormalizer $normalizer,
        private readonly AuditService $audit,
    ) {}

    /** @return array{base: KnowledgeBase, created: bool} */
    public function saveBase(array $data, int $actorId): array
    {
        return DB::transaction(function () use ($data, $actorId): array {
            $tenantId = app(TenantContext::class)->requireId();
            $base = KnowledgeBase::forTenant($tenantId)->lockForUpdate()
                ->firstOrNew(['tenant_id' => $tenantId]);
            $created = ! $base->exists;
            $old = $created ? null : $this->auditValues($base);
            if ($created) {
                $base->public_id = (string) Str::uuid();
            }
            $base->fill(Arr::only($data, ['title', 'description', 'is_public']))->save();
            $base->refresh();
            $this->audit->record(
                $created ? 'create' : 'update',
                $base,
                oldValues: $old,
                newValues: $this->auditValues($base) + ['actor_id' => $actorId],
            );

            return ['base' => $base, 'created' => $created];
        });
    }

    public function saveCategory(
        array $data,
        int $actorId,
        ?KnowledgeCategory $category = null,
    ): KnowledgeCategory {
        return DB::transaction(function () use ($data, $actorId, $category): KnowledgeCategory {
            $tenantId = app(TenantContext::class)->requireId();
            $category = $category === null
                ? new KnowledgeCategory
                : KnowledgeCategory::forTenant($tenantId)->lockForUpdate()->findOrFail($category->id);
            $old = $category->exists ? $this->auditValues($category) : null;
            $this->prepareName($data, $category, $tenantId, 'category');
            if (! $category->exists) {
                $data['created_by'] = $actorId;
            }
            $category->fill(Arr::only($data, [
                'name', 'normalized_name', 'description', 'position', 'is_active', 'created_by',
            ]))->save();
            $category->refresh();
            $this->audit->record(
                $old === null ? 'create' : 'update',
                $category,
                oldValues: $old,
                newValues: $this->auditValues($category),
            );

            return $category;
        });
    }

    public function saveTag(array $data, int $actorId, ?KnowledgeTag $tag = null): KnowledgeTag
    {
        return DB::transaction(function () use ($data, $actorId, $tag): KnowledgeTag {
            $tenantId = app(TenantContext::class)->requireId();
            $tag = $tag === null
                ? new KnowledgeTag
                : KnowledgeTag::forTenant($tenantId)->lockForUpdate()->findOrFail($tag->id);
            $old = $tag->exists ? $this->auditValues($tag) : null;
            $this->prepareName($data, $tag, $tenantId, 'tag');
            if (! $tag->exists) {
                $data['created_by'] = $actorId;
            }
            $tag->fill(Arr::only($data, [
                'name', 'normalized_name', 'description', 'is_active', 'created_by',
            ]))->save();
            $tag->refresh();
            $this->audit->record(
                $old === null ? 'create' : 'update',
                $tag,
                oldValues: $old,
                newValues: $this->auditValues($tag),
            );

            return $tag;
        });
    }

    public function deleteCatalog(KnowledgeCategory|KnowledgeTag $catalog): void
    {
        DB::transaction(function () use ($catalog): void {
            $tenantId = app(TenantContext::class)->requireId();
            $catalog = $catalog::forTenant($tenantId)->lockForUpdate()->findOrFail($catalog->id);
            abort_if(
                $catalog->versions()->exists(),
                409,
                'This knowledge catalog is referenced and cannot be deleted.',
            );
            $old = $this->auditValues($catalog);
            $catalog->delete();
            $this->audit->record('delete', $catalog, oldValues: $old);
        });
    }

    private function prepareName(
        array &$data,
        KnowledgeCategory|KnowledgeTag $catalog,
        int $tenantId,
        string $label,
    ): void {
        if (! array_key_exists('name', $data)) {
            return;
        }

        $data['name'] = trim((string) $data['name']);
        $normalized = $this->normalizer->text($data['name']);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'name' => 'The name must contain letters or numbers.',
            ]);
        }

        $query = $catalog::forTenant($tenantId)->where('normalized_name', $normalized);
        if ($catalog->exists) {
            $query->whereKeyNot($catalog->getKey());
        }
        abort_if($query->exists(), 409, "A knowledge {$label} with this name already exists.");
        $data['normalized_name'] = $normalized;
    }

    private function auditValues(Model $model): array
    {
        return Arr::only($model->attributesToArray(), [
            'id', 'tenant_id', 'public_id', 'title', 'name', 'position', 'is_active',
            'is_public', 'created_by',
        ]);
    }
}
