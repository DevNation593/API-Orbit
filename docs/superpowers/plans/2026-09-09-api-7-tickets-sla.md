# API-7.1 Tickets and SLA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar operación interna de tickets con agentes, colas, calendarios, SLA medibles y escalaciones recuperables, con aislamiento multiempresa y un flujo Postman verificable.

**Architecture:** Módulo Laravel integrado, separado del inbox. Servicios pequeños concentran configuración, ciclo del ticket, cálculo temporal y escalaciones; transacciones y restricciones únicas protegen los efectos. Se reutiliza autenticación, autorización, auditoría y notificaciones existentes.

**Tech Stack:** PHP ^8.3, Laravel ^13.17, Sanctum ^4.3, Horizon ^5.48, Pest ^4.7, SQLite para pruebas locales y PostgreSQL para producción; dependencias ya presentes, sin instalar paquetes.

**Spec:** `docs/superpowers/specs/2026-09-09-api-7-tickets-sla-design.md`, aprobada explícitamente por el usuario después del commit 704c36b.

## Global Constraints

- Se mantienen las decisiones del usuario: sin slug; IDs internos numéricos; PostgreSQL solo mediante DATABASE_URL y Redis solo mediante REDIS_URL. No se requieren nuevas credenciales ni cambios en .env.
- Todas las tablas nuevas tienen tenant_id y timestamps. Las referencias tienen claves foráneas y validación de pertenencia al tenant. Los modelos de negocio usan TenantScoped; los jobs requieren contexto explícito.
- No se borran tickets, comentarios, ejecuciones ni escalaciones por API.
- Los cambios posteriores de prioridad, cola o configuración no modifican ese snapshot.
- Registrar PUBLIC no envía email/WhatsApp ni habilita acceso de clientes: documenta la atención realizada.
- CLOSED solo permite lectura y replays idempotentes, nunca cambios nuevos.
- Durante el desarrollo no se aplican migraciones a la base real, no se envían mensajes reales y no se modifican credenciales ni configuración de conexión.
- La revisión y las pruebas cubren solo este bloque; no declarar completos portal, knowledge base, Customer Success ni encuestas.

---

## Entorno, seguridad y evidencia

Repositorio real: `D:\Proyectos Personales\Orbit CRM\API Orbit`; la ruta nominal CRM ya no existe. Rama actual dev, checkout normal, con avances anteriores sin commit. Solicitar preferencia antes de crear un worktree. No hacer reset, stash, checkout de archivos, commit global ni push. Conservar archivos previos y modificaciones concurrentes.

Las ediciones locales usan apply_patch; fuera del workspace nominal necesitan la aprobación de sandbox correspondiente. Los commits de cada tarea deben contener únicamente cambios propios. En archivos previamente modificados, preparar solo los hunks de esta tarea; si no se pueden aislar sin incorporar trabajo anterior, dejar esos hunks sin commit y adjuntar al paquete de revisión el diff de esta tarea respecto a su copia inicial. No usar `git add .` ni `git commit -a`.

Antes de ejecutar pruebas, comprobar que no existe bootstrap/cache/config.php. Ejecutar desde la raíz real con variables solo para ese proceso PowerShell:

```powershell
if (Test-Path -LiteralPath 'bootstrap\cache\config.php') { throw 'Verificar configuración cacheada antes de probar.' }
$env:APP_ENV = 'testing'
$env:DATABASE_URL = 'sqlite:///:memory:'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:BROADCAST_CONNECTION = 'null'
$env:SESSION_DRIVER = 'array'
php artisan test --compact
```

Base verificada antes de implementar: 80 pruebas, 1.226 aserciones, cero fallos. Este dato no reemplaza una nueva verificación al terminar.

Para cada ciclo RED: el primer fallo debe demostrar la funcionalidad ausente (por ejemplo 404 en una ruta nueva), no un typo o una conexión real. Registrar comando y resultado RED/GREEN. Para cualquier fallo inesperado usar systematic-debugging. Revisar cada tarea antes de avanzar.

## Mapa de archivos y contratos compartidos

- Persistencia: `database/migrations/2026_09_09_000000_create_support_foundations.php` y los diez modelos enumerados en la tarea 1, más la tabla pivot.
- Dominio: `app/Support/SupportCatalog.php`, `app/Policies/SupportPolicy.php`, `app/Policies/TicketPolicy.php` y servicios detallados por tarea.
- HTTP: controllers separados por recurso, requests de validación y `routes/support.php`. Añadir un require a `routes/api.php` dentro de v1, junto al require de marketing, sin alterar sus rutas.
- Pruebas: `tests/Support/SupportTestCase.php`, suites `SupportConfigurationTest`, `SlaCalendarTest`, `SlaConfigurationTest`, `TicketApiTest`, `TicketLifecycleSlaTest`, `SupportEscalationTest`, `SupportSecurityTest` y `SupportPostmanWorkflowTest`.
- Documentación: `docs/16-API-7-1-TICKETS-SLA.md`, OpenAPI, colección/README Postman, README principal y análisis de brechas.

Convenciones para todos los implementadores:

- Namespace de modelos App\Models; servicios App\Services; clases de prueba Tests\Support.
- Fechas de acciones: `now()->toImmutable()->utc()->startOfSecond()`, capturadas después del bloqueo del ticket. Los inputs no fijan tiempos.
- `SlaRule.policy_id`, `SlaExecution.ticket_id`, `SlaEscalation.execution_id`; definir claves explícitas en las relaciones que no coinciden con la convención Eloquent.
- Pivot: `support_queue_agents(tenant_id, queue_id, agent_id)`.
- Calendario público: `weekly_schedule` objeto con claves "1" a "7"; cada valor es una lista de objetos `{"start":"09:00","end":"17:00"}`; holidays lista de fechas.
- Snapshot: `policy_id`, `policy_name`, `priority`, `first_response_seconds`, `resolution_seconds`, `pause_on_waiting_customer`, `calendar`. calendar contiene mode, timezone, weekly_schedule y holidays normalizados.
- Respuesta de ticket incluye `sla` (objeto o null) y `assignee_available`; no expandir mensajes o perfiles CRM. Ocultar payload_hash y datos técnicos de reservas/idempotencia de las respuestas.
- Creación idempotente devuelve `array{ticket: Ticket, replayed: bool}`; comentario, `array{comment: TicketComment, replayed: bool}`.
- Resolver listas con filtros permitidos y paginación ApiResponse. Referencias de payload de otro tenant: 422 sin datos; rutas de otro tenant: 404.

### Task 1: Catálogos de soporte y persistencia del dominio

**Files:**

