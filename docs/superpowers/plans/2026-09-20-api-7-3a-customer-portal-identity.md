# API-7.3A Customer Portal Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar la identidad y acceso del Portal de Clientes por invitación, con sesiones externas aisladas, perfil acotado y lectura autenticada de conocimiento `CUSTOMER`.

**Architecture:** El módulo añade un `CustomerPortal` por tenant y un autenticable `PortalUser` ligado a `Contact`, sin reutilizar usuarios o roles internos. Dos localizadores mínimos sin RLS permiten resolver el tenant por UUID/hash antes de consultar entidades protegidas; las rutas externas usan Sanctum con habilidad `portal` y un pipeline distinto de la API interna. Los casos de uso se dividen en servicios transaccionales y la consulta de conocimiento publicado se comparte mediante visibilidades explícitas.

**Tech Stack:** PHP ^8.3, Laravel ^13.17, Sanctum ^4.3, Pest ^4.7, SQLite en memoria para desarrollo/pruebas y PostgreSQL 16 en CI; notificaciones Laravel y componentes existentes del repositorio, sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-09-20-api-7-3a-customer-portal-identity-design.md`, aprobado por el usuario y confirmado en el commit `01bc550`.

## Global Constraints

- No crear ni aceptar `slug`. `public_id` es UUID generado por servidor; nunca se expone un ID numérico de tenant, portal, cuenta, contacto o artículo en rutas externas.
- Las categorías y etiquetas conservan únicamente el contrato de vocabulario tenant-local de API-7.2.
- PostgreSQL se configura solo con `DATABASE_URL` y Redis solo con `REDIS_URL`; no añadir variables de conexión.
- `PortalUser` y `User` son principales distintos. Un token o ID coincidente nunca autoriza la superficie del otro.
- Las rutas del portal no dependen de `X-Tenant-ID`; cualquier header recibido se ignora para resolver tenant.
- Los tokens Sanctum externos tienen únicamente la habilidad `portal`, vencen a los 30 días y se revocan al suspender, desactivar portal o cambiar contraseña.
- Invitaciones vencen en siete días; resets en 60 minutos. Ambos usan tokens CSPRNG de 64 caracteres, SHA-256 persistido y consumo único.
- Solo `local`/`testing` puede devolver URLs o tokens planos en `meta`; producción nunca los registra ni responde.
- Las tablas de negocio usan `TenantScoped` y RLS opcional. Solo `customer_portal_locators` y `portal_invitation_locators` quedan sin RLS.
- No enviar correo real, hacer tráfico externo ni usar una base persistente para pruebas destructivas.
- No implementar API-7.3B/7.3C, frontend, OAuth, 2FA, cambio de correo, Customer Success o encuestas.
- No cambiar Vercel. No hacer push, merge, reset, stash, `git add .` ni `git commit -a`.

---

## Entorno y evidencia inicial

Ejecutar en el worktree aislado:

```text
D:\Proyectos Personales\CRM\.worktrees\api-orbit-api-7-3a-customer-portal
```

Rama: `feature/api-7-3a-customer-portal`. Al redactar este plan, HEAD es `01bc5508cd6860f63c3c99d32887750384dc65e6` y el worktree está limpio. La base comprobada es:

```text
npm run build
APP_KEY=0123456789abcdef0123456789abcdef php artisan test
243 tests, 2904 assertions, 0 failures
```

Antes del primer RED:

```powershell
if (Test-Path -LiteralPath 'bootstrap\cache\config.php') {
    throw 'Existe configuración cacheada; elimínala solo después de revisar que sea un artefacto generado.'
}
$env:APP_ENV = 'testing'
$env:APP_KEY = '0123456789abcdef0123456789abcdef'
$env:DATABASE_URL = 'sqlite:///:memory:'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:BROADCAST_CONNECTION = 'null'
$env:SESSION_DRIVER = 'array'
php artisan test --compact
```

Resultado requerido: la base vuelve a quedar verde. Si falla, detener la tarea y usar `systematic-debugging`; no modificar código de portal para ocultar una regresión previa.

Cada tarea sigue RED–GREEN–REFACTOR, termina con tests focalizados, Pint, `php -l`, `git diff --check` y un commit limitado. Antes de editar tests durante la ejecución, invocar `test-driven-development` y leer su referencia de buenas pruebas.

## Mapa de archivos e interfaces

Persistencia:

- `database/migrations/2026_09_20_000000_create_customer_portal_foundations.php`
- `app/Models/CustomerPortal.php`
- `app/Models/PortalUser.php`
- `app/Models/PortalInvitation.php`
- `app/Models/PortalPasswordResetToken.php`

Límites y soporte:

- `app/Support/PortalEmail.php`: normaliza/enmascara correos.
- `app/Support/PortalToken.php`: emite 64 caracteres y calcula SHA-256.
- `app/Http/Middleware/ResolvePortalContext.php`: UUID → locator → `TenantContext` → portal.
- `app/Http/Middleware/EnsurePortalPrincipal.php`: clase, habilidad, tenant, estado y contacto.
- `app/Policies/CustomerPortalPolicy.php`: `portal.manage` para la superficie interna.

Dominio:

- `CustomerPortalService`: configuración y estado de cuentas.
- `PortalInvitationService`: emisión, inspección, revocación y aceptación.
- `PortalAuthService`: login/logout.
- `PortalPasswordResetService`: reset no enumerable y consumo transaccional.
- `PortalProfileService`: proyección y cambios permitidos del contacto.
- `PublishedKnowledgeQuery`: snapshots publicados con visibilidades obligatorias.
- `PortalKnowledgeService`: adaptación `PUBLIC` + `CUSTOMER`.

HTTP:

- Administración: `CustomerPortalSettingsController`, `CustomerPortalUserController`, `CustomerPortalInvitationController`.
- Portal: `PortalInvitationController`, `PortalAuthController`, `PortalProfileController`, `PortalKnowledgeController`.
- Recursos: `CustomerPortalResource`, `PortalUserAdminResource`, `PortalInvitationAdminResource`, `PortalProfileResource`, `PortalSessionResource`.
- Rutas: `routes/customer_portal.php`, requerido dentro de `/api/v1`.

Pruebas:

- Fixture común: `tests/Support/PortalTestCase.php`.
- Suites: persistencia, configuración, invitaciones, autenticación, reset, perfil, conocimiento, seguridad/RLS, OpenAPI y Postman.

Convenciones contractuales:

```php
CustomerPortal::query()->where('is_active', true);
PortalUser::STATUS_ACTIVE;
PortalUser::STATUS_SUSPENDED;
PortalInvitation::STATUS_PENDING;
PortalInvitation::STATUS_ACCEPTED;
PortalInvitation::STATUS_REVOKED;
PortalInvitation::STATUS_EXPIRED;
```

`PortalProfileResource` produce siempre:

```php
[
    'portal' => ['public_id', 'title', 'settings'],
    'account' => ['email', 'status', 'email_verified_at', 'last_login_at'],
    'contact' => ['first_name', 'last_name', 'phone'],
]
```

`PortalSessionResource` agrega `access_token`, `token_type`, `expires_at` y `portal_public_id` a esa proyección. Ningún otro recurso devuelve el bearer.

### Task 1: Persistencia, modelos, permisos y auditoría por tipo de principal

**Files:**

- Create: `database/migrations/2026_09_20_000000_create_customer_portal_foundations.php`
- Create: `app/Models/CustomerPortal.php`
- Create: `app/Models/PortalUser.php`
- Create: `app/Models/PortalInvitation.php`
- Create: `app/Models/PortalPasswordResetToken.php`
- Create: `tests/Feature/Api/PortalPersistenceTest.php`
- Modify: `app/Models/Contact.php`
- Modify: `app/Models/Tenant.php`
- Modify: `app/Models/AuditLog.php`
- Modify: `app/Support/AuditService.php`
- Modify: `app/Support/PermissionCatalog.php:32`
- Modify: `app/Providers/AppServiceProvider.php:98`

**Interfaces:**

- Consumes: `TenantScoped`, `TenantContext`, `AuditService`, `PermissionCatalog`, Sanctum `HasApiTokens`.
- Produces: los cuatro modelos tenant-scoped y sus constantes de estado.
- Produces: `AuditService::record(string $action, Model|string $entity, string|int|null $entityId = null, ?array $oldValues = null, ?array $newValues = null, ?Request $request = null, ?int $tenantId = null, User|PortalUser|null $actor = null): AuditLog`.
- Produces: morph alias `portal_user` para `PortalUser`.

- [ ] **Step 1: Escribir el RED de esquema, constraints y actor de auditoría.**

Crear `PortalPersistenceTest` con `RefreshDatabase`. Debe comprobar tablas/columnas, ausencia de slug, mismo correo en tenants diferentes, rechazo del mismo correo o contacto dentro del tenant, rechazo de contacto cruzado y atribución no ambigua:

```php
public function test_portal_schema_is_tenant_safe_and_has_no_slug(): void
{
    foreach ([
        'customer_portals',
        'portal_users',
        'portal_invitations',
        'portal_password_reset_tokens',
        'customer_portal_locators',
        'portal_invitation_locators',
    ] as $table) {
        $this->assertTrue(Schema::hasTable($table));
    }

    $this->assertFalse(Schema::hasColumn('customer_portals', 'slug'));
    $this->assertFalse(Schema::hasColumn('portal_users', 'slug'));
    $this->assertTrue(Schema::hasColumn('audit_logs', 'portal_user_id'));
}
```

Agregar un caso que cree `User` y `PortalUser` con el mismo ID, autentique cada uno por separado y afirme:

```php
$this->assertDatabaseHas('audit_logs', [
    'action' => 'portal.test',
    'user_id' => null,
    'portal_user_id' => $portalUser->id,
]);
```

- [ ] **Step 2: Ejecutar el RED y confirmar que falla por tablas/modelos ausentes.**

```powershell
php artisan test --compact tests/Feature/Api/PortalPersistenceTest.php
```

Resultado requerido: FAIL por `customer_portals` inexistente o clase `CustomerPortal` inexistente; no por conexión externa.

- [ ] **Step 3: Crear la migración reversible con localizadores fuera de RLS.**

La migración crea primero el índice padre de `contacts`, luego entidades, localizadores y la FK de auditoría:

```php
Schema::table('contacts', function (Blueprint $table): void {
    $table->unique(['id', 'tenant_id'], 'contacts_id_tenant_unique');
});

