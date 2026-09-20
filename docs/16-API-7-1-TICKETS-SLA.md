# API-7.1 — Tickets, colas y SLA

Este bloque implementa la operación interna de soporte de API-7: agentes, categorías, colas, tickets, comentarios, calendarios hábiles, políticas SLA y escalaciones recuperables. Mantiene IDs numéricos, aislamiento por `tenant_id`, Bearer Sanctum y respuestas `{data, meta}`.

API-7.1 no incorpora base de conocimiento; ese bloque se entrega separadamente como API-7.2. Portal/autenticación de clientes, Customer Success, encuestas, watchers, fusión de tickets, adjuntos propios y sincronización automática con email o WhatsApp siguen fuera de ambos bloques.

## Puesta en marcha

La migración es `2026_09_09_000000_create_support_foundations.php`. Antes de aplicarla, revisa el destino de `DATABASE_URL` y respalda la base correspondiente.

```powershell
php artisan migrate
php artisan schedule:work
php artisan queue:work redis --queue=support,notifications --tries=3 --timeout=120
```

En producción Linux puede ejecutarse Horizon en lugar del worker manual. `config/horizon.php` incluye el supervisor `support`, con la cola `support`, tres intentos y timeout de 120 segundos. El scheduler ejecuta `support:dispatch-sla` cada minuto y evita ejecuciones solapadas. No hacen falta variables de conexión adicionales: PostgreSQL usa `DATABASE_URL` y Redis usa `REDIS_URL`.

Si el scheduler o el worker se detienen, la detección/notificación puede demorarse, pero el instante de incumplimiento sigue siendo el vencimiento real guardado.

## Autenticación y permisos

Todas las rutas del bloque son internas bajo `/api/v1` y requieren:

```text
Authorization: Bearer <sanctum-token>
X-Tenant-ID: <tenant-numérico>
```

Una ruta de otro tenant responde 404 y una referencia de payload perteneciente a otro tenant responde 422, sin revelar el registro externo.

| Permiso | Alcance |
|---|---|
| `support.view` | Leer agentes, categorías y colas. |
| `support.manage` | Crear, editar, asociar y retirar configuración de soporte. |
| `tickets.view` | Leer tickets, comentarios internos/públicos y su SLA. |
| `tickets.create` | Crear tickets; también exige `tickets.view`. |
| `tickets.update` | Editar campos operativos; también exige `tickets.view`. |
| `tickets.assign` | Cambiar cola/agente; también exige `tickets.view`. |
| `tickets.reply` | Registrar una respuesta `PUBLIC`; también exige `tickets.view`. |
| `tickets.comment_internal` | Registrar una nota `INTERNAL`; también exige `tickets.view`. |
| `tickets.change_status` | Ejecutar transiciones; también exige `tickets.view`. |
| `sla.view` | Leer calendarios, políticas y escalaciones. |
| `sla.manage` | Crear, editar o retirar calendarios y políticas. |

Un agente de soporte es un `User` existente, miembro activo del tenant, con `tickets.view` y `tickets.reply`. `support.manage` por sí solo no convierte a un usuario en agente ni concede acciones sobre tickets.

## Orden de configuración

El orden seguro es:

1. Registrar al usuario como agente en `POST /support/agents`.
2. Crear categoría y, si corresponde, calendario y política SLA.
3. Crear la cola con su `sla_policy_id`.
4. Reemplazar miembros con `PUT /support/queues/{queue}/agents`.
5. Configurar `escalation_agent_id` mediante `PATCH /support/queues/{queue}` cuando ese agente ya pertenece a la cola.

Rutas de configuración:

```text
GET|POST            /api/v1/support/agents
GET|PATCH|DELETE    /api/v1/support/agents/{agent}
GET|POST            /api/v1/support/categories
GET|PATCH|DELETE    /api/v1/support/categories/{category}
GET|POST            /api/v1/support/queues
GET|PATCH|DELETE    /api/v1/support/queues/{queue}
PUT                 /api/v1/support/queues/{queue}/agents
GET|POST            /api/v1/support/sla-calendars
GET|PATCH|DELETE    /api/v1/support/sla-calendars/{calendar}
GET|POST            /api/v1/support/sla-policies
GET|PATCH|DELETE    /api/v1/support/sla-policies/{policy}
```