- Create: `database/migrations/2026_09_09_000000_create_support_foundations.php`.
- Create: `app/Models/SupportAgent.php`, `TicketCategory.php`, `SupportQueue.php`, `Ticket.php`, `TicketComment.php`, `SlaBusinessCalendar.php`, `SlaPolicy.php`, `SlaRule.php`, `SlaExecution.php`, `SlaEscalation.php` (todos bajo app/Models).
- Create: `app/Support/SupportCatalog.php`, `app/Policies/SupportPolicy.php`, `app/Services/SupportConfigurationService.php`.
- Create: `app/Http/Requests/SupportAgentRequest.php`, `SupportCategoryRequest.php`, `SupportQueueRequest.php`.
- Create: `app/Http/Controllers/Api/SupportAgentController.php`, `SupportCategoryController.php`, `SupportQueueController.php`.
- Create: `routes/support.php`, `tests/Support/SupportTestCase.php`, `tests/Feature/Api/SupportConfigurationTest.php`.
- Modify: `app/Support/PermissionCatalog.php` (ALL), `app/Providers/AppServiceProvider.php` (boot/policies), `routes/api.php` (v1 require).

**Interfaces:**

Consumes User::hasPermission(string, ?int): bool, TenantContext, AuditService y ApiResponse existentes.
Produces SupportConfigurationService::saveAgent(array, ?SupportAgent = null): SupportAgent; saveCategory(array, ?TicketCategory = null): TicketCategory; saveQueue(array, ?SupportQueue = null): SupportQueue; replaceQueueAgents(SupportQueue, array): SupportQueue; delete(Model): void; isEligible(SupportAgent): bool; assertAssignment(SupportQueue, ?int): ?SupportAgent.
Produces relaciones SupportAgent::queues(), SupportQueue::agents()/slaPolicy()/escalationAgent(), Ticket::sla()/comments()/queue()/assignee(), SlaPolicy::rules()/calendar(), SlaExecution::ticket()/escalations(), SlaEscalation::execution(), TicketComment::ticket()/author().
Produces SupportTestCase::supportFixture(bool $withSla = true, array $permissions = PermissionCatalog::ALL): array con user, tenant, token, agent, queue y policy (nullable).

- [ ] **Step 1: Prueba RED de alta y aislamiento del catálogo.**

En SupportConfigurationTest usar TestCase y RefreshDatabase. El fallo que debe detectar es una ruta ausente o una consulta que lea/escriba en el tenant incorrecto:

```php
it('creates support categories only in the active tenant', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $response = $api->postJson('/api/v1/support/categories', ['name' => 'Facturación']);
    $response->assertCreated()->assertJsonPath('data.name', 'Facturación');
    $this->assertDatabaseHas('ticket_categories', [
        'id' => $response->json('data.id'), 'tenant_id' => $client['tenant']->id,
    ]);
});
```

Ejecutar `php artisan test --compact tests/Feature/Api/SupportConfigurationTest.php`. Resultado RED esperado: 404 en lugar de 201. Antes de ampliar el código, añadir casos literales de agente propio 201, usuario externo 422, miembro sin tickets.reply 422, lectura ajena 404, falta de support.manage 403 y duplicado de agente 409.

- [ ] **Step 2: Migración y modelos mínimos que permiten operar y proteger las referencias.**

Seguir el patrón de la migración de marketing para tenant_id, JSONB, permisos y RLS opcional. Crear en orden agentes, categorías, calendarios, políticas, reglas, colas, pivot, tickets, comentarios, ejecuciones y escalaciones. Al revertir usar el orden inverso. No ejecutar migraciones fuera de pruebas.

Persistir todos los campos de la especificación. En sla_executions agregar first_response_due_at, first_response_at, resolution_due_at, last_resolution_due_at, resolved_at, paused_at, resolution_anchor_at, resolution_remaining_seconds, first_response_breached/resolution_breached y first_response_breached_at/resolution_breached_at. Campos de control en sla_escalations: status, recipients JSONB, attempts (0), next_attempt_at, reserved_until, reservation_token UUID nullable y last_error nullable. Presupuesto entero en segundos, nunca float.

```php
$table->unique(['tenant_id', 'idempotency_key'], 'support_ticket_key_unique');
$table->unique(['tenant_id', 'ticket_id', 'idempotency_key'], 'support_comment_key_unique');
$table->unique(['tenant_id', 'execution_id', 'metric'], 'support_escalation_unique');
```

Aplicar cada índice a su tabla, no a una sola. TicketComment, SlaExecution y SlaEscalation usan FK restrictivas; tablas de configuración también restringen borrado si hay historial. Una ejecución es única por ticket. No SoftDeletes ni cascadas que borren historial de soporte al borrar un catálogo. TenantScoped en todos los modelos; casts boolean/datetime/array donde corresponda.

- [ ] **Step 3: Permisos, validación de agentes y CRUD de configuración.**

Añadir exactamente estas claves y registrarlas en la migración para roles de sistema o con settings.manage:

```php
[
    'support.view', 'support.manage', 'tickets.view', 'tickets.create',
    'tickets.update', 'tickets.assign', 'tickets.reply',
    'tickets.comment_internal', 'tickets.change_status', 'sla.view', 'sla.manage',
]
```

La elegibilidad no se limita al permiso del usuario autenticado:

```php
public function isEligible(SupportAgent $agent): bool
{
    $tenantId = app(TenantContext::class)->requireId();
    $user = $agent->user;
    return (int) $agent->tenant_id === $tenantId
        && $agent->is_active
        && $user !== null
        && $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->exists()
        && $user->hasPermission('tickets.view', $tenantId)
        && $user->hasPermission('tickets.reply', $tenantId);
}
```

Requests aceptan solo campos definidos. name 1–160, description nullable hasta 5.000, is_active boolean; user_id inmutable al actualizar agente. IDs positivos. Agregar métodos index/store/show/update/destroy a los tres controllers y PUT /support/queues/{queue}/agents con agent_ids distintos (0–100). GET /support/agents admite filtro user_id entero positivo, además de paginación; probar búsqueda existente y resultado vacío para el flujo Postman. scope+findOrFail antes de autorizar por recurso.

PUT bloquea cola y agentes involucrados, valida todo antes de sync; pivot incluye tenant_id. Proteger cola/agente con tickets no CLOSED y agentes referenciados como escalation_agent_id. Una cola nueva no puede nombrar un agente de escalación hasta tenerlo como miembro: crear, asociar y después PATCH. Borrado de agente con historial cerrado sigue siendo 409 por referencias; desactivación sí se permite si no hay casos abiertos ni configuración de escalación.

SupportPolicy distingue support.* para agentes/categorías/colas de sla.* para políticas/calendarios. Esos dos últimos recursos se habilitan en Task 2. Registros/auditoría quedan en la misma transacción. No guardar cuerpos privados en audit.

- [ ] **Step 4: Fixture reutilizable, pruebas GREEN y revisión.**

Crear SupportTestCase extendiendo Tests\TestCase. supportFixture crea un cliente con createTenantUser, activa el TenantContext, registra el agente del usuario, una cola activa y su pivot; si withSla=true crea política "Base", calendar_id=null, pause_on_waiting_customer=true, y cuatro reglas (60/240 minutos). Este código fija la interfaz de fixture:

