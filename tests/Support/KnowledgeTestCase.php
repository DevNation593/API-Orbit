<?php

namespace Tests\Support;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Support\PermissionCatalog;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class KnowledgeTestCase extends TestCase
{
    protected function knowledgeFixture(array $permissions = PermissionCatalog::ALL, bool $public = true): array
    {
        app(TenantContext::class)->clear();
        $client = $this->createTenantUser($permissions);
        app(TenantContext::class)->set((int) $client['tenant']->id);

        $base = KnowledgeBase::create([
            'public_id' => (string) Str::uuid(),
            'title' => 'Centro de ayuda',
            'description' => 'Respuestas verificadas',
            'is_public' => $public,
        ]);
        $category = KnowledgeCategory::create([
            'name' => 'Facturación',
            'normalized_name' => 'facturacion',
            'position' => 10,
            'created_by' => $client['user']->id,
        ]);
        $tag = KnowledgeTag::create([
            'name' => 'Primeros pasos',
            'normalized_name' => 'primeros pasos',
            'created_by' => $client['user']->id,
        ]);

        return $client + ['base' => $base, 'category' => $category, 'tag' => $tag];
    }
}
