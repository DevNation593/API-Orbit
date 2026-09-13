# API-7.2 Knowledge Base Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar una Base de Conocimiento multiempresa con edición versionada, publicación controlada y lectura pública segura, sin slugs y con un flujo Postman verificable.

**Architecture:** Módulo Laravel integrado con una superficie interna protegida por Sanctum/tenant y otra pública limitada por UUID. Los artículos conservan una identidad numérica interna y revisiones inmutables; punteros separados para la revisión actual y publicada evitan que un borrador sustituya contenido público antes de republicarlo.

**Tech Stack:** PHP ^8.3, Laravel ^13.17, Sanctum ^4.3, Pest ^4.7, SQLite en memoria para pruebas locales y PostgreSQL para producción; se reutilizan `TenantScoped`, `AuditService`, `HtmlSanitizer` y la infraestructura existente, sin instalar paquetes.

**Spec:** `docs/superpowers/specs/2026-09-12-api-7-2-knowledge-base-design.md`, aprobado por el usuario el 2026-09-12 y confirmado en el commit `82e22fd`.

## Global Constraints

- No usar `slug` ni aceptar un identificador legible equivalente. Los IDs internos son enteros; `public_id` es UUID generado por servidor y no editable.
- PostgreSQL se configura solo con `DATABASE_URL` y Redis solo con `REDIS_URL`. No añadir variables de conexión.
- Todas las tablas nuevas llevan `tenant_id` y timestamps; modelos tenant-bound usan `TenantScoped` y RLS opcional en PostgreSQL.
- La API pública solo entrega la revisión publicada de artículos `PUBLIC` cuando la base está habilitada. `CUSTOMER` queda reservado para API-7.3.
- Las revisiones son inmutables. `PATCH` crea una nueva revisión y requiere `expected_version`.
- No borrar artículos ni revisiones por API. Categorías y etiquetas referenciadas se desactivan; su borrado devuelve 409.
- Sanear `body_html` antes de persistir y no copiarlo a auditoría, logs o respuestas de listado.
- No usar la base real, no ejecutar rollback fuera de SQLite desechable/PostgreSQL efímero y no realizar tráfico externo.
- No hacer `push`, merge, reset, stash, `git add .` ni `git commit -a`. El usuario eligió continuar localmente en `dev`.
- Mantener Portal, Customer Success y encuestas fuera del alcance y sin marcarlos como terminados.

---

## Entorno, seguridad de Git y evidencia

Repositorio real: `D:\Proyectos Personales\Orbit CRM\API Orbit`. Es un checkout normal sobre `dev` con trabajo previo no confirmado que debe conservarse. Al redactar este plan, HEAD es `82e22fd`. La ejecución permanece en este checkout porque el usuario eligió continuar localmente y los módulos anteriores aún dependen de cambios no confirmados que un worktree nuevo no contendría.

Antes de modificar cada archivo compartido, guardar una copia en:

```text
.superpowers/sdd/2026-09-12-api-7-2-knowledge-base/task-N-before/
```

La carpeta `.superpowers/sdd` es evidencia local ignorada por Git. Archivos compartidos ya modificados, como `routes/api.php`, `app/Providers/AppServiceProvider.php`, `app/Support/PermissionCatalog.php`, `README.md`, `docs/09-GAP-ANALYSIS.md`, `docs/openapi.yaml` y la colección Postman, solo pueden prepararse por hunk propio. Si un hunk no puede aislarse respecto al índice sin incorporar trabajo anterior, dejarlo sin stage y documentar el delta contra la copia inicial. Cada commit incluye únicamente archivos nuevos propios y hunks inequívocos de la tarea.

Antes del primer RED:

```powershell
if (Test-Path -LiteralPath 'bootstrap\cache\config.php') {
    throw 'Existe configuración cacheada; revisar antes de probar.'
}
$env:APP_ENV = 'testing'
$env:DATABASE_URL = 'sqlite:///:memory:'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:BROADCAST_CONNECTION = 'null'
$env:SESSION_DRIVER = 'array'
php artisan test --compact
```

La última base conocida es 240 pruebas y 2.698 aserciones. La ejecución debe obtener cero fallos con una corrida nueva; si no, detenerse y usar `systematic-debugging` antes de atribuir el fallo a API-7.2.

Para todo ciclo TDD, leer `C:\Users\Stiwar Saltos\.codex\skills\test-driven-development\writing-good-tests.md` antes de editar tests. Registrar el comando RED y confirmar que falla por la capacidad ausente, no por sintaxis, configuración o acceso a una base real. Después implementar el mínimo GREEN, refactorizar con la prueba verde y ejecutar la regresión relacionada.

## Mapa de archivos y contratos compartidos

- Persistencia: `database/migrations/2026_09_12_000000_create_knowledge_base_foundations.php`.
- Modelos: `KnowledgeBase`, `KnowledgeCategory`, `KnowledgeTag`, `KnowledgeArticle` y `KnowledgeArticleVersion` bajo `app/Models`.
- Dominio: `KnowledgeConfigurationService`, `KnowledgeArticleService`, `PublicKnowledgeService` y `KnowledgeArticleQuery` bajo `app/Services`.
- HTTP interno: requests de configuración/catálogos/artículo/versión, `KnowledgeBaseController`, `KnowledgeCategoryController`, `KnowledgeTagController` y `KnowledgeArticleController`.
- HTTP público: `PublicKnowledgeController` y recursos de respuesta dedicados.
- Autorización: `KnowledgePolicy` para configuración/catálogos y `KnowledgeArticlePolicy` para artículos.
- Rutas: `routes/knowledge.php` requerido dentro de `/api/v1`.
- Pruebas: `tests/Support/KnowledgeTestCase.php` y suites feature separadas por entrega.
- Documentación: `docs/17-API-7-2-KNOWLEDGE-BASE.md`, OpenAPI, Postman, README y GAP Analysis.

Convenciones de nombres y respuestas:

- Estados y visibilidades se almacenan en mayúsculas exactamente como el spec.
- `KnowledgeArticleVersion.version` es entero ascendente por artículo desde 1.
- `current_version_id` y `published_version_id` contienen IDs de fila; la ruta `{version}` usa el número secuencial.
- Listados internos y públicos omiten `body_html`. Detalles y versión explícita pueden incluirlo según autorización.
- Referencia de payload ajena: 422 en el campo concreto. Recurso de ruta ajeno: 404.
- Todas las respuestas usan `ApiResponse` con `{data, meta}`.

### Task 1: Persistencia, configuración y catálogos

**Files:**