```php
$client = $this->createTenantUser($permissions);
app(TenantContext::class)->set((int) $client['tenant']->id);
$agent = SupportAgent::create(['user_id' => $client['user']->id, 'is_active' => true]);
$policy = $withSla ? SlaPolicy::create([
    'name' => 'Base', 'is_active' => true, 'pause_on_waiting_customer' => true,
]) : null;
if ($policy !== null) {
    foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $priority) {
        $policy->rules()->create([
            'priority' => $priority, 'first_response_minutes' => 60, 'resolution_minutes' => 240,
        ]);
    }
}
$queue = SupportQueue::create(['name' => 'Soporte', 'is_active' => true, 'sla_policy_id' => $policy?->id]);
$queue->agents()->attach($agent->id, ['tenant_id' => $client['tenant']->id]);
return $client + ['agent' => $agent, 'queue' => $queue, 'policy' => $policy];
```

Ampliar casos de protección usando tickets de fixture: OPEN impide retirar/desactivar; CLOSED conserva referencia histórica y permite desactivar, no borrar. Cambiar permisos/membresía después de registrar agente debe impedir nueva asignación.

Ejecutar suite de configuración y suite completa. Pint y php -l solo sobre archivos de esta tarea. Guardar evidencia, revisar cumplimiento/calidad y hacer commit únicamente del delta propio si es aislable: `feat: add tenant-scoped support configuration`.

### Task 2: Calendarios hábiles y políticas de SLA

**Files:**

- Create: `app/Services/SlaCalendarService.php`, `app/Services/SlaConfigurationService.php`.
- Create: `app/Http/Requests/SlaCalendarRequest.php`, `SlaPolicyRequest.php`.
- Create: `app/Http/Controllers/Api/SlaCalendarController.php`, `SlaPolicyController.php`.
- Create: `tests/Unit/SlaCalendarTest.php`, `tests/Feature/Api/SlaConfigurationTest.php`.
- Modify: `routes/support.php`, `app/Providers/AppServiceProvider.php` (policies si faltan).

**Interfaces:**

Consumes modelos y SupportTestCase de Task 1.
Produces SlaCalendarService::normalize(array): array; addWorkingSeconds(CarbonImmutable $from, int $seconds, array $calendar): CarbonImmutable; workingSecondsBetween(CarbonImmutable $from, CarbonImmutable $to, array $calendar): int.
Produces SlaConfigurationService::saveCalendar(array, ?SlaBusinessCalendar = null): SlaBusinessCalendar; savePolicy(array, ?SlaPolicy = null): SlaPolicy; delete(Model): void.

- [ ] **Step 1: RED del cálculo con resultados manuales.**

SlaCalendarTest usa Tests\TestCase (sin RefreshDatabase). Debe fallar si se cuenta el fin de semana o se interpreta mal la zona:

```php
it('resumes a business deadline on monday in the calendar timezone', function (): void {
    $calendar = [
        'mode' => 'BUSINESS', 'timezone' => 'America/Guayaquil', 'holidays' => [],
        'weekly_schedule' => array_fill_keys([1, 2, 3, 4, 5], [['start' => '09:00', 'end' => '17:00']]),
    ];
    $result = app(SlaCalendarService::class)->addWorkingSeconds(
        CarbonImmutable::parse('2026-09-11 21:30:00', 'UTC'), 7200, $calendar,
    );
    expect($result->format('Y-m-d H:i:sP'))->toBe('2026-09-14 15:30:00+00:00');
});
```

Añadir tabla de valores independientes: viernes 16:30 local +30 min = viernes 17:00; +90 min con lunes feriado = martes 10:00; cero segundos conserva el instante incluso fuera de horario; ALWAYS +3600 suma una hora real; solapamientos, zona inválida, días 0/8, end<=start, horario vacío y feriados repetidos responden ValidationException.

Ejecutar `php artisan test --compact tests/Unit/SlaCalendarTest.php`; RED debe ser servicio ausente antes de implementarlo.

- [ ] **Step 2: Implementar intervalos UTC, presupuesto entero y límites.**

normalize valida mode ALWAYS/BUSINESS, timezone con DateTimeZone, calendario semanal y exclusiones; no confía en que siempre lo invoque un FormRequest. Para ALWAYS devolver calendario normalizado sin horarios obligatorios. Los intervalos no cruzan medianoche; 24:00 solo en end. Enumerar días locales, excluir holidays, convertir sus límites a UTC, ordenar intervalos y recortar al rango consultado.

El núcleo de consumo es:

```php
$start = max($from->getTimestamp(), $intervalStart->getTimestamp());
$available = max(0, $intervalEnd->getTimestamp() - $start);
if ($remaining <= $available) {
    return CarbonImmutable::createFromTimestampUTC($start + $remaining);
}
$remaining -= $available;
```

Solo usar ese núcleo cuando available>0, salvo seconds=0 que retorna from antes del recorrido. workingSecondsBetween suma intersecciones positivas hasta to, sin modificar inputs. Limitar ambos recorridos a diez años desde from; fallar con ValidationException antes de devolver un cálculo parcial.

Para un límite local ambiguo, construir candidatos usando cada offset presente en DateTimeZone::getTransitions dentro de dos días del límite, y conservar los que al volver a esa zona reproduzcan la hora solicitada. Elegir el menor timestamp para start y mayor para end. Si no hay candidatos, encontrar el salto cuyo rango de horas inexistentes contiene el límite y usar el instante de transición. No confiar en la normalización implícita del constructor PHP.

Casos DST literales America/New_York: domingo 2026-03-08, intervalo 02:30–04:00 equivale a 07:00–08:00 UTC; domingo 2026-11-01, intervalo 01:30–02:30 equivale a 05:30–07:30 UTC. Probar ambos límites y consumo, no solo formato de fechas.

- [ ] **Step 3: RED/GREEN de los endpoints de calendarios y políticas.**

SlaConfigurationTest usa SupportTestCase + RefreshDatabase. Crear el calendario anterior por POST, capturar ID y crear política con reglas completas:

```php
$rules = array_map(fn (string $priority): array => [
    'priority' => $priority, 'first_response_minutes' => 60, 'resolution_minutes' => 240,
], ['LOW', 'MEDIUM', 'HIGH', 'URGENT']);
$response = $api->postJson('/api/v1/support/sla-policies', [
    'name' => 'Horario de oficina', 'calendar_id' => $calendarId,
    'pause_on_waiting_customer' => true, 'rules' => $rules,
]);
$response->assertCreated()->assertJsonCount(4, 'data.rules');
```