Schema::create('customer_portals', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->uuid('public_id')->unique();
    $table->string('title', 120);
    $table->boolean('is_active')->default(false);
    $table->json('settings')->nullable();
    $table->timestamps();
    $table->unique('tenant_id', 'customer_portals_tenant_unique');
    $table->unique(['id', 'tenant_id'], 'customer_portals_id_tenant_unique');
});

Schema::create('portal_users', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('contact_id');
    $table->string('email', 190);
    $table->string('password');
    $table->string('status', 20)->default('ACTIVE');
    $table->timestamp('email_verified_at')->nullable();
    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();
    $table->unique(['id', 'tenant_id'], 'portal_users_id_tenant_unique');
    $table->unique(['tenant_id', 'contact_id'], 'portal_users_contact_unique');
    $table->unique(['tenant_id', 'email'], 'portal_users_email_unique');
    $table->index(['tenant_id', 'status', 'last_login_at'], 'portal_users_list_index');
    $table->foreign(['contact_id', 'tenant_id'], 'portal_users_contact_tenant_fk')
        ->references(['id', 'tenant_id'])->on('contacts')->restrictOnDelete();
});

Schema::create('portal_invitations', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('contact_id');
    $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('email', 190);
    $table->char('token_hash', 64)->unique();
    $table->string('status', 20)->default('PENDING');
    $table->timestamp('expires_at');
    $table->timestamp('accepted_at')->nullable();
    $table->timestamps();
    $table->unique(['id', 'tenant_id'], 'portal_invitations_id_tenant_unique');
    $table->index(['tenant_id', 'contact_id', 'status'], 'portal_invitations_contact_index');
    $table->index(['tenant_id', 'status', 'expires_at'], 'portal_invitations_expiry_index');
    $table->foreign(['contact_id', 'tenant_id'], 'portal_invitations_contact_tenant_fk')
        ->references(['id', 'tenant_id'])->on('contacts')->restrictOnDelete();
});

Schema::create('portal_password_reset_tokens', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('portal_user_id');
    $table->char('token_hash', 64)->unique();
    $table->timestamp('expires_at');
    $table->timestamp('used_at')->nullable();
    $table->timestamps();
    $table->index(
        ['tenant_id', 'portal_user_id', 'used_at', 'expires_at'],
        'portal_password_resets_lookup_index',
    );
    $table->foreign(['portal_user_id', 'tenant_id'], 'portal_resets_user_tenant_fk')
        ->references(['id', 'tenant_id'])->on('portal_users')->cascadeOnDelete();
});

Schema::create('customer_portal_locators', function (Blueprint $table): void {
    $table->uuid('public_id')->primary();
    $table->unsignedBigInteger('portal_id')->unique();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->timestamps();
    $table->foreign(['portal_id', 'tenant_id'], 'portal_locators_portal_tenant_fk')
        ->references(['id', 'tenant_id'])->on('customer_portals')->cascadeOnDelete();
});

Schema::create('portal_invitation_locators', function (Blueprint $table): void {
    $table->char('token_hash', 64)->primary();
    $table->unsignedBigInteger('invitation_id')->unique();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->timestamps();
    $table->foreign(['invitation_id', 'tenant_id'], 'invitation_locators_invitation_tenant_fk')
        ->references(['id', 'tenant_id'])->on('portal_invitations')->cascadeOnDelete();
});

Schema::table('audit_logs', function (Blueprint $table): void {
    $table->foreignId('portal_user_id')->nullable()
        ->constrained('portal_users')->nullOnDelete();
    $table->index(['tenant_id', 'portal_user_id', 'created_at'], 'audit_logs_portal_actor_index');
});
```

Aplicar `ENABLE/FORCE ROW LEVEL SECURITY` y la policy estándar únicamente a `customer_portals`, `portal_users`, `portal_invitations` y `portal_password_reset_tokens` cuando el driver sea `pgsql` y `tenancy.rls_enabled=true`. Instalar `portal.manage` por `upsert` y concederlo a roles `is_system=true` o que ya tengan `settings.manage`.

En `down`: quitar primero FK/índice/columna `audit_logs.portal_user_id`; borrar localizadores, resets, invitaciones, usuarios y portal; retirar `portal.manage`; finalmente quitar `contacts_id_tenant_unique`. No envolver el rollback en una desactivación global de FKs.

- [ ] **Step 4: Implementar modelos, relaciones, morph map y auditoría discriminada.**

`PortalUser` debe ser autenticable, no un `Model` simple:

```php
class PortalUser extends Authenticatable
{
    use HasApiTokens, Notifiable, TenantScoped;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_SUSPENDED = 'SUSPENDED';