- Create: `database/migrations/2026_09_12_000000_create_knowledge_base_foundations.php`.
- Create: `app/Models/KnowledgeBase.php`, `KnowledgeCategory.php`, `KnowledgeTag.php`, `KnowledgeArticle.php`, `KnowledgeArticleVersion.php`.
- Create: `app/Policies/KnowledgePolicy.php`.
- Create: `app/Services/KnowledgeConfigurationService.php`.
- Create: `app/Http/Requests/KnowledgeBaseRequest.php`, `KnowledgeCategoryRequest.php`, `KnowledgeTagRequest.php`.
- Create: `app/Http/Controllers/Api/KnowledgeBaseController.php`, `KnowledgeCategoryController.php`, `KnowledgeTagController.php`.
- Create: `routes/knowledge.php`, `tests/Support/KnowledgeTestCase.php`, `tests/Feature/Api/KnowledgeConfigurationTest.php`.
- Modify: `app/Support/PermissionCatalog.php`, `app/Providers/AppServiceProvider.php`, `routes/api.php`.

**Interfaces:**

- Consumes: `DuplicateNormalizer::text(mixed $value): ?string`, `AuditService::record(...)`, `ApiResponse`, `TenantContext` y `ChecksTenantPermission`.
- Produces: `KnowledgeConfigurationService::saveBase(array $data, int $actorId): array{base: KnowledgeBase, created: bool}`.
- Produces: `KnowledgeConfigurationService::saveCategory(array $data, int $actorId, ?KnowledgeCategory $category = null): KnowledgeCategory`.
- Produces: `KnowledgeConfigurationService::saveTag(array $data, int $actorId, ?KnowledgeTag $tag = null): KnowledgeTag`.
- Produces: `KnowledgeConfigurationService::deleteCatalog(KnowledgeCategory|KnowledgeTag $catalog): void`.
- Produces: `KnowledgeTestCase::knowledgeFixture(array $permissions = PermissionCatalog::ALL, bool $public = true): array` con `user`, `tenant`, `token`, `base`, `category` y `tag`.

- [ ] **Step 1: Escribir el RED de configuración única, catálogos y aislamiento.**

Crear `KnowledgeConfigurationTest` con `RefreshDatabase`. El primer caso llama rutas todavía ausentes y debe recibir 404 en vez de 201:

```php
public function test_it_configures_one_base_and_creates_catalogs_without_slugs(): void
{
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', $client['tenant']->id);

    $base = $api->putJson('/api/v1/knowledge/settings', [
        'title' => 'Centro de ayuda',
        'description' => 'Respuestas verificadas',
        'is_public' => true,
    ])->assertCreated();

    $base->assertJsonPath('data.title', 'Centro de ayuda')
        ->assertJsonPath('data.is_public', true);
    $this->assertTrue(Str::isUuid($base->json('data.public_id')));

    $categoryId = $api->postJson('/api/v1/knowledge/categories', [
        'name' => 'Facturación',
        'position' => 10,
    ])->assertCreated()->json('data.id');

    $tagId = $api->postJson('/api/v1/knowledge/tags', [
        'name' => 'Primeros pasos',
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('knowledge_categories', [
        'id' => $categoryId,
        'tenant_id' => $client['tenant']->id,
    ]);
    $this->assertDatabaseHas('knowledge_tags', [
        'id' => $tagId,
        'tenant_id' => $client['tenant']->id,
    ]);
    $this->assertFalse(Schema::hasColumn('knowledge_articles', 'slug'));
}
```

Añadir antes de implementar: segundo `PUT` devuelve 200 y conserva `public_id`; GET sin `knowledge.view` devuelve 403; escritura sin `knowledge.manage` devuelve 403; nombre equivalente normalizado devuelve 409; IDs/`public_id`/`slug` enviados por cliente devuelven 422; recurso de otro tenant devuelve 404; referencia y rol no se filtran en errores.

- [ ] **Step 2: Ejecutar y comprobar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeConfigurationTest.php
```

Resultado requerido: fallo de contrato por ruta ausente. Corregir cualquier error de fixture hasta obtener ese fallo exacto.

- [ ] **Step 3: Crear la migración reversible y los modelos mínimos.**

La migración crea las tablas en este orden y luego agrega las dos claves circulares:

```php
Schema::create('knowledge_bases', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->uuid('public_id')->unique();
    $table->string('title', 190);
    $table->text('description')->nullable();
    $table->boolean('is_public')->default(false);
    $table->timestamps();
    $table->unique('tenant_id', 'knowledge_bases_tenant_unique');
});

Schema::create('knowledge_categories', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('name', 120);
    $table->string('normalized_name', 120);
    $table->text('description')->nullable();
    $table->unsignedSmallInteger('position')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->unique(['tenant_id', 'normalized_name'], 'knowledge_categories_name_unique');
    $table->index(['tenant_id', 'is_active', 'position'], 'knowledge_categories_list_index');
});

Schema::create('knowledge_tags', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('name', 80);
    $table->string('normalized_name', 80);
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->unique(['tenant_id', 'normalized_name'], 'knowledge_tags_name_unique');
    $table->index(['tenant_id', 'is_active', 'name'], 'knowledge_tags_list_index');
});

Schema::create('knowledge_articles', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->uuid('public_id')->unique();
    $table->string('status', 20)->default('DRAFT');
    $table->unsignedBigInteger('current_version_id')->nullable();
    $table->unsignedBigInteger('published_version_id')->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('published_at')->nullable();
    $table->timestamp('archived_at')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'status', 'updated_at'], 'knowledge_articles_status_index');
    $table->index(['tenant_id', 'published_at'], 'knowledge_articles_published_index');
});

Schema::create('knowledge_article_versions', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('article_id')->constrained('knowledge_articles')->cascadeOnDelete();
    $table->unsignedInteger('version');
    $table->foreignId('category_id')->nullable()->constrained('knowledge_categories')->restrictOnDelete();
    $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('title', 255);
    $table->text('summary')->nullable();
    $table->longText('body_html');
    $table->string('visibility', 20);
    $table->string('seo_title', 70)->nullable();
    $table->string('seo_description', 170)->nullable();
    $table->string('change_summary', 500)->nullable();
    $table->timestamps();
    $table->unique(['article_id', 'version'], 'knowledge_article_version_unique');
    $table->index(['tenant_id', 'category_id', 'visibility'], 'knowledge_versions_catalog_index');
});

Schema::create('knowledge_article_version_tags', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('version_id')->constrained('knowledge_article_versions')->cascadeOnDelete();
    $table->foreignId('tag_id')->constrained('knowledge_tags')->restrictOnDelete();
    $table->timestamps();
    $table->unique(['version_id', 'tag_id'], 'knowledge_version_tag_unique');
    $table->index(['tenant_id', 'tag_id', 'version_id'], 'knowledge_version_tag_lookup_index');
});