Ejecutar antes de registrar rutas: RED 404. Implementar GET/POST y GET/PATCH/DELETE con sla.view/manage, validación tenant-safe, reglas atómicas únicas por prioridad. Rechazar reglas incompletas, duplicadas, primera respuesta mayor a resolución, cero, más de 525600 y calendarios externos/inactivos con 422. PATCH que omite rules conserva reglas; si las proporciona reemplaza las cuatro atómicamente. Borrado referenciado 409; desactivación no cambia snapshots históricos.

- [ ] **Step 4: Verificar y registrar.**

Ejecutar las suites SlaCalendarTest, SlaConfigurationTest y SupportConfigurationTest; inspeccionar casos DST y de diez años. Pint/lint del delta, revisión de tarea y commit aislado `feat: add business calendars and SLA policies`.

### Task 3: Tickets, asignación y snapshot inicial de SLA

**Files:**

- Create: `app/Services/TicketService.php`, `app/Services/SlaEngine.php`, `app/Policies/TicketPolicy.php`.
- Create: `app/Http/Requests/TicketRequest.php`, `app/Http/Controllers/Api/TicketController.php`, `app/Http/Resources/TicketResource.php`.
- Create: `tests/Feature/Api/TicketApiTest.php`.
- Modify: `routes/support.php`, `app/Providers/AppServiceProvider.php`.

**Interfaces:**

Consumes SupportConfigurationService::assertAssignment/isEligible, SlaCalendarService::normalize/addWorkingSeconds y SupportTestCase::supportFixture.
Produces TicketService::create(array $data, User $actor): array{ticket: Ticket, replayed: bool}; update(Ticket, array): Ticket; assign(Ticket, int $queueId, ?int $agentId): Ticket.
Produces SlaEngine::start(Ticket, CarbonImmutable $at): ?SlaExecution.
Produces TicketResource::toArray(Request): array, con sla y assignee_available.

- [ ] **Step 1: RED de creación idempotente y SLA por prioridad.**

Usar SupportTestCase + RefreshDatabase y un reloj fijo. La prueba falla si crea duplicados, no inicia el SLA o acepta un payload distinto para la misma clave:

```php
it('creates one ticket and freezes its initial SLA on replay', function (): void {
    $fixture = $this->supportFixture();
    $this->travelTo(CarbonImmutable::parse('2026-09-14 14:00:00', 'UTC'));
    $api = $this->withToken($fixture['token'])->withHeader('X-Tenant-ID', $fixture['tenant']->id);
    $payload = [
        'subject' => 'No puedo acceder', 'queue_id' => $fixture['queue']->id,
        'priority' => 'HIGH', 'idempotency_key' => 'ticket-high-1',
    ];
    $first = $api->postJson('/api/v1/tickets', $payload)->assertCreated();
    $id = $first->json('data.id');
    $api->postJson('/api/v1/tickets', $payload)->assertOk()->assertJsonPath('data.id', $id);
    $api->postJson('/api/v1/tickets', array_replace($payload, ['subject' => 'Otro caso']))->assertConflict();
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('sla_executions', 1);
    expect(CarbonImmutable::parse($first->json('data.sla.first_response_due_at'))->format('H:i:s'))->toBe('15:00:00');
    expect(CarbonImmutable::parse($first->json('data.sla.resolution_due_at'))->format('H:i:s'))->toBe('18:00:00');
    $this->travelBack();
});
```

Ejecutar `php artisan test --compact tests/Feature/Api/TicketApiTest.php` antes del código: RED 404. Añadir casos de creación sin política (sla=null), prioridades LOW/MEDIUM/HIGH/URGENT, vínculos ajenos 422, assignee fuera de cola 422 y asignación en alta sin tickets.assign 403.

- [ ] **Step 2: Normalización, replay y creación transaccional.**

TicketRequest valida límites de la especificación, permite solo campos del contrato y prohíbe los gestionados por servidor. En PATCH no admite status, queue_id, assigned_agent_id ni idempotency_key. La validación dinámica de actividad/relaciones se realiza en el servicio después de buscar un replay autorizado, para permitir repetir una creación aunque luego se haya desactivado la cola.

Normalizar el payload de alta con un orden fijo de campos y IDs enteros; omisiones opcionales equivalen a null y priority omitida a MEDIUM. Hashear esa representación sin idempotency_key ni datos del actor. No usar el estado actual del ticket para reconstruir el payload original.

```php
$hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$existing = Ticket::where('idempotency_key', $key)->first();
if ($existing !== null) {
    abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'The idempotency key belongs to a different payload.');
    return ['ticket' => $existing, 'replayed' => true];
}
```

Para una creación nueva, transaction: bloquear cola y agente, revalidar activos y membresía, crear ticket OPEN con autor del contexto, llamar SlaEngine::start y auditar. La restricción unique protege la carrera de claves; capturar solo UniqueConstraintViolationException fuera de la transacción fallida, volver a buscar en el tenant y comparar hash. No convertir otros errores SQL en replays.

start captura la política activa, regla por prioridad y calendario activo, calcula first_response_due_at y resolution_due_at con addWorkingSeconds y crea una ejecución RUNNING. resolution_anchor_at=created_at; resolution_remaining_seconds=resolution_seconds; banderas false. Si no hay política devuelve null. Si hay referencia inválida/inactiva o falta la regla, lanzar ValidationException y revertir también el ticket.

- [ ] **Step 3: Lectura, edición y asignación con permisos.**

Registrar TicketPolicy y rutas del spec. Toda mutación requiere tickets.view más su permiso específico. El endpoint de alta requiere tickets.assign cuando se proporciona assigned_agent_id, incluso si es null, como contrato explícito de ese campo.

index soporta q, status, priority, category_id, queue_id, assigned_agent_id, unassigned, created_from/created_to; validaciones de fechas e incompatibilidad entre unassigned=true y assigned_agent_id. Orden sort=created_at/updated_at/priority y direction=asc/desc; desempate por id. Implementar prioridad con CASE de valores fijos, nunca interpolar un campo arbitrario.

```php
$query->orderByRaw("CASE priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END ".$direction);
```

direction debe haber pasado Rule::in(['asc','desc']). PATCH solo sobre estados activos; RESOLVED/CLOSED devuelve 409. Asignar permite RESOLVED, no CLOSED; validar nueva cola/agente, conservar snapshot, auditar solo si cambian IDs. TicketResource expone campos de negocio y el SLA, nunca payload_hash, cuerpos del inbox ni credenciales. assignee_available es false sin agente o si dejó de ser elegible/pertenecer a la cola.

- [ ] **Step 4: GREEN, regresión y commit acotado.**

Comprobar que cambiar prioridad, cola, política o calendario no cambia el snapshot; la prioridad vigente puede diferir de snapshot.priority. Repetir alta tras cerrar/desactivar catálogos conserva ID sin nueva auditoría. Probar actor inactivo, lectura sin tickets.view, filtros, paginación, IDs inválidos y enlaces contacto/conversación inconsistentes.