No se puede desactivar una cola ni retirar/desactivar un agente mientras conserve tickets que no estén `CLOSED`. Tampoco se puede retirar un agente configurado como responsable de escalación. Los catálogos sin referencias se pueden borrar; si conservan historial, el API devuelve 409 y deben desactivarse.

### Calendarios y políticas

`ALWAYS` consume tiempo continuo. `BUSINESS` usa una zona IANA, días ISO `1`–`7`, intervalos semiabiertos y feriados locales. Cada día admite hasta cuatro intervalos sin solapamiento; `24:00` solo es válido como fin. Un calendario `BUSINESS` necesita al menos un intervalo semanal.

```json
{
  "name": "Horario Ecuador",
  "mode": "BUSINESS",
  "timezone": "America/Guayaquil",
  "weekly_schedule": {
    "1": [{"start": "09:00", "end": "17:00"}],
    "2": [{"start": "09:00", "end": "17:00"}],
    "3": [{"start": "09:00", "end": "17:00"}],
    "4": [{"start": "09:00", "end": "17:00"}],
    "5": [{"start": "09:00", "end": "17:00"}],
    "6": [],
    "7": []
  },
  "holidays": ["2026-12-25"]
}
```

Una política contiene exactamente una regla por prioridad. Los objetivos son minutos enteros entre 1 y 525.600, y primera respuesta no puede superar resolución. `calendar_id: null` equivale a `ALWAYS` en UTC.

```json
{
  "name": "Atención estándar",
  "calendar_id": null,
  "pause_on_waiting_customer": true,
  "rules": [
    {"priority": "LOW", "first_response_minutes": 60, "resolution_minutes": 240},
    {"priority": "MEDIUM", "first_response_minutes": 60, "resolution_minutes": 240},
    {"priority": "HIGH", "first_response_minutes": 30, "resolution_minutes": 120},
    {"priority": "URGENT", "first_response_minutes": 15, "resolution_minutes": 60}
  ]
}
```

## Tickets y comentarios

```text
GET|POST    /api/v1/tickets
GET|PATCH   /api/v1/tickets/{ticket}
POST        /api/v1/tickets/{ticket}/assign
POST        /api/v1/tickets/{ticket}/status
GET|POST    /api/v1/tickets/{ticket}/comments
GET         /api/v1/tickets/{ticket}/sla
```

El alta requiere `subject`, `queue_id` e `idempotency_key`; `priority` usa `MEDIUM` por defecto. Los vínculos `category_id`, `contact_id`, `organization_id` y `conversation_id` son opcionales y tenant-safe. Enviar `assigned_agent_id`, incluso como `null`, requiere `tickets.assign`.

```json
{
  "subject": "No puedo ingresar",
  "description": "El acceso falla desde esta mañana.",
  "priority": "HIGH",
  "queue_id": 3,
  "assigned_agent_id": 8,
  "idempotency_key": "portal-import-2026-00017"
}
```

El primer POST válido responde 201. Repetir la misma clave con el mismo payload normalizado responde 200 y devuelve el recurso original sin repetir auditoría ni efectos. Reutilizarla con otro payload responde 409. La regla también se aplica a comentarios y está delimitada por ticket.

`PATCH /tickets/{ticket}` solo acepta `subject`, `description`, `priority`, `category_id` y vínculos CRM. Estado y asignación tienen acciones dedicadas. Cambiar prioridad o cola no recalcula el SLA: el campo `sla.snapshot.priority` conserva la prioridad elegida al crear el ticket.

Las listas admiten `per_page` de 1 a 100. Tickets filtra por `q`, `status`, `priority`, categoría, cola, agente, `unassigned` y fechas; ordena por `created_at`, `updated_at` o prioridad. Los valores de prioridad se ordenan `URGENT`, `HIGH`, `MEDIUM`, `LOW`. El API no interpola nombres de columna ni expresiones SQL recibidas del cliente.

### Comentarios

- `INTERNAL` es una nota visible al equipo con `tickets.view`; requiere `tickets.comment_internal`.
- `PUBLIC` registra una respuesta que el agente ya comunicó al cliente; requiere un agente elegible y `tickets.reply`.
- Registrar `PUBLIC` no envía email ni WhatsApp, no crea un `Message` y no habilita acceso de portal.
- El primer `PUBLIC` fija `first_response_at`; las notas internas y respuestas posteriores no lo cambian.
- `RESOLVED` acepta nuevas notas internas, pero no respuestas públicas. `CLOSED` solo admite lectura y replays idempotentes.