Schema::table('knowledge_articles', function (Blueprint $table): void {
    $table->foreign('current_version_id', 'knowledge_articles_current_version_fk')
        ->references('id')->on('knowledge_article_versions')->nullOnDelete();
    $table->foreign('published_version_id', 'knowledge_articles_published_version_fk')
        ->references('id')->on('knowledge_article_versions')->nullOnDelete();
});
```

Instalar `knowledge.view`, `knowledge.manage` y `knowledge.publish` por `upsert`; concederlos únicamente a roles de sistema y roles que ya tengan `settings.manage`. En `down` quitar primero las FKs circulares, después pivot, versiones, artículos, etiquetas, categorías y base; retirar asociaciones de permisos antes de borrar permisos. Aplicar `ENABLE/FORCE ROW LEVEL SECURITY` y policy tenant a las seis tablas solo con driver `pgsql` y `tenancy.rls_enabled=true`.

Los cinco modelos usan `TenantScoped`. Definir casts boolean/datetime/integer, ocultar `normalized_name` y relaciones exactas: base pertenece a tenant; categoría/tag tienen versiones; artículo tiene `currentVersion`, `publishedVersion` y `versions()->orderByDesc('version')`; versión pertenece a artículo/categoría/autor y pertenece a muchas etiquetas mediante el pivot tenant-safe.

- [ ] **Step 4: Implementar permisos, servicios y endpoints de configuración.**

`KnowledgePolicy` debe exponer:

```php
public function viewAny(User $user): bool;
public function view(User $user, Model $model): bool;
public function create(User $user): bool;
public function update(User $user, Model $model): bool;
public function delete(User $user, Model $model): bool;
```

Lectura usa `knowledge.view`; las demás acciones usan `knowledge.manage` y verifican tenant cuando existe modelo.

`KnowledgeConfigurationService::saveBase` usa transacción, `firstOrNew` por tenant, genera `Str::uuid()` solo al crear y devuelve la bandera `created`. `saveCategory` y `saveTag` recortan nombre, llaman `DuplicateNormalizer::text`, comprueban unicidad por tenant, asignan `created_by` solo al alta y auditan sin datos privados. `deleteCatalog` bloquea el registro, devuelve 409 si `versions()->exists()` y audita el borrado si está sin uso.

Requests permitidos:

```php
// KnowledgeBaseRequest
[
    'title' => ['required', 'string', 'min:1', 'max:190'],
    'description' => ['nullable', 'string', 'max:5000'],
    'is_public' => ['sometimes', 'boolean'],
]

// KnowledgeCategoryRequest
[
    'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:120'],
    'description' => ['nullable', 'string', 'max:5000'],
    'position' => ['sometimes', 'integer', 'between:0,65535'],
    'is_active' => ['sometimes', 'boolean'],
]

// KnowledgeTagRequest
[
    'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
    'description' => ['nullable', 'string', 'max:2000'],
    'is_active' => ['sometimes', 'boolean'],
]
```

Cada request añade error de validación si recibe `id`, `tenant_id`, `public_id`, `normalized_name`, `created_by`, timestamps o `slug`. Los controladores validan filtros `q`, `active` y `per_page`, escapan `!`, `%` y `_` para `LIKE`, autorizan antes de mutar y usan `ApiResponse::paginated`.

Crear las rutas internas:

```php
Route::prefix('knowledge')->middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    Route::get('settings', [KnowledgeBaseController::class, 'show']);
    Route::put('settings', [KnowledgeBaseController::class, 'upsert']);
    Route::get('categories', [KnowledgeCategoryController::class, 'index']);
    Route::post('categories', [KnowledgeCategoryController::class, 'store']);
    Route::get('categories/{category}', [KnowledgeCategoryController::class, 'show'])->whereNumber('category');
    Route::patch('categories/{category}', [KnowledgeCategoryController::class, 'update'])->whereNumber('category');
    Route::delete('categories/{category}', [KnowledgeCategoryController::class, 'destroy'])->whereNumber('category');
    Route::get('tags', [KnowledgeTagController::class, 'index']);
    Route::post('tags', [KnowledgeTagController::class, 'store']);
    Route::get('tags/{tag}', [KnowledgeTagController::class, 'show'])->whereNumber('tag');
    Route::patch('tags/{tag}', [KnowledgeTagController::class, 'update'])->whereNumber('tag');
    Route::delete('tags/{tag}', [KnowledgeTagController::class, 'destroy'])->whereNumber('tag');
});
```

Registrar policies y agregar `require __DIR__.'/knowledge.php';` dentro del prefijo `v1`. No mover rutas existentes.

- [ ] **Step 5: Completar fixture, GREEN y reversibilidad.**

`KnowledgeTestCase::knowledgeFixture` crea usuario/tenant, establece `TenantContext`, crea base con UUID, categoría y etiqueta. Agregar pruebas de `down()`/`up()` sobre la migración: después de `down` ninguna tabla knowledge existe; después de `up` las seis existen con `tenant_id`/timestamps y no existe columna `slug`.

Ejecutar:

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeConfigurationTest.php
php artisan test --compact tests/Feature/Api/SupportConfigurationTest.php
```

Resultado: cero fallos. Ejecutar Pint sobre archivos PHP de Task 1, `php -l` sobre cada uno y `git diff --check`.

- [ ] **Step 6: Revisar y confirmar Task 1.**

Comprobar permisos, orden de rollback, FKs y consultas tenant. Preparar solo archivos nuevos y hunks propios. Commit:

```text
feat: add tenant-scoped knowledge configuration
```

### Task 2: Borradores y versiones inmutables

**Files:**

- Create: `app/Policies/KnowledgeArticlePolicy.php`.
- Create: `app/Services/KnowledgeArticleService.php`, `app/Services/KnowledgeArticleQuery.php`.
- Create: `app/Http/Requests/KnowledgeArticleRequest.php`.
- Create: `app/Http/Resources/KnowledgeArticleSummaryResource.php`, `KnowledgeArticleResource.php`, `KnowledgeArticleVersionResource.php`.
- Create: `app/Http/Controllers/Api/KnowledgeArticleController.php`.
- Create: `tests/Feature/Api/KnowledgeArticleVersioningTest.php`.
- Modify: `app/Providers/AppServiceProvider.php`, `routes/knowledge.php`.

**Interfaces:**

- Consumes: modelos y fixture de Task 1, `HtmlSanitizer::sanitize(?string): ?string` y `AuditService`.
- Produces: `KnowledgeArticleService::create(array $data, User $actor): KnowledgeArticle`.
- Produces: `KnowledgeArticleService::revise(KnowledgeArticle $article, array $data, User $actor): KnowledgeArticle`.
- Produces: `KnowledgeArticleService::version(KnowledgeArticle $article, int $number): KnowledgeArticleVersion`.
- Produces: `KnowledgeArticleQuery::internal(array $filters): Builder`.
- Produces: recursos summary/detalle/versión con relaciones precargadas y sin consultas implícitas.