Ejecutar TicketApiTest más las suites SupportConfiguration y SlaConfiguration. Pint/lint del delta; revisión de tarea y commit aislado `feat: add tickets with immutable SLA snapshots`.

### Task 4: Respuestas, estados y relojes de atención

**Files:**

- Create: `app/Services/TicketCommentService.php`, `app/Services/SlaEscalationService.php`.
- Create: `app/Http/Requests/TicketCommentRequest.php`, `TicketStatusRequest.php`, `app/Http/Resources/SlaExecutionResource.php`.
- Create: `tests/Feature/Api/TicketLifecycleSlaTest.php`.
- Modify: `app/Services/TicketService.php`, `SlaEngine.php`, `app/Http/Controllers/Api/TicketController.php`, `app/Http/Resources/TicketResource.php`, `routes/support.php`.

**Interfaces:**

Consumes TicketService::create/update/assign y SlaEngine::start.
Produces TicketService::changeStatus(Ticket, string $status, array $data): Ticket.
Produces TicketCommentService::create(Ticket, array $data, User $actor): array{comment: TicketComment, replayed: bool}.
Adds SlaEngine::recordFirstResponse(Ticket, CarbonImmutable): void; transition(Ticket, string $from, string $to, CarbonImmutable): void; evaluate(SlaExecution, CarbonImmutable): void; remaining(SlaExecution, CarbonImmutable): int.
Produces SlaEscalationService::record(SlaExecution, string $metric, CarbonImmutable $deadline, CarbonImmutable $detectedAt): SlaEscalation. Task 5 agrega despacho; record no envía notificaciones.
evaluate y transition se usan dentro de una transacción que ya bloqueó ticket y ejecución, en ese orden. remaining es puro y no escribe.

- [ ] **Step 1: RED de comentario público frente a nota interna.**

TicketLifecycleSlaTest usa SupportTestCase + RefreshDatabase. Crear ticket por la API de Task 3; congelar inicialmente 2026-09-14 14:00 UTC. Estos resultados deben fallar hasta incorporar respuestas:

```php
$api->postJson("/api/v1/tickets/{$ticketId}/comments", [
    'visibility' => 'INTERNAL', 'body' => 'Revisar permisos', 'idempotency_key' => 'internal-1',
])->assertCreated();
$this->assertDatabaseHas('tickets', ['id' => $ticketId, 'first_response_at' => null]);
$this->travelTo(CarbonImmutable::parse('2026-09-14 14:15:00', 'UTC'));
$public = ['visibility' => 'PUBLIC', 'body' => 'Se restableció el acceso', 'idempotency_key' => 'public-1'];
$api->postJson("/api/v1/tickets/{$ticketId}/comments", $public)->assertCreated();
$this->travelTo(CarbonImmutable::parse('2026-09-14 14:45:00', 'UTC'));
$api->postJson("/api/v1/tickets/{$ticketId}/comments", $public)->assertOk();
$this->assertDatabaseHas('tickets', ['id' => $ticketId, 'first_response_at' => '2026-09-14 14:15:00']);
$this->assertDatabaseCount('ticket_comments', 2);
$this->assertDatabaseCount('messages', 0);
```

Ejecutar la suite antes de agregar endpoints: RED 404. Incluir claves repetidas con distinto contenido 409, ausencia de tickets.comment_internal 403 y PUBLIC de usuario no registrado como agente 422.

- [ ] **Step 2: Guardar comentarios sin envío e iniciar medición de respuesta.**

Bloquear ticket, autorizar/validar elegibilidad del actor y resolver replay antes de comprobar el estado temporal. Crear comentario con author_user_id del actor. Solo el primer PUBLIC establece Ticket.first_response_at y llama recordFirstResponse, que actualiza la ejecución y evalúa puntualidad con ese instante. INTERNAL no toca la fecha. No invocar ConversationMessageService, transports, HTTP ni envíos a clientes.

Los comentarios se listan paginados por id y requieren tickets.view, incluidas las notas internas. CLOSED no recibe comentarios nuevos; RESOLVED solo admite INTERNAL. No exponer endpoints de edición/borrado de comentarios.

- [ ] **Step 3: RED de matriz de estados y presupuesto de resolución.**

Añadir pruebas con la matriz completa del spec (15 transiciones entre estados distintos permitidas; todos los demás pares rechazados). El mismo estado devuelve 200 sin otra auditoría. Resolver/WAITING_CUSTOMER antes de PUBLIC responde 409; resumen/motivo faltantes en una transición que los requiere responde 422.

Caso temporal de referencia: ticket 14:00, PUBLIC 14:15, WAITING_CUSTOMER 15:00 (10.800 segundos restantes), IN_PROGRESS 17:00 (vencimiento 20:00), RESOLVED 18:00 (7.200 restantes), reapertura al día siguiente 10:00 (vencimiento 12:00). Todos UTC en ALWAYS.

```php
$this->travelTo(CarbonImmutable::parse('2026-09-14 15:00:00', 'UTC'));
$api->postJson("/api/v1/tickets/{$ticketId}/status", ['status' => 'WAITING_CUSTOMER'])
    ->assertOk()->assertJsonPath('data.sla.resolution_due_at', null);
$this->assertDatabaseHas('sla_executions', ['ticket_id' => $ticketId, 'resolution_remaining_seconds' => 10800]);
$this->travelTo(CarbonImmutable::parse('2026-09-14 17:00:00', 'UTC'));
$resumed = $api->postJson("/api/v1/tickets/{$ticketId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
expect(CarbonImmutable::parse($resumed->json('data.sla.resolution_due_at'))->format('H:i:s'))->toBe('20:00:00');
```

Ejecutar RED antes de implementar changeStatus/transition.

- [ ] **Step 4: Implementar transiciones, medición y registro único de incumplimiento.**

Copiar la matriz del spec a SupportCatalog. changeStatus bloquea ticket/ejecución, calcula at después del lock y retorna temprano para no-op. Valida transición, PUBLIC previo y resumen/motivo; SlaEngine::transition evalúa el reloj anterior antes de alterarlo. Auditar old/new status y motivos/resumen sin duplicar cuerpos de comentarios.

La comparación temporal es estricta:

```php
$responseAt = $execution->first_response_at?->toImmutable() ?? $at;
if (! $execution->first_response_breached && $responseAt->gt($execution->first_response_due_at)) {
    $execution->first_response_breached = true;
    $execution->first_response_breached_at = $execution->first_response_due_at;
}
if ($execution->status === 'RUNNING' && ! $execution->resolution_breached
    && $execution->resolution_due_at !== null && $at->gt($execution->resolution_due_at)) {
    $execution->resolution_breached = true;
    $execution->resolution_breached_at = $execution->resolution_due_at;
}
```

Guardar nuevas marcas y llamar record solo para las que cambiaron false→true. record captura destinatarios elegibles (agente asignado y responsable de la cola, sin duplicar user_id), guarda breached_at y detected_at, y usa la unicidad tenant/execution/metric. recipients es una lista de `{user_id,status,attempts,last_error}`, con status inicial pending. Si no hay destinatarios: skipped_no_recipient; si hay: pending y next_attempt_at=detectedAt. No se envía nada en esta tarea.