    protected $fillable = ['contact_id', 'email', 'password', 'status', 'email_verified_at', 'last_login_at'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
```

`CustomerPortal`, `PortalInvitation` y `PortalPasswordResetToken` usan `TenantScoped`, fillables mínimos, casts de fechas/JSON y relaciones a tenant/contacto/actor/cuenta. Agregar relaciones `Tenant::customerPortal()`, `Tenant::portalUsers()`, `Contact::portalUser()`, `Contact::portalInvitations()` y `AuditLog::portalUser()`.

Registrar:

```php
Relation::enforceMorphMap([
    'user' => User::class,
    'contact' => Contact::class,
    'organization' => Organization::class,
    'lead' => Lead::class,
    'deal' => Deal::class,
    'task' => Task::class,
    'file' => FileRecord::class,
    'entity_record' => EntityRecord::class,
    'portal_user' => PortalUser::class,
]);
```

Cambiar `AuditService` sin romper llamadas nombradas existentes:

```php
public function record(
    string $action,
    Model|string $entity,
    string|int|null $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?Request $request = null,
    ?int $tenantId = null,
    User|PortalUser|null $actor = null,
): AuditLog {
    $model = $entity instanceof Model ? $entity : null;
    $request ??= request();
    $tenant = $model?->getAttribute('tenant_id')
        ?? $tenantId
        ?? app(TenantContext::class)->requireId();
    $principal = $actor ?? $request?->user();
    if ($principal instanceof PortalUser && (int) $principal->tenant_id !== (int) $tenant) {
        throw new LogicException('The portal actor does not belong to the audit tenant.');
    }

    $context = app(TenantContext::class);
    $previousTenant = $context->id();
    if ($previousTenant !== (int) $tenant) {
        $context->set((int) $tenant);
    }

    try {
        return AuditLog::create([
            'tenant_id' => $tenant,
            'user_id' => $principal instanceof User ? $principal->id : null,
            'portal_user_id' => $principal instanceof PortalUser ? $principal->id : null,
            'action' => $action,
            'entity_type' => $model?->getTable() ?? (string) $entity,
            'entity_id' => (string) ($model?->getKey() ?? $entityId ?? 'unknown'),
            'old_values' => $this->redact($oldValues),
            'new_values' => $this->redact($newValues),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->header('X-Request-ID')
                ?? $request?->attributes->get('request_id'),
        ]);
    } finally {
        $previousTenant === null
            ? $context->clear()
            : $context->set($previousTenant);
    }
}
```

La redacción recorre arrays anidados:

```php
private function redact(?array $values): ?array
{
    if ($values === null) {
        return null;
    }

    $sensitive = [
        'password', 'password_confirmation', 'token', 'token_hash',
        'access_token', 'secret', 'credentials', 'private_key', 'api_key',
    ];
    foreach ($values as $key => $value) {
        if (in_array(mb_strtolower((string) $key), $sensitive, true)) {
            $values[$key] = '[REDACTED]';
        } elseif (is_array($value)) {
            $values[$key] = $this->redact($value);
        }
    }

    return $values;
}
```

- [ ] **Step 5: Ejecutar GREEN, migración reversible y regresión de auditoría.**

```powershell
php artisan test --compact tests/Feature/Api/PortalPersistenceTest.php
php artisan test --compact tests/Feature/Api/TenancyAndPermissionsTest.php
php artisan test --compact tests/Feature/Api/KnowledgeSecurityTest.php
vendor/bin/pint --test app/Models app/Support/AuditService.php app/Support/PermissionCatalog.php app/Providers/AppServiceProvider.php database/migrations/2026_09_20_000000_create_customer_portal_foundations.php tests/Feature/Api/PortalPersistenceTest.php
git diff --check
```

Para reversibilidad usar exclusivamente SQLite `:memory:` dentro del mismo proceso de prueba: `migrate`, `rollback --step=1`, afirmar tablas ausentes, `migrate` y afirmar tablas presentes.

- [ ] **Step 6: Revisar y confirmar Task 1.**

Revisar orden de FKs, tablas excluidas de RLS, campos ocultos, morph alias y compatibilidad de llamadas existentes a `AuditService`. Preparar solo estos archivos y crear:

```text
feat: add customer portal persistence
```

### Task 2: Administración interna de configuración y cuentas

**Files:**

- Create: `app/Policies/CustomerPortalPolicy.php`
- Create: `app/Services/CustomerPortalService.php`
- Create: `app/Http/Requests/CustomerPortalSettingsRequest.php`
- Create: `app/Http/Requests/PortalUserStatusRequest.php`
- Create: `app/Http/Resources/CustomerPortalResource.php`
- Create: `app/Http/Resources/PortalUserAdminResource.php`
- Create: `app/Http/Controllers/Api/CustomerPortalSettingsController.php`
- Create: `app/Http/Controllers/Api/CustomerPortalUserController.php`
- Create: `routes/customer_portal.php`
- Create: `tests/Support/PortalTestCase.php`
- Create: `tests/Feature/Api/PortalConfigurationTest.php`
- Modify: `app/Http/Middleware/ResolveTenant.php:14`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/api.php:37`

**Interfaces:**

- Consumes: modelos de Task 1, `ChecksTenantPermission`, `ApiResponse`, `AuditService`.
- Produces: `CustomerPortalService::upsert(array $data, User $actor): array{portal: CustomerPortal, created: bool}`.
- Produces: `CustomerPortalService::changeStatus(PortalUser $portalUser, string $status, User $actor): PortalUser`.
- Produces: `PortalTestCase::portalFixture(array $permissions = PermissionCatalog::ALL, bool $active = true): array{user: User, tenant: Tenant, token: string, portal: CustomerPortal, contact: Contact}`.
- Produces: `PortalTestCase::internalApi(array $fixture): static`, con Bearer interno y `X-Tenant-ID`.

- [ ] **Step 1: Escribir el RED de settings, listado, suspensión y principal interno estricto.**

La suite debe cubrir:

```php
public function test_manager_creates_one_portal_without_slug_or_editable_uuid(): void
{
    $client = $this->createTenantUser(['portal.manage']);
    $api = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', $client['tenant']->id);

    $response = $api->putJson('/api/v1/customer-portal/settings', [
        'title' => 'Portal Acme',
        'is_active' => true,
        'settings' => [
            'welcome_message' => 'Bienvenido',
            'support_email' => 'soporte@example.test',
        ],
    ])->assertCreated();

    $this->assertTrue(Str::isUuid($response->json('data.public_id')));
    $this->assertFalse(Schema::hasColumn('customer_portals', 'slug'));
    $this->assertDatabaseCount('customer_portals', 1);
    $this->assertDatabaseCount('customer_portal_locators', 1);
}
```

Agregar: GET antes de configurar = 404; segundo PUT = 200 y conserva UUID; claves desconocidas/settings/campos de servidor = 422; sin permiso = 403; tenant ajeno = 404; filtros/paginación válidos; suspensión revoca tokens; reactivación no emite token.

Crear deliberadamente un `PortalUser` cuyo ID coincide con un `User`, autenticarlo con Sanctum y llamar una ruta interna: debe responder 403 antes de consultar `tenant_user`.

- [ ] **Step 2: Ejecutar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/PortalConfigurationTest.php
```

Resultado requerido: FAIL por rutas ausentes y por `ResolveTenant` aceptando cualquier autenticable.

- [ ] **Step 3: Implementar requests, policy y servicio transaccional.**

Reglas exactas:

```php
// CustomerPortalSettingsRequest
[
    'title' => ['required', 'string', 'min:1', 'max:120'],
    'is_active' => ['required', 'boolean'],
    'settings' => ['sometimes', 'array:welcome_message,support_email'],
    'settings.welcome_message' => ['nullable', 'string', 'max:500'],
    'settings.support_email' => ['nullable', 'email:rfc', 'max:190'],
    'id' => ['missing'],
    'tenant_id' => ['missing'],
    'public_id' => ['missing'],
    'slug' => ['missing'],
]

// PortalUserStatusRequest
[
    'status' => ['required', Rule::in([
        PortalUser::STATUS_ACTIVE,
        PortalUser::STATUS_SUSPENDED,
    ])],
    'tenant_id' => ['missing'],
    'contact_id' => ['missing'],
    'email' => ['missing'],
    'password' => ['missing'],
]
```

`upsert` bloquea por tenant, genera `Str::uuid()` solo al crear, crea entidad y locator en una transacción, reemplaza settings permitidos y revoca todos los tokens de las cuentas del tenant cuando pasa de activo a inactivo. Debe mapear la carrera del unique de tenant a una recarga/actualización, no a 500.

`changeStatus` bloquea la cuenta y, al suspender, borra todos sus tokens antes de auditar. Las acciones son `portal.settings.created`, `portal.settings.updated`, `portal.settings.disabled`, `portal.user.suspended` y `portal.user.reactivated`.

`CustomerPortalPolicy` acepta solo `User`, exige `portal.manage` y compara `tenant_id` cuando recibe modelo. Registrar esa policy para `CustomerPortal`, `PortalUser` y `PortalInvitation`; los controladores llaman `Gate::authorize` antes de cada lectura o mutación.

- [ ] **Step 4: Crear recursos, controladores, rutas y fixture común.**

Rutas internas:

```php
Route::prefix('customer-portal')
    ->middleware(['auth:sanctum', 'tenant.context'])
    ->group(function (): void {
        Route::get('settings', [CustomerPortalSettingsController::class, 'show']);
        Route::put('settings', [CustomerPortalSettingsController::class, 'upsert']);
        Route::get('users', [CustomerPortalUserController::class, 'index']);
        Route::patch('users/{portalUser}', [CustomerPortalUserController::class, 'update'])
            ->whereNumber('portalUser');
    });
```

`PortalUserAdminResource` expone ID interno solo a administración, contacto reducido, correo, estado y fechas; nunca contraseña ni tokens. El listado valida `q` máximo 120, `status`, `contact_id`, `sort` (`created_at`, `last_login_at`, `email`), `direction`, `page` y `per_page` 1–100; escapa `%`, `_` y `!`.

Endurecer `ResolveTenant` antes de construir `TenantUser`:

```php
if (! $user instanceof User) {
    return ApiResponse::error('This principal cannot access the internal API.', [], 403);
}
```

Registrar policy y requerir `customer_portal.php` dentro de `/api/v1` sin mover rutas existentes.

- [ ] **Step 5: Obtener GREEN y verificar regresiones internas.**

```powershell
php artisan test --compact tests/Feature/Api/PortalConfigurationTest.php
php artisan test --compact tests/Feature/Api/TenancyAndPermissionsTest.php
php artisan test --compact tests/Feature/Api/KnowledgeConfigurationTest.php
php artisan route:list --path=api/v1/customer-portal
vendor/bin/pint --test app/Policies/CustomerPortalPolicy.php app/Services/CustomerPortalService.php app/Http/Requests/CustomerPortalSettingsRequest.php app/Http/Requests/PortalUserStatusRequest.php app/Http/Resources/CustomerPortalResource.php app/Http/Resources/PortalUserAdminResource.php app/Http/Controllers/Api/CustomerPortalSettingsController.php app/Http/Controllers/Api/CustomerPortalUserController.php app/Http/Middleware/ResolveTenant.php routes/customer_portal.php tests/Support/PortalTestCase.php tests/Feature/Api/PortalConfigurationTest.php
git diff --check
```

- [ ] **Step 6: Revisar y confirmar Task 2.**

Confirmar que ningún recurso administrativo cruza tenant y que desactivar/suspender revoca tokens dentro de la transacción. Commit:

```text
feat: add customer portal administration
```

### Task 3: Invitaciones, activación y primera sesión

**Files:**

- Create: `app/Support/PortalEmail.php`
- Create: `app/Support/PortalToken.php`
- Create: `app/Services/PortalInvitationService.php`
- Create: `app/Http/Requests/PortalInvitationRequest.php`
- Create: `app/Http/Requests/PortalInvitationAcceptRequest.php`
- Create: `app/Http/Resources/PortalInvitationAdminResource.php`
- Create: `app/Http/Resources/PortalProfileResource.php`
- Create: `app/Http/Resources/PortalSessionResource.php`
- Create: `app/Notifications/PortalInvitationNotification.php`
- Create: `app/Http/Controllers/Api/CustomerPortalInvitationController.php`
- Create: `app/Http/Controllers/Api/PortalInvitationController.php`
- Create: `tests/Feature/Api/PortalInvitationTest.php`
- Modify: `routes/customer_portal.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**

- Produces: `PortalEmail::normalize(?string $email): ?string`.
- Produces: `PortalEmail::mask(string $email): string`.
- Produces: `PortalToken::issue(): string` y `PortalToken::hash(string $token): string`.
- Produces: `PortalInvitationService::invite(Contact $contact, User $actor): array{invitation: PortalInvitation, notification_sent: bool, activation_url: string}`.
- Produces: `PortalInvitationService::inspect(string $token): array`.
- Produces: `PortalInvitationService::accept(string $token, array $data): array{portal: CustomerPortal, user: PortalUser, access_token: string, expires_at: CarbonImmutable}`.
- Produces: `PortalInvitationService::revoke(PortalInvitation $invitation, User $actor): PortalInvitation`.
- Produces: `PortalTestCase::createPortalUser(array $fixture, string $password = 'Portal-Password!2026'): PortalUser`.
- Produces: `PortalTestCase::portalSession(array $fixture, string $password = 'Portal-Password!2026'): array{user: PortalUser, token: string, expires_at: CarbonImmutable}`.

- [ ] **Step 1: Escribir el RED del ciclo completo de invitación.**

Casos mínimos:

```php
public function test_manager_invites_contact_and_token_is_never_persisted_plain(): void
{
    Notification::fake();
    $fixture = $this->portalFixture();

    $response = $this->internalApi($fixture)
        ->postJson('/api/v1/customer-portal/invitations', [
            'contact_id' => $fixture['contact']->id,
        ])
        ->assertCreated()
        ->assertJsonPath('meta.notification_sent', true);

    $activationUrl = $response->json('meta.activation_url');
    $token = Str::afterLast($activationUrl, '/');
    $this->assertDatabaseMissing('portal_invitations', ['token_hash' => $token]);
    $this->assertDatabaseHas('portal_invitations', [
        'token_hash' => hash('sha256', $token),
        'email' => mb_strtolower($fixture['contact']->email),
        'status' => PortalInvitation::STATUS_PENDING,
    ]);
}
```

Añadir pruebas de: contacto sin correo = 422; otro tenant = 422 sin fuga; portal ausente/inactivo = 409; cuenta existente = 409; reinvitar revoca anterior; listado/filtros; DELETE pendiente y ya revocada = 200; aceptada = 409; GET devuelve correo enmascarado; inválida = 404; expirada = 410/`EXPIRED`; contacto eliminado o cuyo correo cambió después de invitar no puede aceptar; aceptación crea una sola cuenta, verifica correo, marca `ACCEPTED`, devuelve token `portal` con expiración; segundo consumo = 409.

- [ ] **Step 2: Ejecutar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/PortalInvitationTest.php
```

Resultado requerido: FAIL por endpoints y servicios ausentes.

- [ ] **Step 3: Implementar helpers, requests y notificación.**

Helpers sin estado:

```php
final class PortalToken
{
    public static function issue(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

final class PortalEmail
{
    public static function normalize(?string $email): ?string
    {
        $value = mb_strtolower(trim((string) $email));

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }
}
```

`mask()` conserva primera letra local y dominio:

```php
public static function mask(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);

    return mb_substr($local, 0, 1)
        .str_repeat('*', max(3, mb_strlen($local) - 1))
        .'@'.$domain;
}
```

Requests:

```php
// PortalInvitationRequest
[
    'contact_id' => [
        'required',
        'integer',
        Rule::exists('contacts', 'id')
            ->where('tenant_id', app(TenantContext::class)->requireId())
            ->whereNull('deleted_at'),
    ],
]

// PortalInvitationAcceptRequest
[
    'password' => ['required', 'confirmed', Password::defaults()],
    'device_name' => ['nullable', 'string', 'min:1', 'max:120'],
    'tenant_id' => ['missing'],
    'contact_id' => ['missing'],
    'email' => ['missing'],
]
```

`PortalInvitationNotification` recibe nombre de portal, nombre de contacto y URL de activación. Usa `Notification::route('mail', $email)` y nunca implementa representación de base de datos.

- [ ] **Step 4: Implementar servicio transaccional y contratos HTTP.**

`invite` debe:

```php
return DB::transaction(function () use ($contact, $actor, $plainToken): array {
    $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);
    $portal = CustomerPortal::query()->where('is_active', true)->first();
    abort_if($portal === null, 409, 'Activate the customer portal before inviting contacts.');

    $email = PortalEmail::normalize($locked->email);
    throw_if($email === null, ValidationException::withMessages([
        'contact_id' => ['The selected contact needs a valid email address.'],
    ]));

    abort_if(PortalUser::query()->where(
        fn (Builder $query) => $query->where('contact_id', $locked->id)->orWhere('email', $email),
    )->exists(), 409, 'A portal account already exists for this contact or email.');

    PortalInvitation::query()
        ->where('contact_id', $locked->id)
        ->where('status', PortalInvitation::STATUS_PENDING)
        ->lockForUpdate()
        ->get()
        ->each->update(['status' => PortalInvitation::STATUS_REVOKED]);

    $tokenHash = PortalToken::hash($plainToken);
    $invitation = PortalInvitation::create([
        'contact_id' => $locked->id,
        'invited_by' => $actor->id,
        'email' => $email,
        'token_hash' => $tokenHash,
        'status' => PortalInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);
    DB::table('portal_invitation_locators')->insert([
        'token_hash' => $tokenHash,
        'invitation_id' => $invitation->id,
        'tenant_id' => $invitation->tenant_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->audit->record(
        'portal.user.invited',
        $invitation,
        newValues: ['contact_id' => $locked->id, 'expires_at' => $invitation->expires_at],
        actor: $actor,
    );

    return [
        'invitation' => $invitation,
        'activation_url' => url('/api/v1/portal/invitations/'.$plainToken),
    ];
});
```

Enviar correo después de que la transacción termine, capturar la excepción sin registrar URL/token y devolver `notification_sent=false` sin revertir datos.

El listado interno valida `status`, `contact_id`, `created_from`, `created_to`, `expires_before`, `sort` (`created_at` o `expires_at`), `direction`, `page` y `per_page` 1–100. `show` público devuelve solo `portal_public_id`, título, correo enmascarado, nombre de contacto y `expires_at`; los estados no utilizables se resuelven como 409/410 y no se serializan como datos normales.

`inspect` y `accept` consultan primero `portal_invitation_locators` por hash exacto, establecen temporalmente `TenantContext`, cargan entidad por `id + tenant_id + token_hash` y restauran el contexto en `finally`. `accept` bloquea invitación/contacto, valida correo snapshot, crea `PortalUser`, marca aceptación y audita con `actor: $portalUser`. Después del commit emite:

```php
$expiresAt = CarbonImmutable::now()->addDays(30);
$accessToken = $portalUser
    ->createToken($deviceName, ['portal'], $expiresAt)
    ->plainTextToken;
```

Capturar únicamente violaciones de `portal_users_contact_unique`/`portal_users_email_unique` y mapearlas a 409; cualquier otra `QueryException` se vuelve a lanzar.

Rutas:

```php
Route::prefix('customer-portal')
    ->middleware(['auth:sanctum', 'tenant.context'])
    ->group(function (): void {
        Route::get('invitations', [CustomerPortalInvitationController::class, 'index']);
        Route::post('invitations', [CustomerPortalInvitationController::class, 'store']);
        Route::delete('invitations/{invitation}', [CustomerPortalInvitationController::class, 'destroy'])
            ->whereNumber('invitation');
    });

Route::prefix('portal/invitations')
    ->middleware('throttle:portal-invitation')
    ->where('token', '[A-Za-z0-9]{64}')
    ->group(function (): void {
        Route::get('{token}', [PortalInvitationController::class, 'show']);
        Route::post('{token}/accept', [PortalInvitationController::class, 'accept']);
    });
```

Registrar limitador de 20/minuto por `hash(token)|IP`. `store` agrega `meta.activation_url` solo en `local/testing`; producción entrega únicamente `notification_sent`.

- [ ] **Step 5: Obtener GREEN y comprobar no filtración.**

```powershell
php artisan test --compact tests/Feature/Api/PortalInvitationTest.php
php artisan test --compact tests/Feature/Api/PortalPersistenceTest.php
php artisan route:list --path=api/v1/portal/invitations
vendor/bin/pint --test app/Support/PortalEmail.php app/Support/PortalToken.php app/Services/PortalInvitationService.php app/Http/Requests/PortalInvitationRequest.php app/Http/Requests/PortalInvitationAcceptRequest.php app/Http/Resources/PortalInvitationAdminResource.php app/Http/Resources/PortalProfileResource.php app/Http/Resources/PortalSessionResource.php app/Notifications/PortalInvitationNotification.php app/Http/Controllers/Api/CustomerPortalInvitationController.php app/Http/Controllers/Api/PortalInvitationController.php tests/Feature/Api/PortalInvitationTest.php
git diff --check
```

Examinar serializaciones y `audit_logs` buscando el token plano, contraseña, hash o URL; todos deben estar ausentes.

- [ ] **Step 6: Revisar y confirmar Task 3.**

Confirmar restauración de `TenantContext`, envío posterior al commit, consumo único y estados 404/409/410. Commit:

```text
feat: add portal invitation onboarding
```

### Task 4: Contexto externo, login, logout y aislamiento Sanctum

**Files:**

- Create: `app/Http/Middleware/ResolvePortalContext.php`
- Create: `app/Http/Middleware/EnsurePortalPrincipal.php`
- Create: `app/Services/PortalAuthService.php`
- Create: `app/Http/Requests/PortalLoginRequest.php`
- Create: `app/Http/Controllers/Api/PortalAuthController.php`
- Create: `tests/Feature/Api/PortalAuthenticationTest.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/customer_portal.php`

**Interfaces:**

- Produces: request attributes `customer_portal` (`CustomerPortal`) y `portal_user` (`PortalUser`).
- Produces: `PortalAuthService::login(CustomerPortal $portal, array $credentials): array{portal: CustomerPortal, user: PortalUser, access_token: string, expires_at: CarbonImmutable}`.
- Produces: `PortalAuthService::logout(PortalUser $user): void`.

- [ ] **Step 1: Escribir el RED de contexto, sesiones y separación de principales.**

Caso principal:

```php
public function test_portal_user_logs_in_without_tenant_header_and_token_has_only_portal_ability(): void
{
    $fixture = $this->portalFixture();
    $portalUser = $this->createPortalUser($fixture, 'Portal-Password!2026');

    $response = $this->postJson(
        "/api/v1/portal/{$fixture['portal']->public_id}/auth/login",
        [
            'email' => mb_strtoupper($portalUser->email),
            'password' => 'Portal-Password!2026',
            'device_name' => 'postman',
        ],
    )->assertOk();

    $token = PersonalAccessToken::findToken($response->json('data.access_token'));
    $this->assertSame(['portal'], $token->abilities);
    $this->assertNotNull($token->expires_at);
}
```

Añadir: UUID mal formado/desconocido = 404; password/cuenta inexistente comparten 401; suspendido/portal inactivo = 403; mismo dispositivo reemplaza token; dispositivos distintos coexisten; logout revoca solo actual; token expirado = 401; header tenant falso no cambia contexto; token `User` interno = 403 en portal; token `PortalUser` = 403 en API interna.

- [ ] **Step 2: Ejecutar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/PortalAuthenticationTest.php
```

Resultado requerido: FAIL por alias/rutas ausentes.

- [ ] **Step 3: Implementar middleware en el orden aprobado.**

`ResolvePortalContext`:

```php
public function handle(Request $request, Closure $next): Response
{
    $publicId = (string) $request->route('portalPublicId');
    $locator = DB::table('customer_portal_locators')
        ->where('public_id', $publicId)
        ->first();
    abort_if($locator === null, 404, 'Resource not found.');

    $previous = $this->context->id();
    try {
        $this->context->set((int) $locator->tenant_id);
        $portal = CustomerPortal::query()
            ->whereKey($locator->portal_id)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $request->attributes->set('customer_portal', $portal);

        return $next($request);
    } finally {
        $previous === null ? $this->context->clear() : $this->context->set($previous);
    }
}
```

No lee `X-Tenant-ID`. `EnsurePortalPrincipal` devuelve 403 salvo que:

```php
$user instanceof PortalUser
&& $user->tokenCan('portal')
&& $user->status === PortalUser::STATUS_ACTIVE
&& $portal->is_active
&& (int) $user->tenant_id === (int) $portal->tenant_id
&& Contact::query()->whereKey($user->contact_id)->exists();
```

Registrar aliases:

```php
'portal.context' => ResolvePortalContext::class,
'portal.principal' => EnsurePortalPrincipal::class,
```

- [ ] **Step 4: Implementar login/logout, limitadores y rutas.**

Reglas de login:

```php
[
    'email' => ['required', 'email:rfc', 'max:190'],
    'password' => ['required', 'string'],
    'device_name' => ['nullable', 'string', 'min:1', 'max:120'],
    'tenant_id' => ['missing'],
    'portal_id' => ['missing'],
]
```

`login` normaliza correo, responde `Invalid credentials.` para cuenta/password inválidos, borra tokens de esa cuenta con el mismo `name`, crea token de 30 días/`['portal']`, actualiza `last_login_at` y audita `portal.auth.login`. `logout` elimina solo `currentAccessToken()` y audita `portal.auth.logout`.

Limitadores:

```php
RateLimiter::for('portal-login', fn (Request $request) => Limit::perMinute(5)->by(hash(
    'sha256',
    (string) $request->route('portalPublicId').'|'.
    (string) PortalEmail::normalize(is_string($request->input('email')) ? $request->input('email') : null).'|'.
    (string) $request->ip(),
)));

RateLimiter::for('portal-authenticated', fn (Request $request) => Limit::perMinute(120)->by(
    (string) ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.(string) $request->ip(),
));
```

Rutas dentro del grupo UUID:

```php
Route::prefix('portal/{portalPublicId}')
    ->whereUuid('portalPublicId')
    ->middleware('portal.context')
    ->group(function (): void {
        Route::post('auth/login', [PortalAuthController::class, 'login'])
            ->middleware('throttle:portal-login');

        Route::middleware([
            'auth:sanctum',
            'portal.principal',
            'throttle:portal-authenticated',
        ])->group(function (): void {
            Route::post('auth/logout', [PortalAuthController::class, 'logout']);
        });
    });
```

La aceptación de Task 3 y login comparten `PortalSessionResource`; ambos devuelven el bearer una sola vez.

- [ ] **Step 5: Obtener GREEN y verificar ambas superficies.**

```powershell
php artisan test --compact tests/Feature/Api/PortalAuthenticationTest.php
php artisan test --compact tests/Feature/Api/PortalConfigurationTest.php
php artisan test --compact tests/Feature/Api/TenancyAndPermissionsTest.php
php artisan route:list --path=api/v1/portal
vendor/bin/pint --test app/Http/Middleware/ResolvePortalContext.php app/Http/Middleware/EnsurePortalPrincipal.php app/Services/PortalAuthService.php app/Http/Requests/PortalLoginRequest.php app/Http/Controllers/Api/PortalAuthController.php bootstrap/app.php app/Providers/AppServiceProvider.php routes/customer_portal.php tests/Feature/Api/PortalAuthenticationTest.php
git diff --check
```

- [ ] **Step 6: Revisar y confirmar Task 4.**

Revisar el orden `portal.context` → `auth:sanctum` → `portal.principal`, expiración real en `personal_access_tokens` y rechazo simétrico de principales. Commit:

```text
feat: add customer portal sessions
```

### Task 5: Recuperación y restablecimiento de contraseña

**Files:**

- Create: `app/Services/PortalPasswordResetService.php`
- Create: `app/Http/Requests/PortalForgotPasswordRequest.php`
- Create: `app/Http/Requests/PortalResetPasswordRequest.php`
- Create: `app/Notifications/PortalPasswordResetNotification.php`
- Create: `tests/Feature/Api/PortalPasswordResetTest.php`
- Modify: `app/Http/Controllers/Api/PortalAuthController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/customer_portal.php`

**Interfaces:**

- Produces: `PortalPasswordResetService::request(CustomerPortal $portal, string $email): array{notification_sent: bool, reset_url: ?string}`.
- Produces: `PortalPasswordResetService::reset(CustomerPortal $portal, array $data): PortalUser`.

- [ ] **Step 1: Escribir el RED no enumerable y de consumo único.**

```php
public function test_forgot_password_has_identical_response_for_existing_and_unknown_email(): void
{
    Notification::fake();
    $fixture = $this->portalFixture();
    $user = $this->createPortalUser($fixture);
    $url = "/api/v1/portal/{$fixture['portal']->public_id}/auth/forgot-password";

    $known = $this->postJson($url, ['email' => $user->email])->assertOk();
    $unknown = $this->postJson($url, ['email' => 'unknown@example.test'])->assertOk();

    $this->assertSame($known->json('data.message'), $unknown->json('data.message'));
}
```

Agregar: portal inactivo mantiene 200 y no crea token; hashes, 60 minutos y URL solo local/testing; solicitud nueva invalida pendientes anteriores; reset correcto cambia hash y revoca todas las sesiones; reuse = 422; expirado auténtico = 410; email/token de otro tenant = 422; respuesta/auditoría no contienen token/password.

- [ ] **Step 2: Ejecutar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/PortalPasswordResetTest.php
```

Resultado requerido: FAIL por métodos/rutas ausentes.

- [ ] **Step 3: Implementar requests, notificación y solicitud genérica.**

```php
// PortalForgotPasswordRequest
['email' => ['required', 'email:rfc', 'max:190']]

// PortalResetPasswordRequest
[
    'email' => ['required', 'email:rfc', 'max:190'],
    'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
    'password' => ['required', 'confirmed', Password::defaults()],
    'tenant_id' => ['missing'],
    'portal_user_id' => ['missing'],
]
```

`request` siempre devuelve el mismo mensaje desde el controlador. Solo si portal/cuenta están activos: bloquea cuenta, marca pendientes previos como usados, crea token/hash con expiración `now()->addMinutes(60)`, audita sin secreto y notifica después del commit. La notificación usa portal UUID, correo y URL de reset. Un fallo del mailer produce `notification_sent=false` en el resultado del servicio, no revierte el token y nunca cambia cuerpo/status de la respuesta pública de producción.

- [ ] **Step 4: Implementar reset transaccional, rate limit y endpoints.**

`reset` exige portal y cuenta activos, localiza dentro del tenant por cuenta + hash, bloquea fila/cuenta, devuelve 422 genérico si no existe/no coincide/usado, 410 si venció, y en éxito:

```php
$user->forceFill(['password' => $data['password']])->save();
$reset->update(['used_at' => now()]);
$user->tokens()->delete();
$this->audit->record(
    'portal.password_reset.completed',
    $user,
    actor: $user,
);
```

Limitador de reset: 3/hora por UUID + hash de correo normalizado + IP. Rutas bajo `portal.context`, sin Sanctum:

```php
Route::post('auth/forgot-password', [PortalAuthController::class, 'forgotPassword'])
    ->middleware('throttle:portal-reset');
Route::post('auth/reset-password', [PortalAuthController::class, 'resetPassword'])
    ->middleware('throttle:portal-reset');
```

El controlador agrega `meta.reset_url` solo en `local/testing`; producción omite URL/token aun cuando la notificación falle.

- [ ] **Step 5: Obtener GREEN y ejecutar regresión de sesiones.**

```powershell
php artisan test --compact tests/Feature/Api/PortalPasswordResetTest.php
php artisan test --compact tests/Feature/Api/PortalAuthenticationTest.php
vendor/bin/pint --test app/Services/PortalPasswordResetService.php app/Http/Requests/PortalForgotPasswordRequest.php app/Http/Requests/PortalResetPasswordRequest.php app/Notifications/PortalPasswordResetNotification.php app/Http/Controllers/Api/PortalAuthController.php app/Providers/AppServiceProvider.php routes/customer_portal.php tests/Feature/Api/PortalPasswordResetTest.php
git diff --check
```

- [ ] **Step 6: Revisar y confirmar Task 5.**

Comparar status/mensajes de correo conocido/desconocido, revisar revocación y buscar secretos en diff/auditoría. Commit:

```text
feat: add portal password recovery
```

### Task 6: Perfil autenticado ligado al contacto

**Files:**

- Create: `app/Services/PortalProfileService.php`
- Create: `app/Http/Requests/PortalProfileRequest.php`
- Create: `app/Http/Controllers/Api/PortalProfileController.php`
- Create: `tests/Feature/Api/PortalProfileTest.php`
- Modify: `routes/customer_portal.php`

**Interfaces:**

- Consumes: `PortalProfileResource`, request attributes `customer_portal` y `portal_user`.
- Produces: `PortalProfileService::profile(PortalUser $user): PortalUser`.
- Produces: `PortalProfileService::update(PortalUser $user, array $data): PortalUser`.

- [ ] **Step 1: Escribir el RED de `me` y edición acotada.**

```php
public function test_portal_user_reads_profile_without_internal_ids(): void
{
    $fixture = $this->portalFixture();
    $session = $this->portalSession($fixture);

    $response = $this->withToken($session['token'])
        ->getJson("/api/v1/portal/{$fixture['portal']->public_id}/me")
        ->assertOk()
        ->assertJsonPath('data.account.email', $session['user']->email)
        ->assertJsonPath('data.contact.first_name', $fixture['contact']->first_name);

    foreach (['tenant_id', 'contact_id', 'owner_id', 'custom_fields', 'password'] as $forbidden) {
        $this->assertArrayNotHasKey($forbidden, $response->json('data.account') ?? []);
        $this->assertArrayNotHasKey($forbidden, $response->json('data.contact') ?? []);
    }
}
```

Agregar: PATCH modifica solo nombres/teléfono; límites 120/120/50; `email`, tenant, contacto, estado, owner, organización, password, timestamps y campos desconocidos = 422; contacto borrado = 403; contacto cruzado = 403; cambio administrativo de `contacts.email` no cambia login ni `account.email`; auditoría solo contiene campos permitidos.

- [ ] **Step 2: Ejecutar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/PortalProfileTest.php
```

Resultado requerido: FAIL por rutas/controlador ausentes.

- [ ] **Step 3: Implementar request y servicio.**

```php
[
    'first_name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
    'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
    'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
    'email' => ['missing'],
    'tenant_id' => ['missing'],
    'contact_id' => ['missing'],
    'status' => ['missing'],
    'password' => ['missing'],
    'owner_id' => ['missing'],
    'organization_id' => ['missing'],
    'custom_fields' => ['missing'],
    'created_at' => ['missing'],
    'updated_at' => ['missing'],
]
```

`profile` carga `contact` dentro del contexto actual o devuelve 403. `update` bloquea contacto, conserva valores omitidos, actualiza solo `first_name`, `last_name`, `phone` y audita:

```php
$this->audit->record(
    'portal.profile.updated',
    $contact,
    oldValues: Arr::only($before, array_keys($data)),
    newValues: Arr::only($contact->fresh()->toArray(), array_keys($data)),
    actor: $user,
);
```

- [ ] **Step 4: Implementar controlador y rutas protegidas.**

```php
Route::middleware([
    'auth:sanctum',
    'portal.principal',
    'throttle:portal-authenticated',
])->group(function (): void {
    Route::get('me', [PortalProfileController::class, 'show']);
    Route::patch('profile', [PortalProfileController::class, 'update']);
});
```

No duplicar serialización: aceptación, login, `me` y PATCH usan `PortalProfileResource`.

- [ ] **Step 5: Obtener GREEN y verificar serialización.**

```powershell
php artisan test --compact tests/Feature/Api/PortalProfileTest.php
php artisan test --compact tests/Feature/Api/PortalAuthenticationTest.php
php artisan test --compact tests/Feature/Api/CrmFlowTest.php
vendor/bin/pint --test app/Services/PortalProfileService.php app/Http/Requests/PortalProfileRequest.php app/Http/Controllers/Api/PortalProfileController.php app/Http/Resources/PortalProfileResource.php routes/customer_portal.php tests/Feature/Api/PortalProfileTest.php
git diff --check
```

- [ ] **Step 6: Revisar y confirmar Task 6.**

Confirmar que el recurso usa `portal_users.email`, no `contacts.email`, y que no aparecen IDs internos. Commit:

```text
feat: add customer portal profile
```

### Task 7: Lectura autenticada de conocimiento `CUSTOMER`

**Files:**

- Create: `app/Services/PublishedKnowledgeQuery.php`
- Create: `app/Services/PortalKnowledgeService.php`
- Create: `app/Http/Requests/PublishedKnowledgeRequest.php`
- Create: `app/Http/Controllers/Api/PortalKnowledgeController.php`
- Create: `tests/Feature/Api/PortalKnowledgeTest.php`
- Modify: `app/Services/PublicKnowledgeService.php`
- Modify: `app/Http/Controllers/Api/PublicKnowledgeController.php`
- Modify: `routes/customer_portal.php`

**Interfaces:**

- Produces: `PublishedKnowledgeQuery::categories(array $visibilities): Collection`.
- Produces: `PublishedKnowledgeQuery::articles(array $filters, array $visibilities): LengthAwarePaginator`.
- Produces: `PublishedKnowledgeQuery::article(string $articlePublicId, array $visibilities): KnowledgeArticle`.
- Produces: `PortalKnowledgeService::base(): KnowledgeBase`.
- Produces: `PortalKnowledgeService::home(): array`.
- Produces: `PortalKnowledgeService::categories(): Collection`.
- Produces: `PortalKnowledgeService::articles(array $filters): LengthAwarePaginator`.
- Produces: `PortalKnowledgeService::article(string $articlePublicId): KnowledgeArticle`.
- Produces: `PortalTestCase::portalKnowledgeFixture(bool $knowledgePublic = false): array`, agregando claves `base`, `category`, `public`, `customer`, `internal`, `draft` y `archived` al fixture.

- [ ] **Step 1: Escribir el RED de matriz de visibilidad y snapshot.**

Construir artículos publicados `PUBLIC`, `CUSTOMER` e `INTERNAL`, además de borrador y archivado:

```php
public function test_portal_sees_public_and_customer_but_never_internal_or_drafts(): void
{
    $fixture = $this->portalKnowledgeFixture(knowledgePublic: false);
    $session = $this->portalSession($fixture);
    $root = "/api/v1/portal/{$fixture['portal']->public_id}/knowledge";

    $list = $this->withToken($session['token'])
        ->getJson($root.'/articles')
        ->assertOk();

    $this->assertEqualsCanonicalizing(
        [$fixture['public']->public_id, $fixture['customer']->public_id],
        array_column($list->json('data'), 'public_id'),
    );
    $this->withToken($session['token'])
        ->getJson($root.'/articles/'.$fixture['internal']->public_id)
        ->assertNotFound();
}
```

Agregar: `KnowledgeBase.is_public=false` sigue accesible al portal; anónimo público recibe 404; una revisión `CUSTOMER` no publicada no reemplaza snapshot anterior; combinación portal/artículo de otro tenant = 404; filtros/orden/paginación iguales a API-7.2; `PublicKnowledgeService` continúa mostrando solo `PUBLIC`.

- [ ] **Step 2: Ejecutar el RED.**

```powershell
php artisan test --compact tests/Feature/Api/PortalKnowledgeTest.php
```

Resultado requerido: FAIL por rutas/clases ausentes.

- [ ] **Step 3: Extraer consulta publicada con visibilidades explícitas.**

La clase rechaza entrada vacía o distinta de `PUBLIC`/`CUSTOMER`:

```php
private function assertVisibilities(array $visibilities): void
{
    $values = array_values(array_unique($visibilities));
    if ($values === [] || array_diff($values, ['PUBLIC', 'CUSTOMER']) !== []) {
        throw new InvalidArgumentException('Published knowledge visibilities must be explicit.');
    }
}
```

Todas las consultas parten de:

```php
KnowledgeArticle::query()
    ->where('status', 'PUBLISHED')
    ->whereNotNull('published_version_id')
    ->whereHas('publishedVersion', fn (Builder $version) =>
        $version->whereIn('visibility', $visibilities))
    ->with(['publishedVersion.category', 'publishedVersion.tags']);
```

La búsqueda escapa `!`, `%`, `_`; los filtros se aplican al snapshot publicado y el orden solo admite `title`/`published_at`. No existe valor predeterminado para `$visibilities`.

- [ ] **Step 4: Delegar API pública y crear adaptación del portal.**

`PublicKnowledgeService` conserva resolución de base pública y `withinTenant`, pero delega siempre con:

```php
private const VISIBILITIES = ['PUBLIC'];
```

`PortalKnowledgeService` busca la base por el tenant ya resuelto sin filtrar `is_public` y delega con:

```php
private const VISIBILITIES = ['PUBLIC', 'CUSTOMER'];
```

`PublishedKnowledgeRequest` valida `q`, `category_id`, `tag_id`, `per_page`, `page`, `sort`, `direction`; ambos controladores lo reutilizan. `PortalKnowledgeController` usa los recursos públicos ya saneados y no agrega visibilidad, IDs internos o versión de trabajo.

Rutas protegidas:

```php
Route::get('knowledge', [PortalKnowledgeController::class, 'home']);
Route::get('knowledge/categories', [PortalKnowledgeController::class, 'categories']);
Route::get('knowledge/articles', [PortalKnowledgeController::class, 'articles']);
Route::get('knowledge/articles/{articlePublicId}', [PortalKnowledgeController::class, 'article'])
    ->whereUuid('articlePublicId');
```

- [ ] **Step 5: Obtener GREEN y ejecutar regresión completa de API-7.2.**

```powershell
php artisan test --compact tests/Feature/Api/PortalKnowledgeTest.php
php artisan test --compact tests/Feature/Api/PublicKnowledgeApiTest.php
php artisan test --compact tests/Feature/Api/KnowledgePublicationTest.php
php artisan test --compact tests/Feature/Api/KnowledgeSecurityTest.php
vendor/bin/pint --test app/Services/PublishedKnowledgeQuery.php app/Services/PortalKnowledgeService.php app/Services/PublicKnowledgeService.php app/Http/Requests/PublishedKnowledgeRequest.php app/Http/Controllers/Api/PortalKnowledgeController.php app/Http/Controllers/Api/PublicKnowledgeController.php routes/customer_portal.php tests/Feature/Api/PortalKnowledgeTest.php
git diff --check
```

- [ ] **Step 6: Revisar y confirmar Task 7.**

Buscar cada llamada a `PublishedKnowledgeQuery` y confirmar que pasa un array literal/constante no vacío. Commit:

```text
feat: expose customer knowledge in portal
```

### Task 8: Hardening multi-tenant, carreras, rate limits y PostgreSQL RLS

**Files:**

- Create: `tests/Feature/Api/PortalSecurityTest.php`
- Create: `tests/Feature/Api/PortalPostgresRlsTest.php`
- Modify: `.github/workflows/ci.yml`
- Modify: archivos de Tasks 1–7 solo cuando un RED de esta tarea demuestre el defecto.

**Interfaces:**

- Consumes: todos los endpoints y servicios API-7.3A.
- Produces: una prueba PostgreSQL focalizada ejecutable con `TENANT_RLS_ENABLED=true`.
- Produces: helper privado `PortalSecurityTest::collidingPrincipals(): array{0: array{user: User, tenant: Tenant, token: string}, 1: array{user: PortalUser, portal: CustomerPortal, token: string}}`.
- No produce endpoints nuevos.

- [ ] **Step 1: Escribir el RED de confusión de principal y cruces de tenant.**

Crear dos tenants con UUID, contactos, cuentas y artículos. Forzar el mismo ID numérico entre `User`/`PortalUser` y probar:

```php
public function test_colliding_internal_and_portal_ids_never_cross_or_misattribute_audit(): void
{
    [$internal, $external] = $this->collidingPrincipals();

    $this->withToken($external['token'])
        ->withHeader('X-Tenant-ID', $internal['tenant']->id)
        ->getJson('/api/v1/contacts')
        ->assertForbidden();

    $this->withToken($internal['token'])
        ->getJson("/api/v1/portal/{$external['portal']->public_id}/me")
        ->assertForbidden();
}
```

Agregar matriz cruzada exacta: token de portal B contra UUID de portal A = 403; token interno contra portal = 403; token externo contra API interna = 403; ID administrativo de usuario/invitación de otro tenant = 404; artículo de otro tenant bajo portal A = 404; `contact_id` de otro tenant al invitar = 422; token de invitación inexistente = 404. Ninguna respuesta incluye nombres o correos del otro tenant.

- [ ] **Step 2: Escribir RED de rate limits, carreras y redacción.**

Usar `Cache::flush()` entre casos con `CACHE_STORE=array`. Confirmar sexto login/minuto = 429, cuarta solicitud reset/hora = 429, vigésima primera invitación/minuto = 429 y solicitud autenticada 121 = 429.

Simular carrera de aceptación insertando una cuenta equivalente en un hook `PortalUser::creating`; la API debe mapear unique violation a 409, nunca 500. Probar dos aceptaciones secuenciales para una sola cuenta y una sola invitación aceptada. Inspeccionar JSON de `audit_logs`:

```php
$serialized = AuditLog::query()->get()->toJson();
$this->assertStringNotContainsString($plainInvitationToken, $serialized);
$this->assertStringNotContainsString('New-Portal-Password!2026', $serialized);
$this->assertStringNotContainsString('token_hash', $serialized);
```

- [ ] **Step 3: Implementar la prueba PostgreSQL de bootstrap RLS.**

`PortalPostgresRlsTest` usa `RefreshDatabase` y se omite fuera de PostgreSQL:

```php
if (DB::getDriverName() !== 'pgsql') {
    $this->markTestSkipped('PostgreSQL-only RLS verification.');
}
$this->assertTrue(config('tenancy.rls_enabled'));
```

Con contexto vacío, afirmar que los localizadores se consultan y las cuatro tablas de negocio no entregan filas. Con contexto tenant A, solo filas A; con tenant B, solo B. Finalmente llamar login e inspección de invitación por UUID/hash sin contexto previo y comprobar 200, demostrando el bootstrap.

No reutilizar `createTenantUser()` antes de establecer contexto: crear tenant/user, llamar `TenantContext::set($tenant->id)` y después crear role/membership/contacto/portal.

- [ ] **Step 4: Añadir el gate de RLS a CI y ejecutar GREEN local.**

Después de la suite PostgreSQL existente:

```yaml
- name: PostgreSQL portal RLS bootstrap
  env:
    TENANT_RLS_ENABLED: true
  run: vendor/bin/pest -c phpunit.postgres.xml tests/Feature/Api/PortalPostgresRlsTest.php
```

Local:

```powershell
php artisan test --compact tests/Feature/Api/PortalSecurityTest.php
php artisan test --compact tests/Feature/Api/PortalAuthenticationTest.php
php artisan test --compact tests/Feature/Api/PortalInvitationTest.php
php artisan test --compact tests/Feature/Api/PortalPasswordResetTest.php
```

Si no existe PostgreSQL desechable local, no apuntar a otra base: dejar la evidencia RLS para CI y reportarlo.

- [ ] **Step 5: Verificar migración, rutas y formato.**

```powershell
php artisan route:list --json
vendor/bin/pint --test tests/Feature/Api/PortalSecurityTest.php tests/Feature/Api/PortalPostgresRlsTest.php
php -l tests/Feature/Api/PortalSecurityTest.php
php -l tests/Feature/Api/PortalPostgresRlsTest.php
git diff --check
```

Comprobar que las rutas externas no incluyen middleware `tenant.context`, que las protegidas sí muestran `portal.context`, `auth:sanctum`, `portal.principal` en ese orden y que las tablas locator no tienen policy RLS.

- [ ] **Step 6: Revisar y confirmar Task 8.**

Invocar `requesting-code-review` sobre Tasks 1–8. Cada defecto aceptado recibe primero un RED focalizado. Commit:

```text
test: harden customer portal isolation
```

### Task 9: OpenAPI, Postman, documentación y verificación integral

**Files:**

- Create: `docs/18-API-7-3A-CUSTOMER-PORTAL-IDENTITY.md`
- Create: `tests/Feature/Api/PortalOpenApiContractTest.php`
- Create: `tests/Feature/Api/PortalPostmanWorkflowTest.php`
- Modify: `docs/openapi.yaml`
- Modify: `docs/postman/Vantex CRM API.postman_collection.json`
- Modify: `docs/postman/README.md`
- Modify: `README.md:75`
- Modify: `docs/09-GAP-ANALYSIS.md:134`
- Modify: `tests/Feature/Api/KnowledgeOpenApiContractTest.php:50`

**Interfaces:**

- OpenAPI agrega 17 paths y 19 operaciones API-7.3A.
- Postman agrega `14 - API-7.3A identidad de portal`; seguridad pasa a `15 - Seguridad y validaciones`.
- Variables nuevas: `portal_public_id`, `portal_invitation_token`, `portal_access_token`, `portal_user_id`, `portal_contact_id`, `portal_reset_token`, `portal_article_public_id`, `portal_article_id`, `portal_category_id`.

- [ ] **Step 1: Escribir el RED de contrato OpenAPI.**

`PortalOpenApiContractTest` carga YAML y exige las 19 operaciones, seguridad correcta y schemas sin campos del servidor:

```php
private const PATHS = [
    '/customer-portal/settings',
    '/customer-portal/users',
    '/customer-portal/users/{portalUser}',
    '/customer-portal/invitations',
    '/customer-portal/invitations/{invitation}',
    '/portal/invitations/{token}',
    '/portal/invitations/{token}/accept',
    '/portal/{portalPublicId}/auth/login',
    '/portal/{portalPublicId}/auth/forgot-password',
    '/portal/{portalPublicId}/auth/reset-password',
    '/portal/{portalPublicId}/auth/logout',
    '/portal/{portalPublicId}/me',
    '/portal/{portalPublicId}/profile',
    '/portal/{portalPublicId}/knowledge',
    '/portal/{portalPublicId}/knowledge/categories',
    '/portal/{portalPublicId}/knowledge/articles',
    '/portal/{portalPublicId}/knowledge/articles/{articlePublicId}',
];
```

Las cinco rutas internas usan `bearerAuth` + `TenantHeader`; invitación/login/reset no autenticados declaran `security: []`; logout/me/profile/knowledge usan solo `bearerAuth`, nunca `TenantHeader`. Actualizar la aserción global antigua de API-7.2 a `>= 508` y afirmar exactamente 527 operaciones en la suite API-7.3A.

- [ ] **Step 2: Escribir el RED del flujo Postman autocontenido.**

La carpeta tiene exactamente estas 26 solicitudes:

```text
Portal - Crear contacto
Portal - Configurar y activar
Portal - Invitar contacto
Portal - Inspeccionar invitación
Portal - Aceptar invitación
Portal - Consultar me
Portal - Actualizar perfil
Portal - Configurar base pública
Portal - Crear categoría
Portal - Crear artículo CUSTOMER
Portal - Publicar artículo CUSTOMER
Portal - Consultar inicio de conocimiento
Portal - Listar conocimiento
Portal - Consultar artículo CUSTOMER
Portal - API pública oculta CUSTOMER
Portal - Solicitar reset
Portal - Completar reset
Portal - Token anterior revocado
Portal - Login con nueva contraseña
Portal - Listar cuentas
Portal - Suspender cuenta
Portal - Cuenta suspendida rechazada
Portal - Reactivar cuenta
Portal - Login tras reactivación
Portal - Logout
Portal - Token de logout revocado
```

El test ejecuta requests contra Laravel, usa `Http::preventStrayRequests()`, `Notification::fake()`, scripts por `tests/Support/postman-script-runner.cjs` y comprueba cada status. Requests del portal usan `auth.type=noauth`, header explícito `Authorization: Bearer {{portal_access_token}}` y nunca `X-Tenant-ID`; requests administrativos/knowledge interno heredan token interno + tenant.

- [ ] **Step 3: Documentar OpenAPI y colección sin secretos.**

Schemas mínimos:

```text
CustomerPortal
CustomerPortalSettingsInput
PortalUserAdmin
PortalUserStatusInput
PortalInvitationAdmin
PortalInvitationCreateInput
PortalInvitationPreview
PortalInvitationAcceptInput
PortalLoginInput
PortalForgotPasswordInput
PortalResetPasswordInput
PortalProfile
PortalProfileUpdate
PortalSession
```

Agregar marcadores `# BEGIN/END API-7.3A PORTAL SCHEMAS` para que el test limite sus `$ref`. Los schemas externos no documentan `password`, token/hash ni IDs internos; los schemas administrativos sí incluyen los IDs numéricos necesarios para `PATCH`/`DELETE`, siempre bajo Bearer interno + tenant.

La colección captura tokens únicamente desde `meta.activation_url`/`meta.reset_url` en local/testing y desde `data.access_token`; no incluye valores reales exportados. Los scripts limpian o sustituyen `portal_access_token` al reset/login y comprueban revocación.

- [ ] **Step 4: Escribir guía y actualizar estado parcial.**

`docs/18-API-7-3A-CUSTOMER-PORTAL-IDENTITY.md` cubre arquitectura, tablas/localizadores, permiso, invitaciones, auth/reset, perfil, conocimiento, errores, rate limits, Postman y comandos.

Actualizar GAP:

```text
| Portal de cliente | PARTIAL | API-7.3A completa identidad por invitación, sesiones, perfil y conocimiento CUSTOMER; tickets/documentos y relación comercial permanecen en API-7.3B/7.3C. |
```

README enlaza la guía y mantiene pendientes 7.3B, 7.3C, Customer Success y encuestas. `docs/postman/README.md` explica la carpeta 14, variables automáticas y ausencia de correo/proveedores reales. No declarar API-7 completa.

- [ ] **Step 5: Obtener GREEN de contratos y runner.**

```powershell
php artisan test --compact tests/Feature/Api/PortalOpenApiContractTest.php
php artisan test --compact tests/Feature/Api/PortalPostmanWorkflowTest.php
php artisan test --compact tests/Feature/Api/KnowledgeOpenApiContractTest.php
php artisan test --compact tests/Feature/Api/KnowledgePostmanWorkflowTest.php
node --check tests/Support/postman-script-runner.cjs
git diff --check
```

Validar JSON de colección/entorno con `ConvertFrom-Json` y verificar que las variables nuevas empiezan vacías.

- [ ] **Step 6: Ejecutar verificación integral y revisión final.**

```powershell
$env:APP_ENV = 'testing'
$env:APP_KEY = '0123456789abcdef0123456789abcdef'
$env:DATABASE_URL = 'sqlite:///:memory:'
php artisan test --compact
vendor/bin/pint --test
Get-ChildItem app,database,routes,tests -Recurse -Filter *.php |
    ForEach-Object { php -l $_.FullName; if ($LASTEXITCODE -ne 0) { throw "PHP syntax failed: $($_.FullName)" } }
npm run build
git diff --check
git diff --cached --check
```

Ejecutar `requesting-code-review`; corregir defectos con RED focalizado y repetir suite afectada + completa. Usar `verification-before-completion` antes de afirmar terminado.

- [ ] **Step 7: Confirmar documentación y commit final.**

Comparar `route:list --json`, OpenAPI y colección; releer GAP/README para que solo API-7.3A figure terminada. Commit:

```text
docs: document API-7.3A customer portal
```

## Cobertura del diseño

| Requisito del spec | Tareas |
| --- | --- |
| Portal único, UUID, sin slug, settings | 1–2 |
| Identidad `PortalUser` separada y tenant-safe | 1, 4, 8 |
| Localizadores previos a RLS | 1, 3–4, 8 |
| Permiso y administración interna | 1–3 |
| Invitación, revocación, caducidad y aceptación | 3, 8 |
| Login/logout, dispositivo, habilidad y expiración | 3–4, 8 |
| Reset no enumerable y revocación | 5, 8 |
| Perfil limitado y correo no editable | 6, 8 |
| Conocimiento `PUBLIC` + `CUSTOMER` publicado | 7–8 |
| `PUBLIC` anónimo sin regresión | 7, 9 |
| Auditoría por principal y redacción | 1–8 |
| Rate limits, errores y carreras | 3–5, 8 |
| SQLite + PostgreSQL/RLS | 1–9 |
| OpenAPI, Postman, README, GAP y guía | 9 |
| Vercel y API-7.3B/7.3C fuera de alcance | Global, 9 |

Antes de cerrar API-7.3A, releer las 14 secciones del spec contra esta tabla, registrar conteos finales de pruebas/aserciones, enumerar cualquier gate CI que no se haya podido ejecutar localmente y confirmar que el worktree solo contiene cambios de esta fase. Después usar `finishing-a-development-branch` para ofrecer opciones de integración sin hacer push o merge no autorizado.