- [ ] **Step 1: Escribir el RED de creación y edición versionada.**

```php
public function test_patch_creates_an_immutable_version_and_rejects_a_stale_editor(): void
{
    $client = $this->knowledgeFixture();
    $this->authenticateKnowledge($client);

    $article = $this->postJson('/api/v1/knowledge/articles', [
        'title' => 'Configurar pagos',
        'summary' => 'Guía inicial',
        'body_html' => '<p>Versión uno</p><script>alert(1)</script>',
        'visibility' => 'PUBLIC',
        'category_id' => $client['category']->id,
        'tag_ids' => [$client['tag']->id],
    ])->assertCreated();

    $id = $article->json('data.id');
    $article->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.current_version.version', 1);
    $this->assertStringNotContainsString(
        '<script',
        (string) $article->json('data.current_version.body_html')
    );

    $this->patchJson('/api/v1/knowledge/articles/'.$id, [
        'expected_version' => 1,
        'body_html' => '<p>Versión dos</p>',
        'change_summary' => 'Aclaración',
    ])->assertOk()->assertJsonPath('data.current_version.version', 2);

    $this->patchJson('/api/v1/knowledge/articles/'.$id, [
        'expected_version' => 1,
        'title' => 'Edición obsoleta',
    ])->assertConflict();

    $this->assertDatabaseHas('knowledge_article_versions', [
        'article_id' => $id,
        'version' => 1,
        'body_html' => '<p>Versión uno</p>',
    ]);
    $this->assertDatabaseHas('knowledge_article_versions', [
        'article_id' => $id,
        'version' => 2,
        'body_html' => '<p>Versión dos</p>',
    ]);
}
```

Agregar REDs para base no configurada 409, categoría/tag inactivo o ajeno 422, más de 20 tags 422, HTML vacío tras saneamiento 422, falta de `knowledge.manage` 403, campos de servidor/`slug` 422 y detalle de otro tenant 404. Verificar que el listado no contiene `body_html`.