remaining devuelve max(0, presupuesto_anclado - segundos_hábiles(anchor, at)) en RUNNING; en otros estados devuelve el presupuesto guardado. SlaExecutionResource utiliza remaining para mostrar presupuesto actualizado sin escribir durante GET.

Al pausar guardar remaining, paused_at y last_resolution_due_at, poner due_at=null y estado PAUSED. Espera interna o waiting_customer con pause_on_waiting_customer=false conserva RUNNING/vencimiento. Reanudar calcula el nuevo due_at desde remaining, guarda anchor=at y limpia pausa. Resolver desde RUNNING congela remaining, desde PAUSED conserva presupuesto sin consumir espera; estado RESOLVED, resolved_at=at. CLOSED conserva todo y cambia estado.

Reapertura guarda anchor=at, presupuesto restante, due_at recalculado y estado RUNNING; conserva primera respuesta, banderas y breached_at. Con cero segundos aún no incumplidos, due_at=at: no marcar antes de que pase ese instante. Limpiar resolved_at/resolution_summary del ticket y resolved_at de la ejecución; auditoría conserva resolución anterior.

- [ ] **Step 5: GREEN de límites y regresión.**

Agregar casos: primera respuesta exactamente 15:00 puntual, 15:00:01 incumplida; resolver exactamente 18:00 puntual; scheduler atrasado no marca tardía una respuesta/resolución puntual; pausa posterior al vencimiento no elimina marca; resolución desde espera, reapertura con cero, WAITING_INTERNAL sigue consumiendo, política sin pausa, SLA inexistente no impide ciclo del ticket, claves antiguas válidas después de CLOSED.

Ejecutar TicketLifecycleSlaTest, TicketApiTest y SlaCalendarTest. Revisar que repetir evaluate no duplica escalaciones/auditoría y que GET no escribe. Pint/lint y commit aislado `feat: implement ticket lifecycle and SLA clocks`.

### Task 5: Escalaciones recuperables, scheduler y notificaciones al equipo

**Files:**

- Create: `app/Contracts/SupportEscalationNotifier.php`, `app/Services/ExistingSupportEscalationNotifier.php`.
- Create: `app/Jobs/ProcessSupportSlaJob.php`, `app/Console/Commands/DispatchDueSupportSlaCommand.php`.
- Create: `app/Http/Controllers/Api/SlaEscalationController.php`, `tests/Feature/Api/SupportEscalationTest.php`.
- Modify: `app/Services/SlaEscalationService.php`, `app/Providers/AppServiceProvider.php`, `routes/support.php`, `routes/console.php`, `config/horizon.php`.
- Modify: la migración de Task 1 solo si aún falta reservation_token; no aplicar cambios a bases reales.

**Interfaces:**

Consumes SlaEngine::evaluate y SlaEscalationService::record de Task 4.
Adds SlaEscalationService::dispatch(int $escalationId): void.
Produces SupportEscalationNotifier::send(User $recipient, SlaEscalation $escalation): bool; false significa preferencias sin canales inmediatos. ExistingSupportEscalationNotifier implementa la interfaz usando NotificationPreferenceService/NotificationDispatcher existentes.
Produces ProcessSupportSlaJob::__construct(int $tenantId, int $executionId), handle(TenantContext, SlaEngine, SlaEscalationService): void.
Produces comando `support:dispatch-sla {--limit=1000}`, límites de 1 a 10000.

- [ ] **Step 1: RED de incumplimiento único y recuperación de despacho.**

SupportEscalationTest usa SupportTestCase + RefreshDatabase. Crear ticket a 14:00, sin respuesta; avanzar a 15:01 y ejecutar el job dos veces. El cambio que debe detectar es omitir una marca, duplicar su escalación o notificar a un destinatario de otra empresa.

```php
$this->travelTo(CarbonImmutable::parse('2026-09-14 15:01:00', 'UTC'));
$job = new ProcessSupportSlaJob((int) $fixture['tenant']->id, (int) $executionId);
app()->call([$job, 'handle']);
app()->call([$job, 'handle']);
$this->assertDatabaseCount('sla_escalations', 1);
$this->assertDatabaseHas('sla_executions', ['id' => $executionId, 'first_response_breached' => true]);
$this->assertDatabaseHas('sla_escalations', ['execution_id' => $executionId, 'metric' => 'FIRST_RESPONSE']);
```

Con reloj a 18:01 debe haber exactamente dos escalaciones (FIRST_RESPONSE y RESOLUTION), no tres. Si ambos destinatarios son el mismo usuario, solo un destinatario capturado. Mantener reales dominio y DB; simular solo el límite de notificación para pruebas de caída:

```php
$this->app->instance(SupportEscalationNotifier::class, new class implements SupportEscalationNotifier {
    public function send(User $recipient, SlaEscalation $escalation): bool
    {
        throw new RuntimeException('Simulated queue outage');
    }
});
```

Afirmar estados/attempts/next_attempt_at en DB, no únicamente llamadas al doble. Ejecutar RED antes de implementar job/dispatch.

- [ ] **Step 2: Reserva duradera y resultados por destinatario.**

dispatch reclama la escalación dentro del tenant en una transacción corta: status=pending, next_attempt_at<=now, reserva libre/expirada. Guardar reservation_token UUID, reserved_until=now+300 segundos e incrementar attempts. No mantener un lock SQL mientras se pone una notificación en cola.

```php
$claimed = DB::transaction(function () use ($escalationId, $at): ?SlaEscalation {
    $row = SlaEscalation::whereKey($escalationId)->lockForUpdate()->firstOrFail();
    if ($row->status !== 'pending' || $row->next_attempt_at?->gt($at)
        || $row->reserved_until?->gt($at)) {
        return null;
    }
    if ($row->attempts >= 3) {
        $row->update(['status' => 'failed', 'reserved_until' => null, 'reservation_token' => null, 'last_error' => 'dispatch_lease_expired']);
        return null;
    }
    $row->update([
        'attempts' => $row->attempts + 1,
        'reserved_until' => $at->addSeconds(300),
        'reservation_token' => (string) Str::uuid(),
    ]);
    return $row->fresh();
});
```

Cada escritura posterior verifica el mismo token bajo lock para no pisar un intento posterior. Antes de enviar, volver a comprobar tenant activo, usuario miembro activo, SupportAgent activo y permisos tickets.view/tickets.reply. La captura del destinatario no es autorización permanente. No reemplazar silenciosamente al destinatario capturado por otro usuario.