Los cuerpos de comentarios no se copian a auditorías ni a notificaciones de SLA.

## Estados y relojes SLA

| Desde | Destinos permitidos |
|---|---|
| `OPEN` | `IN_PROGRESS`, `WAITING_CUSTOMER`, `WAITING_INTERNAL`, `RESOLVED` |
| `IN_PROGRESS` | `WAITING_CUSTOMER`, `WAITING_INTERNAL`, `RESOLVED` |
| `WAITING_CUSTOMER` | `IN_PROGRESS`, `WAITING_INTERNAL`, `RESOLVED` |
| `WAITING_INTERNAL` | `IN_PROGRESS`, `WAITING_CUSTOMER`, `RESOLVED` |
| `RESOLVED` | `CLOSED`, `IN_PROGRESS` |
| `CLOSED` | Ninguno |

`WAITING_CUSTOMER` y `RESOLVED` requieren una respuesta pública previa. Resolver exige `resolution_summary`; reabrir desde `RESOLVED` exige `reason`. Repetir el estado vigente es un no-op 200. Una transición distinta no permitida responde 409 y `CLOSED` es definitivo.

Al crear el ticket, la ejecución captura política, regla y calendario. Ese snapshot no cambia aunque después se editen o desactiven la configuración, la prioridad o la cola. Sin política, `sla` es `null` y el ticket conserva el mismo ciclo operativo.

La primera respuesta nunca se pausa. `WAITING_INTERNAL` sigue consumiendo resolución. `WAITING_CUSTOMER` pausa resolución únicamente si `pause_on_waiting_customer` quedó activo en el snapshot. Reanudar usa el presupuesto restante; resolver lo congela; reabrir conserva respuesta, snapshot e incumplimientos y vuelve a calcular el vencimiento desde el presupuesto guardado. Responder o resolver exactamente en el límite sigue siendo puntual.

## Escalaciones recuperables

```text
GET /api/v1/support/sla-escalations
```

La lista es de solo lectura, requiere `sla.view` y filtra por `ticket_id`, `metric` (`FIRST_RESPONSE` o `RESOLUTION`) y estado. Cada métrica genera como máximo una escalación por ejecución.

Los destinatarios capturados son el agente asignado y el responsable de la cola, sin duplicados. Esa lista queda fijada al registrar el incumplimiento y no se reemplaza si después cambian la cola o la asignación. Antes de notificar se vuelve a exigir que cada destinatario siga siendo agente activo, miembro activo del tenant y tenga `tickets.view` y `tickets.reply`; esos permisos son tenant-wide, mientras la pertenencia a la cola se comprueba al capturar el destinatario. Los resultados individuales distinguen `pending`, `dispatched`, `failed`, `skipped_ineligible` y `skipped_preferences`.

`dispatched` significa que la notificación interna fue puesta en cola; no confirma entrega por el proveedor y nunca significa que se contactó al cliente. El despacho usa una reserva persistente de cinco minutos, hasta tres intentos y esperas de 60 y 300 segundos. Una caída puede recuperarse al expirar la reserva; los destinatarios ya despachados no se repiten en un reintento normal.

## Postman y verificación

La carpeta **12 - API-7.1 tickets y SLA** ejecuta un flujo autocontenido después de autenticación. Reutiliza el agente actual o lo registra, crea configuración, congela un SLA `MEDIUM`, cambia la prioridad a `HIGH`, registra una nota y una respuesta, pausa/reanuda, resuelve, reabre, vuelve a resolver, cierra y confirma que `CLOSED` no puede reabrirse. No espera vencimientos reales ni realiza tráfico externo.

```powershell
php artisan test --compact tests/Feature/Api/SupportSecurityTest.php
php artisan test --compact tests/Feature/Api/SupportPostmanWorkflowTest.php
php artisan test --compact
```

OpenAPI documenta las 36 operaciones de API-7.1 y sus schemas de entrada/salida. Las pruebas locales usan SQLite en memoria, reloj fijo y proveedores simulados. Las carreras, índices y RLS deben verificarse además en PostgreSQL desechable mediante CI antes de producción; nunca se debe usar la base real para rollback o pruebas destructivas.