- [ ] **Step 2: Ejecutar el RED de rutas ausentes.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeArticleVersioningTest.php
```

Resultado requerido: 404 en `POST /knowledge/articles` o ausencia del servicio, nunca un error de conexión.

- [ ] **Step 3: Implementar request, policy y alta transaccional.**

`KnowledgeArticleRequest` acepta:

```php
[
    'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'],
    'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
    'body_html' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:200000'],
    'visibility' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(['PUBLIC', 'CUSTOMER', 'INTERNAL'])],
    'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
    'tag_ids' => ['sometimes', 'array', 'max:20'],
    'tag_ids.*' => ['integer', 'distinct', 'min:1'],
    'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'],
    'seo_description' => ['sometimes', 'nullable', 'string', 'max:170'],
    'change_summary' => ['sometimes', 'nullable', 'string', 'max:500'],
    'expected_version' => [$this->isMethod('patch') ? 'required' : 'prohibited', 'integer', 'min:1'],
]
```

En `after` exigir al menos un campo editorial además de `expected_version` en PATCH y rechazar campos controlados por servidor. Normalizar visibilidad a mayúsculas, pero no modificar título/cuerpo fuera de trim de extremos.

`KnowledgeArticlePolicy` usa `knowledge.view` para `viewAny/view`, `knowledge.manage` para `create/update` y deja `publish/archive/restore` para Task 3 con `knowledge.publish`.

El alta:

```php
public function create(array $data, User $actor): KnowledgeArticle
{
    return $this->database->transaction(function () use ($data, $actor): KnowledgeArticle {
        if (KnowledgeBase::query()->first() === null) {
            abort(409, 'Configure the knowledge base before creating articles.');
        }
        $prepared = $this->prepareVersionData($data);
        $article = KnowledgeArticle::create([
            'public_id' => (string) Str::uuid(),
            'status' => 'DRAFT',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $version = $this->createVersion($article, 1, $prepared, $actor);
        $article->forceFill(['current_version_id' => $version->id])->save();
        $this->auditArticle('knowledge.article.created', $article, $version);

        return $this->loadArticle($article);
    });
}
```

`prepareVersionData` valida categoría/tag activos dentro del tenant, sanea body y rechaza resultado vacío. `createVersion` crea la fila, asocia tags con `tenant_id` en pivot y no llama `update` sobre versiones.

- [ ] **Step 4: Implementar edición con bloqueo y consulta interna.**

`revise` debe bloquear la identidad y recargar `currentVersion` dentro de la transacción:

```php
$article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($article->id);
$current = $article->currentVersion()->with('tags')->firstOrFail();
if ((int) $data['expected_version'] !== (int) $current->version) {
    abort(409, 'The article has a newer version.');
}
if ($article->status === 'ARCHIVED') {
    abort(409, 'Restore the archived article before editing it.');
}
```

Copiar todos los campos del snapshot actual, reemplazar solo los enviados, resolver tags actuales cuando `tag_ids` no exista, preparar contenido y crear versión `current.version + 1`. Actualizar únicamente `current_version_id`, `updated_by` y `updated_at` en la identidad. Auditar nombres de campos y números de versión, sin `body_html`.

`KnowledgeArticleQuery::internal` carga revisión actual/categoría/tags y revisión publicada, aplica inicialmente `q` escapado sobre título/resumen, `status`, `visibility`, `category_id`, `tag_id` y paginación. Nunca usa un nombre de columna del request.

Registrar rutas:

```php
Route::get('articles', [KnowledgeArticleController::class, 'index']);
Route::post('articles', [KnowledgeArticleController::class, 'store']);
Route::get('articles/{article}', [KnowledgeArticleController::class, 'show'])->whereNumber('article');
Route::patch('articles/{article}', [KnowledgeArticleController::class, 'update'])->whereNumber('article');
```

`KnowledgeArticleSummaryResource` omite cuerpo. `KnowledgeArticleResource` incluye revisión actual, revisión publicada resumida y `has_unpublished_changes` calculado por desigualdad de IDs. `KnowledgeArticleVersionResource` incluye HTML solo en endpoints de detalle autenticados.

- [ ] **Step 5: Verificar GREEN, inmutabilidad y commit.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeArticleVersioningTest.php
php artisan test --compact tests/Feature/Api/KnowledgeConfigurationTest.php
```

Confirmar dos filas de versión, una identidad, un pivot por tag y ningún `script` persistido. Ejecutar Pint, `php -l`, `git diff --check` y revisión del delta. Commit aislado:

```text
feat: add immutable knowledge article versions
```

### Task 3: Publicación, archivado y recuperación

**Files:**

- Create: `app/Http/Requests/KnowledgeExpectedVersionRequest.php`.
- Create: `tests/Feature/Api/KnowledgePublicationTest.php`.
- Modify: `app/Policies/KnowledgeArticlePolicy.php`.
- Modify: `app/Services/KnowledgeArticleService.php`.
- Modify: `app/Http/Controllers/Api/KnowledgeArticleController.php`.
- Modify: `routes/knowledge.php`.

**Interfaces:**

- Consumes: `KnowledgeArticleService` y recursos de Task 2.
- Produces: `publish(KnowledgeArticle $article, int $expectedVersion, User $actor): KnowledgeArticle`.
- Produces: `archive(KnowledgeArticle $article, User $actor): KnowledgeArticle`.
- Produces: `restore(KnowledgeArticle $article, User $actor): KnowledgeArticle`.
- Produces: `restoreVersion(KnowledgeArticle $article, int $number, int $expectedVersion, User $actor): KnowledgeArticle`.
- Produces: `versions(KnowledgeArticle $article): LengthAwarePaginator` y `version(KnowledgeArticle $article, int $number): KnowledgeArticleVersion` desde el controller/query.

- [ ] **Step 1: Escribir RED de snapshot publicado y cambios no publicados.**

```php
public function test_published_version_stays_live_until_a_new_revision_is_published(): void
{
    $client = $this->knowledgeFixture();
    $this->authenticateKnowledge($client);
    $article = $this->createKnowledgeArticle($client, '<p>Publicada uno</p>');
    $id = $article['id'];

    $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
        'expected_version' => 1,
    ])->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED')
        ->assertJsonPath('data.published_version.version', 1);

    $this->patchJson('/api/v1/knowledge/articles/'.$id, [
        'expected_version' => 1,
        'body_html' => '<p>Borrador dos</p>',
    ])->assertOk()
        ->assertJsonPath('data.current_version.version', 2)
        ->assertJsonPath('data.published_version.version', 1)
        ->assertJsonPath('data.has_unpublished_changes', true);

    $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
        'expected_version' => 2,
    ])->assertOk()
        ->assertJsonPath('data.published_version.version', 2)
        ->assertJsonPath('data.has_unpublished_changes', false);
}
```

Agregar REDs: publicar versión obsoleta 409; publicar archivado 409; repetir publicación de la misma revisión no crea auditoría; usuario `knowledge.manage` sin `knowledge.publish` recibe 403; archivo retira publicación; restaurar archivado produce `DRAFT` y puntero publicado null; restaurar cuando no está archivado devuelve 409.

- [ ] **Step 2: Escribir RED de historial y restauración inmutable.**

Crear tres revisiones, consultar lista descendente y `GET /versions/1`. Ejecutar `POST /versions/1/restore` con `expected_version=3` y exigir revisión 4 cuyo contenido coincide con versión 1; filas 1–3 no cambian. Número inexistente y versión perteneciente a otro artículo responden 404. Restauración histórica en `ARCHIVED` devuelve 409.

Ejecutar:

```powershell
php artisan test --compact tests/Feature/Api/KnowledgePublicationTest.php
```

Resultado requerido: rutas/acciones ausentes.

- [ ] **Step 3: Implementar acciones bajo bloqueo.**

`KnowledgeExpectedVersionRequest` acepta solo `expected_version` entero positivo y rechaza campos de servidor. `publish` bloquea artículo, valida estado/revisión, y en una actualización fija:

```php
[
    'status' => 'PUBLISHED',
    'published_version_id' => $current->id,
    'published_at' => $at,
    'archived_at' => null,
    'updated_by' => $actor->id,
]
```

Si ya está publicado exactamente ese ID, devolver el artículo sin tocar timestamps ni auditoría.

`archive` acepta `DRAFT` o `PUBLISHED`; si ya está `ARCHIVED` devuelve 200 sin repetir auditoría. Fija estado/`archived_at` y conserva ambos punteros. `restore` solo acepta `ARCHIVED` y fija `DRAFT`, `published_version_id=null`, `published_at=null` y `archived_at=null`.

`restoreVersion` bloquea, compara `expected_version` con la revisión actual, localiza el número dentro del mismo artículo, copia contenido/categoría/tags a una revisión nueva y actualiza `current_version_id`. No cambia el snapshot publicado ni el estado `PUBLISHED`.

Auditorías: `knowledge.article.published`, `archived`, `restored` y `version_restored` con IDs/números/estado; nunca cuerpo.

- [ ] **Step 4: Exponer endpoints e historial.**

```php
Route::get('articles/{article}/versions', [KnowledgeArticleController::class, 'versions'])->whereNumber('article');
Route::get('articles/{article}/versions/{version}', [KnowledgeArticleController::class, 'version'])
    ->whereNumber('article')->whereNumber('version');
Route::post('articles/{article}/versions/{version}/restore', [KnowledgeArticleController::class, 'restoreVersion'])
    ->whereNumber('article')->whereNumber('version');
Route::post('articles/{article}/publish', [KnowledgeArticleController::class, 'publish'])->whereNumber('article');
Route::post('articles/{article}/archive', [KnowledgeArticleController::class, 'archive'])->whereNumber('article');
Route::post('articles/{article}/restore', [KnowledgeArticleController::class, 'restore'])->whereNumber('article');
```

Autorizar `publish/archive/restore` con `knowledge.publish` y tenant. Historial usa `knowledge.view`. Paginar versiones entre 1 y 100 y ordenarlas por número descendente.

- [ ] **Step 5: GREEN, regresión y commit.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgePublicationTest.php
php artisan test --compact tests/Feature/Api/KnowledgeArticleVersioningTest.php
```

Comprobar timestamps con reloj fijo, número de auditorías y ausencia de cambios en snapshots viejos. Pint/lint/diff check y revisión. Commit:

```text
feat: implement knowledge publication lifecycle
```

### Task 4: API pública de solo lectura

**Files:**

- Create: `app/Services/PublicKnowledgeService.php`.
- Create: `app/Http/Resources/PublicKnowledgeArticleSummaryResource.php`, `PublicKnowledgeArticleResource.php`.
- Create: `app/Http/Controllers/Api/PublicKnowledgeController.php`.
- Create: `tests/Feature/Api/PublicKnowledgeApiTest.php`.
- Modify: `app/Providers/AppServiceProvider.php`.
- Modify: `routes/knowledge.php`.

**Interfaces:**

- Consumes: `KnowledgeBase`, `KnowledgeArticle`, `TenantContext` y revisiones publicadas de Tasks 1–3.
- Produces: `PublicKnowledgeService::base(string $publicId): KnowledgeBase`.
- Produces: `PublicKnowledgeService::home(KnowledgeBase $base): array`.
- Produces: `PublicKnowledgeService::categories(KnowledgeBase $base): Collection`.
- Produces: `PublicKnowledgeService::articles(KnowledgeBase $base, array $filters): LengthAwarePaginator`.
- Produces: `PublicKnowledgeService::article(KnowledgeBase $base, string $articlePublicId): KnowledgeArticle`.
- Produce respuestas públicas sin `tenant_id`, ID interno, autores, revisión actual ni body en listados.

- [ ] **Step 1: Escribir RED de matriz de visibilidad pública.**

```php
public function test_public_api_exposes_only_the_published_public_snapshot(): void
{
    $client = $this->knowledgeFixture(public: true);
    $this->authenticateKnowledge($client);
    $article = $this->createKnowledgeArticle($client, '<p>Versión pública uno</p>', 'PUBLIC');
    $id = $article['id'];
    $publicId = $article['public_id'];
    $baseId = $client['base']->public_id;

    $this->getJson('/api/v1/public/knowledge/'.$baseId.'/articles/'.$publicId)
        ->assertNotFound();

    $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
        'expected_version' => 1,
    ])->assertOk();

    $this->getJson('/api/v1/public/knowledge/'.$baseId.'/articles/'.$publicId)
        ->assertOk()
        ->assertJsonPath('data.body_html', '<p>Versión pública uno</p>')
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.tenant_id');

    $this->patchJson('/api/v1/knowledge/articles/'.$id, [
        'expected_version' => 1,
        'body_html' => '<p>Borrador privado dos</p>',
    ])->assertOk();

    $this->getJson('/api/v1/public/knowledge/'.$baseId.'/articles/'.$publicId)
        ->assertOk()
        ->assertJsonPath('data.body_html', '<p>Versión pública uno</p>');
}
```

Crear artículos publicados `CUSTOMER` e `INTERNAL` y exigir 404. Exigir 404 para base apagada, artículo archivado, UUID desconocido y artículo de otra base. La lista no contiene `body_html` ni contenido de borrador.

- [ ] **Step 2: Ejecutar RED y fijar el contrato de rate limit.**

```powershell
php artisan test --compact tests/Feature/Api/PublicKnowledgeApiTest.php
```

Resultado requerido: 404 porque las rutas públicas no existen.

Registrar `public-knowledge` con dos límites: 60 solicitudes por minuto y 1.000 por día, clave `basePublicId|IP`. La prueba realiza 60 solicitudes válidas con el limiter limpio y exige 429 en la siguiente, sin depender de Redis.

- [ ] **Step 3: Implementar localización y restauración segura del tenant.**

`base` consulta `KnowledgeBase::withoutGlobalScope('tenant')` por `public_id` e `is_public=true`. Cada operación usa:

```php
private function withinTenant(KnowledgeBase $base, Closure $callback): mixed
{
    $previous = $this->context->id();
    try {
        $this->context->set((int) $base->tenant_id);

        return $callback();
    } finally {
        $previous === null
            ? $this->context->clear()
            : $this->context->set($previous);
    }
}
```

`articles` consulta identidades `PUBLISHED` y carga `publishedVersion.category/tags`. Cada filtro opera sobre `publishedVersion`: `q` escapado en título/resumen, categoría, tag y sort permitido `title` o `published_at`. La categoría pública lista únicamente categorías referenciadas por una revisión publicada `PUBLIC`, incluso si fueron desactivadas después de publicar.

`article` restringe simultáneamente `public_id`, tenant de la base, estado `PUBLISHED` y `publishedVersion.visibility=PUBLIC`. Nunca cae a `currentVersion`.

- [ ] **Step 4: Implementar recursos, controller y rutas públicas.**

`PublicKnowledgeArticleSummaryResource` devuelve `public_id`, título, resumen, categoría, tags, SEO, `version` y `published_at`. `PublicKnowledgeArticleResource` agrega `body_html`. Ninguno devuelve ID de artículo, tenant, autor, `current_version` o `has_unpublished_changes`.

Rutas, antes del grupo interno:

```php
Route::prefix('public/knowledge/{basePublicId}')
    ->middleware('throttle:public-knowledge')
    ->whereUuid('basePublicId')
    ->group(function (): void {
        Route::get('', [PublicKnowledgeController::class, 'home']);
        Route::get('categories', [PublicKnowledgeController::class, 'categories']);
        Route::get('articles', [PublicKnowledgeController::class, 'articles']);
        Route::get('articles/{articlePublicId}', [PublicKnowledgeController::class, 'article'])
            ->whereUuid('articlePublicId');
    });