Por destinatario: pending, dispatched, failed, skipped_ineligible o skipped_preferences; preservar éxitos parciales al reintentar. La interfaz send retorna true solo si se puso en cola mediante NotificationDispatcher; false si sus preferencias no tienen canales inmediatos. Usar evento support.sla_breached, título fijo, cuerpo sin texto del ticket, contexto con ticket_id/execution_id/metric, sin URL no implementada.

Estado agregado: pending si hay destinatarios reintentables; failed si se agotaron y hay alguno fallido; dispatched si hay al menos uno despachado y ninguno pendiente/fallido; skipped_preferences si todos se omitieron por preferencias; skipped_no_recipient si ninguno era elegible. Un error mixto de preferencias/inelegibilidad sin envíos se clasifica skipped_no_recipient y conserva razones individuales.

Tras fallo de despacho: intento 1 next_attempt_at=now+60, intento 2=now+300, intento 3 failed. Persistir solo clase de error/código controlado, no exception message de un proveedor. Si un proceso muere, la reserva expira y el scheduler puede recuperarla; nunca hay cuarto intento, incluso si murió durante el tercero. Los estados dispatched indican puesta en cola, no entrega confirmada del proveedor.

- [ ] **Step 3: Job tenant-safe y selección de trabajo pendiente.**

Job ShouldQueue + ShouldBeUnique, cola support, tries=3, timeout=120, uniqueFor=600, clave tenant:execution. En handle:

```php
$previous = $context->id();
$context->set($this->tenantId);
try {
    if (! Tenant::whereKey($this->tenantId)->where('status', 'active')->exists()) {
        return;
    }
    DB::transaction(function () use ($engine): void {
        $ticketId = SlaExecution::whereKey($this->executionId)->value('ticket_id');
        if ($ticketId === null) {
            return;
        }
        Ticket::whereKey($ticketId)->lockForUpdate()->firstOrFail();
        $execution = SlaExecution::whereKey($this->executionId)->lockForUpdate()->firstOrFail();
        $engine->evaluate($execution, now()->toImmutable()->utc()->startOfSecond());
    });
    foreach (SlaEscalation::where('execution_id', $this->executionId)->where('status', 'pending')->pluck('id') as $id) {
        $escalations->dispatch((int) $id);
    }
} finally {
    $previous === null ? $context->clear() : $context->set($previous);
}
```

El comando recorre tenants activos con lazyById y restaura contexto. Seleccionar ejecuciones con primera respuesta pendiente/no incumplida y deadline<now; resolución RUNNING/no incumplida y deadline<now; o escalaciones pending con next_attempt_at<=now y reserva libre/expirada. Agrupar OR correctamente dentro del tenant. No volver a encolar indefinidamente una métrica ya cumplida/incumplida. Una ejecución RESOLVED/CLOSED puede tener despacho pendiente: no excluirlo.

Programar everyMinute()->withoutOverlapping() y supervisor-support en Horizon con connection=redis, queue=['support'], timeout=120 y tries=3. No añadir otra variable de conexión.

- [ ] **Step 4: Endpoint de escalaciones y GREEN.**

GET /support/sla-escalations requiere sla.view, filtros ticket_id/metric/status y paginación. Mostrar metric, fechas, resultado agregado y por destinatario sin reservation_token ni detalles secretos. No permite crear, forzar ni editar escalaciones por HTTP.

Pruebas adicionales: no repetir un destinatario despachado tras éxito parcial; permisos revocados mientras espera; tenant inactivo; ninguna preferencia inmediata; caída y recuperación de reserva; expiración del tercer intento sin cuarto; dos tenants; límite del comando; primera respuesta justo a tiempo con worker atrasado; resolución previa al job; restauración del contexto incluso al lanzar excepción.

En al menos un caso usar el adaptador real con QUEUE_CONNECTION=sync, MAIL_MAILER=array y BROADCAST_CONNECTION=null; verificar notificación en DB perteneciente al tenant y que no contiene body del ticket. Para el scheduler usar Queue::fake solo de ProcessSupportSlaJob y comprobar IDs concretos. Ejecutar SupportEscalationTest y TicketLifecycleSlaTest. Pint/lint, revisión y commit aislado `feat: dispatch recoverable SLA escalations`.

### Task 6: Seguridad transversal y flujo real Postman/OpenAPI

**Files:**

- Create: `tests/Feature/Api/SupportSecurityTest.php`, `tests/Feature/Api/SupportPostmanWorkflowTest.php`, `docs/16-API-7-1-TICKETS-SLA.md`.
- Modify: `docs/openapi.yaml`, `docs/postman/Vantex CRM API.postman_collection.json`, `docs/postman/README.md`, `README.md`, `docs/09-GAP-ANALYSIS.md`.
- Modify solo si una prueba nueva revela un incumplimiento: archivos de soporte creados/integrados en Tasks 1–5; registrar el fallo RED y la corrección.

**Interfaces:**

Consumes todos los endpoints de la sección 7 del spec y contratos de Tasks 1–5.
Produces carpeta Postman `12 - API-7.1 tickets y SLA`, después de API-6; renombrar carpeta de seguridad actual a 13 conservando sus solicitudes.
Produces OpenAPI con las 36 operaciones de este bloque (26 de catálogos/membresías, 4 de tickets raíz/detalle, 2 acciones, 2 comentarios, 1 SLA y 1 lista de escalaciones); contrastarlas individualmente con route:list.
Produces guía operativa de configuración, comentarios sin envío, estados, snapshot, pausas, scheduler, worker, idempotencia y limitaciones del bloque.

- [ ] **Step 1: RED de ataques a las fronteras HTTP.**

SupportSecurityTest usa dos tenants y usuarios con permisos diferentes. Para cada familia, usar datos existentes válidos y cambiar solo tenant o permiso, evitando pruebas que fallen accidentalmente por un campo obligatorio faltante.

```php
it('does not expose another tenant ticket through nested routes', function (): void {
    $first = $this->supportFixture();
    $firstApi = $this->withToken($first['token'])->withHeader('X-Tenant-ID', $first['tenant']->id);
    $ticketId = $firstApi->postJson('/api/v1/tickets', [
        'subject' => 'Privado', 'queue_id' => $first['queue']->id, 'idempotency_key' => 'private-1',
    ])->assertCreated()->json('data.id');
    $second = $this->supportFixture();
    Sanctum::actingAs($second['user']);
    $api = $this->withHeader('X-Tenant-ID', $second['tenant']->id);
    $api->getJson("/api/v1/tickets/{$ticketId}")->assertNotFound();
    $api->getJson("/api/v1/tickets/{$ticketId}/comments")->assertNotFound();
    $api->getJson("/api/v1/tickets/{$ticketId}/sla")->assertNotFound();
});
```

Añadir casos concretos: tickets.create sin tickets.view no crea; tickets.reply no autoriza INTERNAL; support.manage no concede tickets.assign; sla.manage no concede tickets.change_status; ticket/comment con tenant_id/author/first_response_at/status/snapshot del cliente rechazado o ignorado sin establecerlo; idempotencia ajena no devuelve registro; texto de búsqueda SQL no amplia resultados; notas no entran a logs/notificaciones; modelos/catálogos cruzados no se aceptan.

Si estas pruebas ya pasan, registrarlo y no cambiar producción artificialmente. Cada defecto real tendrá prueba RED propia antes del arreglo.

- [ ] **Step 2: Definir smoke Postman y una prueba que lo ejecute contra Laravel.**

Crear primero SupportPostmanWorkflowTest usando el patrón de MarketingPostmanWorkflowTest, pero con selección explícita de la carpeta API-7.1, captura de IDs y validación de que hay solicitudes. Los fallos esperados iniciales son carpeta/requests ausentes o campos de respuesta incompatibles.

El flujo usa un tenant de prueba nuevo, el usuario autenticado y datos con run_id. Operaciones en orden:

1. Buscar agente de user_id actual; reutilizarlo si existe o registrar uno, capturando support_agent_id.
2. Crear categoría, calendario ALWAYS, política con cuatro reglas 60/240 y cola; capturar IDs.
3. Asociar agente a cola y configurar su escalation_agent_id.
4. Listar catálogos; crear ticket MEDIUM con asignación y clave api7-ticket-{{run_id}}.
5. Repetir la creación (200), listar/ver ticket y cambiar prioridad a HIGH sin alterar snapshot.priority=MEDIUM.
6. Crear INTERNAL, crear PUBLIC, repetir PUBLIC (200), consultar comentarios y SLA.
7. IN_PROGRESS → WAITING_INTERNAL → WAITING_CUSTOMER → IN_PROGRESS.
8. RESOLVED con resumen → IN_PROGRESS con motivo → RESOLVED con nuevo resumen → CLOSED.
9. Intentar CLOSED → IN_PROGRESS (409); listar escalaciones filtradas por ticket_id y consultar ticket cerrado.

El lookup de agente requiere filtro user_id validado en GET /support/agents; integrarlo en Task 1. La colección reutiliza agente mediante scripts de flujo, no crea una segunda identidad. No desactivar ni borrar el agente reutilizado al terminar. Verificar la rama de agente existente y la de alta con fixtures independientes.

Scripts de captura siguen la forma usada por la colección existente:

```javascript
pm.test('Estado esperado', function () { pm.response.to.have.status(201); });
const data = pm.response.json().data;
pm.collectionVariables.set('support_ticket_id', String(data.id));
```

La prueba envía cada request real a Laravel con sus variables resueltas, comprueba el status esperado, captura IDs y termina verificando tickets.status=CLOSED, dos comentarios y cero messages. Para los scripts de control condicional, ejecutar su JavaScript en un contexto aislado con respuestas literales vacía/no vacía y comprobar la ruta siguiente; no confiar solo en regex que detecten presencia de texto. Compilar todos los scripts con Node antes de entregar.

```php
$this->assertDatabaseHas('tickets', ['id' => $variables['support_ticket_id'], 'status' => 'CLOSED']);
$this->assertDatabaseHas('sla_executions', ['ticket_id' => $variables['support_ticket_id'], 'status' => 'CLOSED']);
$this->assertDatabaseCount('ticket_comments', 2);
$this->assertDatabaseCount('messages', 0);
Http::assertNothingSent();
```

Mantener reloj fijo durante el smoke y simular solo HTTP/notificaciones externas. No generar expiraciones por esperas reales, ni exponer un endpoint para cambiar el reloj.

- [ ] **Step 3: OpenAPI y documentación operativa.**

Documentar las rutas reales, permisos, Bearer/X-Tenant-ID, payloads, enums, límites, replay 200 frente a alta 201 y conflictos 409. calendar_id=null equivale a ALWAYS/UTC. No describir PUBLIC como envío real ni sla.dispatched como entrega al cliente.

Definir schemas propios de Agent, Category, Queue, Calendar, Policy/Rule, Ticket, Comment, SlaExecution, SlaEscalation y sus inputs; conservar referencias existentes y claves únicas de YAML. Incluir ejemplos de horario BUSINESS y cuatro reglas. Declarar una propiedad adicional del servidor como readOnly, nunca campo del request.

En la guía operativa mostrar crear agente → cola → miembros → responsable de escalaciones, snapshots no retroactivos, cierre definitivo, pausas/reapertura y los comandos:

```powershell
php artisan migrate
php artisan schedule:work
php artisan queue:work redis --queue=support,notifications --tries=3 --timeout=120
```

Estos comandos se documentan para el despliegue del usuario; no se ejecutan contra su base real durante el desarrollo. Para producción con Horizon explicar el supervisor support. Marcar API-7.1 como implementado solo al terminar las pruebas; el resto de API-7 permanece pendiente.

- [ ] **Step 4: Verificación final con evidencia nueva.**

Ejecutar bajo el entorno SQLite aislado: `php artisan test --compact`. Ejecutar Pint --test y php -l en el listado exacto de archivos PHP propios. `git diff --check` para cambios; comprobar también archivos nuevos.

Migración reversible solo en conexión desechable: bootstrap con entorno testing/DATABASE_URL sqlite:///:memory:, verificar DB driver=sqlite y database=:memory: antes de migrar; dentro del mismo proceso ejecutar migrate, rollback --step=1 y migrate. Nunca lanzar rollback con la configuración real.

Validar con parser YAML/JSON: cero claves duplicadas, todas las refs OpenAPI resuelven, métodos/path coinciden con route:list --json y no se perdió una ruta previa. La colección debe parsear, sus cuerpos JSON resolver variables y sus scripts compilar/ejecutar. El smoke prueba API y documentación juntas.

Si PostgreSQL desechable no está disponible, reportar que carreras SQL/RLS no se verificaron en PostgreSQL; no sustituirlo por la base real. Hacer revisión final del delta propio, corregir hallazgos importantes con pruebas y registrar evidencia. Commit aislado `test: verify support API and Postman workflow`; sin push ni merge automático.

## Cobertura y cierre

| Requisito del spec | Tareas responsables |
| --- | --- |
| Alcance, arquitectura y preservación de datos | Todas; límites finales en 6 |
| Agentes, categorías, colas, permisos y persistencia | 1, 6 |
| Calendarios y políticas | 2, 6 |
| Tickets, vínculos, asignación y snapshot | 3, 6 |
| Comentarios, estados, pausas y reapertura | 4, 6 |
| Escalaciones, jobs, scheduler y notificaciones | 4, 5, 6 |
| Contratos HTTP, idempotencia y aislamiento | 1–6 |
| OpenAPI, Postman, regresión y despliegue | 6 |

Antes de entregar: leer el diff real, anotar conteos exactos de pruebas/assertions y señalar limitaciones comprobadas, no supuestas. Usar verification-before-completion y requesting-code-review; al decidir integración usar finishing-a-development-branch. Mantener los cambios sin merge/push si el usuario no autorizó esas acciones.