```

Validar `q` máximo 120, IDs positivos, `per_page` 1–100, `sort` en `title,published_at` y `direction` en `asc,desc`.

- [ ] **Step 5: GREEN, seguridad pública y commit.**

```powershell
php artisan test --compact tests/Feature/Api/PublicKnowledgeApiTest.php
php artisan test --compact tests/Feature/Api/KnowledgePublicationTest.php
```

Verificar restauración de `TenantContext` después de éxito y excepción; comprobar que ninguna respuesta contiene contenido `CUSTOMER`/`INTERNAL`. Pint/lint/diff check y revisión. Commit:

```text
feat: expose published public knowledge articles
```

### Task 5: Filtros, autorización y endurecimiento transversal

**Files:**

- Create: `tests/Feature/Api/KnowledgeSecurityTest.php`.
- Modify: `app/Services/KnowledgeArticleQuery.php`.
- Modify: controllers, requests, policies y servicios de Tasks 1–4 solo donde un RED concreto demuestre el defecto.

**Interfaces:**

- Consumes: todos los endpoints y servicios de Tasks 1–4.
- Produces filtros internos `q`, `status`, `visibility`, `category_id`, `tag_id`, `has_unpublished_changes`, fechas, `sort` y `direction`.
- Produce evidencia de aislamiento, permiso, proyección, auditoría y validación de referencias/campos del servidor.

- [ ] **Step 1: Escribir RED de permisos y tenant escape.**

Usar dos tenants con artículos reales. Para cada acción enviar un payload válido y variar solo el permiso o tenant:

```php
public function test_foreign_articles_and_versions_are_hidden_on_every_nested_route(): void
{
    $first = $this->knowledgeFixture();
    $this->authenticateKnowledge($first);
    $article = $this->createKnowledgeArticle($first, '<p>SECRETO_TENANT_A</p>');

    $second = $this->knowledgeFixture();
    $this->authenticateKnowledge($second);
    $root = '/api/v1/knowledge/articles/'.$article['id'];

    $this->getJson($root)->assertNotFound();
    $this->patchJson($root, [
        'expected_version' => 1,
        'title' => 'Ataque',
    ])->assertNotFound();
    $this->getJson($root.'/versions')->assertNotFound();
    $this->getJson($root.'/versions/1')->assertNotFound();
    $this->postJson($root.'/publish', ['expected_version' => 1])->assertNotFound();
    $this->postJson($root.'/versions/1/restore', ['expected_version' => 1])->assertNotFound();
    $this->assertStringNotContainsString('SECRETO_TENANT_A', $this->getJson('/api/v1/knowledge/articles')->getContent());
}
```

Matriz obligatoria: `view` no gestiona/publica; `manage` no publica; `publish` no edita y requiere también `view`; referencias de categoría/tag ajenas devuelven 422 en el campo; rutas no numéricas internas y UUID inválidos públicos devuelven 404.

- [ ] **Step 2: Escribir RED de filtros y control de entrada.**

Crear artículos cuyos títulos contienen literalmente `%`, `_` y una cadena SQL. Buscar esos caracteres debe devolver solo coincidencias literales. Probar filtros de estado/visibilidad/categoría/tag, `has_unpublished_changes=true/false` y rango `created_from/created_to`. `sort=title;drop table`, `per_page=0/101` y fecha inválida devuelven 422.

`KnowledgeArticleQuery::internal` debe usar un mapa constante:

```php
private const SORTS = [
    'created_at' => 'knowledge_articles.created_at',
    'updated_at' => 'knowledge_articles.updated_at',
    'published_at' => 'knowledge_articles.published_at',
    'title' => 'current_version.title',
];
```

Para `has_unpublished_changes=true` exigir `published_version_id IS NULL OR current_version_id <> published_version_id`; para false exigir igualdad no nula. Título se ordena mediante join/alias controlado y siempre desempata por `knowledge_articles.id`.

- [ ] **Step 3: Escribir RED de auditoría, proyección e historial protegido.**

Crear cuerpo `PRIVATE_KNOWLEDGE_BODY`, editar, publicar, archivar/restaurar y comprobar:

```php
$this->assertStringNotContainsString(
    'PRIVATE_KNOWLEDGE_BODY',
    AuditLog::query()->get()->toJson()
);
$this->deleteJson('/api/v1/knowledge/articles/'.$articleId)->assertStatus(405);
$this->deleteJson('/api/v1/knowledge/articles/'.$articleId.'/versions/1')->assertStatus(405);
```

Verificar que auditorías contienen artículo, versión, estado y actor; listados no contienen cuerpo; JSON público no contiene IDs internos/autores. Categoría/tag referenciada devuelve 409 al borrar y sigue disponible en el snapshot publicado aunque esté inactiva.

- [ ] **Step 4: Ejecutar REDs y corregir únicamente defectos demostrados.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeSecurityTest.php
```

Cada fallo real recibe un cambio mínimo en query/request/policy/service y una ejecución GREEN inmediata del método afectado. Si una prueba pasa desde el inicio, conservarla como cobertura y no introducir cambios artificiales.

- [ ] **Step 5: Regresión focalizada y commit.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeConfigurationTest.php
php artisan test --compact tests/Feature/Api/KnowledgeArticleVersioningTest.php
php artisan test --compact tests/Feature/Api/KnowledgePublicationTest.php
php artisan test --compact tests/Feature/Api/PublicKnowledgeApiTest.php
php artisan test --compact tests/Feature/Api/KnowledgeSecurityTest.php
```

Ejecutar Pint, `php -l` y diff check. Revisar consultas N+1 con relaciones precargadas y comprobar que todos los filtros usan columnas de allowlist. Commit:

```text
test: harden knowledge API boundaries
```

### Task 6: OpenAPI, Postman, documentación y cierre

**Files:**

- Create: `tests/Feature/Api/KnowledgePostmanWorkflowTest.php`.
- Create: `tests/Feature/Api/KnowledgeOpenApiContractTest.php`.
- Create: `docs/17-API-7-2-KNOWLEDGE-BASE.md`.
- Create: scripts locales bajo `.superpowers/sdd/2026-09-12-api-7-2-knowledge-base/` para generar/validar únicamente el delta OpenAPI/Postman.
- Modify: `docs/openapi.yaml`.
- Modify: `docs/postman/Vantex CRM API.postman_collection.json`, `docs/postman/README.md`.
- Modify: `README.md`, `docs/09-GAP-ANALYSIS.md`, `docs/16-API-7-1-TICKETS-SLA.md`.
- Reuse: `tests/Support/postman-script-runner.cjs` sin debilitar sus protecciones.

**Interfaces:**

- Consumes: 26 operaciones de Tasks 1–5.
- Produces: 17 paths y 26 operaciones OpenAPI de API-7.2; el total esperado pasa de 482 a 508 operaciones si ninguna ruta previa cambia.
- Produces: carpeta Postman `13 - API-7.2 base de conocimiento` con 18 solicitudes; renombra la carpeta general de seguridad de `13` a `14` sin alterar sus requests.
- Produces: guía de uso interno/público, versionado, seguridad y límites del bloque.

- [ ] **Step 1: Escribir RED del contrato OpenAPI.**

`KnowledgeOpenApiContractTest` lee el YAML como texto, extrae cada bloque de path por indentación exacta y obtiene únicamente claves HTTP de cuatro espacios. Esto evita añadir `symfony/yaml` o depender de PyYAML en CI. Un validador local adicional puede usar PyYAML ya disponible en la estación de trabajo para comprobar estructura completa y referencias. El test PHP exige exactamente estos paths/métodos:

```php
$operations = [
    '/knowledge/settings' => ['get', 'put'],
    '/knowledge/categories' => ['get', 'post'],
    '/knowledge/categories/{category}' => ['get', 'patch', 'delete'],
    '/knowledge/tags' => ['get', 'post'],
    '/knowledge/tags/{tag}' => ['get', 'patch', 'delete'],
    '/knowledge/articles' => ['get', 'post'],
    '/knowledge/articles/{article}' => ['get', 'patch'],
    '/knowledge/articles/{article}/versions' => ['get'],
    '/knowledge/articles/{article}/versions/{version}' => ['get'],
    '/knowledge/articles/{article}/versions/{version}/restore' => ['post'],
    '/knowledge/articles/{article}/publish' => ['post'],
    '/knowledge/articles/{article}/archive' => ['post'],
    '/knowledge/articles/{article}/restore' => ['post'],
    '/public/knowledge/{basePublicId}' => ['get'],
    '/public/knowledge/{basePublicId}/categories' => ['get'],
    '/public/knowledge/{basePublicId}/articles' => ['get'],
    '/public/knowledge/{basePublicId}/articles/{articlePublicId}' => ['get'],
];
```

Comprobar Bearer + `X-Tenant-ID` solo en operaciones internas; las cuatro públicas no deben declarar auth ni header tenant. Requests de escritura no contienen `slug`, `public_id`, estado, punteros o autores. Schemas enumeran estados/visibilidades y separan summary/detail.

Ejecutar y observar RED por paths ausentes:

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeOpenApiContractTest.php
```

- [ ] **Step 2: Escribir RED del flujo Postman ejecutado contra Laravel.**

`KnowledgePostmanWorkflowTest` busca exactamente la carpeta nueva, resuelve variables, ejecuta scripts mediante el runner endurecido y despacha requests reales a Laravel. El RED inicial es carpeta ausente.

Las 18 solicitudes, en orden, son:

1. Upsert de configuración pública; captura `knowledge_base_public_id`.
2. Crear categoría; captura `knowledge_category_id`.
3. Crear etiqueta; captura `knowledge_tag_id`.
4. Crear artículo DRAFT versión 1; captura ID interno, UUID y versión.
5. Consultar versión 1.
6. Editar a versión 2 y actualizar variable versión.
7. Listar historial.
8. Publicar versión 2.
9. Listar públicamente sin Authorization ni `X-Tenant-ID`.
10. Consultar detalle público y validar contenido de versión 2.
11. Editar internamente a versión 3.
12. Consultar detalle público y confirmar que continúa versión 2.
13. Restaurar históricamente versión 1, creando versión 4.
14. Publicar versión 4.
15. Consultar detalle público y confirmar contenido de versión 1 con número 4.
16. Archivar artículo.
17. Confirmar 404 público.
18. Restaurar artículo a DRAFT y confirmar puntero publicado null.

Cada request tiene test de status y contrato. Ejemplo:

```javascript
pm.test('Artículo publicado', function () {
    pm.response.to.have.status(200);
});
const data = pm.response.json().data;
pm.expect(data.status).to.eql('PUBLISHED');
pm.collectionVariables.set('knowledge_version', String(data.current_version.version));
```

La prueba final exige una base/categoría/tag, un artículo `DRAFT`, cuatro versiones, puntero publicado null tras restaurar y cero mensajes/tráfico externo. Debe comprobar el conjunto y orden exactos de requests visitados, no solo el último.

- [ ] **Step 3: Documentar OpenAPI y Postman.**

Añadir schemas para settings, categoría, tag, artículo summary/detail, versión, inputs, filtros y respuestas públicas. Todos los `$ref` deben resolver; no sobrescribir keys YAML existentes. Contrastar `route:list --json` con las 508 operaciones documentadas y conservar las 482 previas.

Insertar la carpeta Postman después de API-7.1 y antes de seguridad. Cambiar únicamente el nombre de seguridad a `14 - Seguridad y validaciones`. Los requests públicos eliminan explícitamente herencia de Bearer/header tenant. Variables se capturan desde respuestas; no exigir credenciales externas ni IDs manuales.

La guía `docs/17-API-7-2-KNOWLEDGE-BASE.md` cubre:

- configuración y permisos;
- categorías/etiquetas activas;
- alta y edición con `expected_version`;
- diferencia entre revisión actual/publicada;
- estados y restauraciones;
- HTML saneado;
- rutas públicas y 404 deliberado;
- Postman y comandos de prueba;
- exclusión de Portal/`CUSTOMER`.

Actualizar GAP a Knowledge Base `DONE` y Portal `MISSING`. Actualizar README y la referencia de numeración Postman en la guía API-7.1. No anunciar todo API-7 como completado.

- [ ] **Step 4: GREEN de contratos y verificadores estructurales.**

```powershell
php artisan test --compact tests/Feature/Api/KnowledgeOpenApiContractTest.php
php artisan test --compact tests/Feature/Api/KnowledgePostmanWorkflowTest.php
node --check tests/Support/postman-script-runner.cjs
```

Validadores locales deben comprobar JSON único, scripts compilables, variables definidas antes de uso, 18 requests, 17 paths/26 operaciones, referencias OpenAPI, schemas de request sin campos del servidor y coincidencia de rutas. Los scripts dentro de `.superpowers/sdd` permanecen ignorados y guardan evidencia, no secretos.

- [ ] **Step 5: Verificación completa con evidencia nueva.**

Ejecutar en el entorno aislado:

```powershell
php artisan test --compact
```

Después ejecutar Pint `--test` y `php -l` sobre la lista exacta de archivos PHP creados/modificados por API-7.2. Ejecutar `git diff --check` y `git diff --cached --check`.

Verificar migración reversible en el mismo proceso con `APP_ENV=testing` y `DATABASE_URL=sqlite:///:memory:`: afirmar driver `sqlite` y database `:memory:`, ejecutar migrate, rollback de la última migración y migrate. No invocar rollback mediante un proceso que pueda recargar `.env`.

Si Docker/PostgreSQL desechable no está disponible, registrar explícitamente que RLS, índices y carreras quedan para CI/deployment; jamás usar la base real como sustituto.

- [ ] **Step 6: Revisión final y commit acotado.**

Usar `requesting-code-review` sobre el delta propio. Todo defecto aceptado recibe test RED antes del arreglo. Volver a ejecutar verificaciones afectadas y la suite completa.

Preparar archivos propios. En OpenAPI/Postman/README/GAP compartidos, preparar solo hunks aislables; si dependen de contenido previo fuera del índice, conservarlos en working tree, comparar contra snapshot y registrarlos en el reporte sin mezclar autoría.

Commit:

```text
test: verify knowledge API and Postman workflow
```

## Cobertura y cierre

| Requisito del spec | Tareas responsables |
| --- | --- |
| Configuración única, UUID público y ausencia de slugs | 1, 4, 5, 6 |
| Categorías, etiquetas y bajas protegidas | 1, 5, 6 |
| Artículos, HTML saneado y revisiones inmutables | 2, 5, 6 |
| Publicación, snapshot, archivado y restauración | 3, 4, 6 |
| API pública solo `PUBLIC`; límites `CUSTOMER`/Portal | 4, 5, 6 |
| Permisos, tenant isolation, auditoría y errores | 1–5 |
| Filtros, paginación, concurrencia y proyecciones | 2, 4, 5 |
| Migración/RLS, OpenAPI, Postman y documentación | 1, 6 |

Antes de declarar API-7.2 terminada, releer el spec contra esta tabla, anotar conteos reales de pruebas/aserciones, revisar el diff propio y enumerar cualquier verificación PostgreSQL no ejecutada. Usar `verification-before-completion`; después `finishing-a-development-branch` para ofrecer integración sin hacer push o merge no autorizado.
