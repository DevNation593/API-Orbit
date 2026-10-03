# 10 — API-1: Customer 360 y productividad base

Fecha de cierre técnico: 2026-09-01

## Alcance

Esta fase agrega Customer 360, timeline unificado, detección y fusión de duplicados, búsqueda global, vistas guardadas y etiquetas sin cambiar la arquitectura del monolito Laravel. Todas las tablas nuevas usan `tenant_id`, IDs numéricos, scopes existentes y RLS opcional en PostgreSQL.

La migración es `2026_09_01_000000_create_customer_360_foundations.php`. Crea `saved_views`, `saved_view_filters`, `tags`, `tag_assignments` y `record_merges`; también añade columnas normalizadas e índices por tenant a contactos y organizaciones.

## Endpoints

| Método | Ruta | Propósito |
|---|---|---|
| `GET` | `/api/v1/contacts/{id}/overview` | Resumen 360 condicionado por permisos. |
| `GET` | `/api/v1/contacts/{id}/timeline` | Actividad y auditoría normalizadas y paginadas. |
| `POST` | `/api/v1/contacts/duplicate-check` | Posibles contactos duplicados con score. |
| `POST` | `/api/v1/contacts/{id}/merge` | Absorbe otro contacto en el registro de la ruta. |
| `POST` | `/api/v1/organizations/duplicate-check` | Posibles organizaciones duplicadas. |
| `POST` | `/api/v1/organizations/{id}/merge` | Absorbe otra organización. |
| `POST` | `/api/v1/companies/duplicate-check` | Alias de compatibilidad para organizaciones. |
| `POST` | `/api/v1/companies/{id}/merge` | Alias de compatibilidad para organizaciones. |
| `GET` | `/api/v1/search` | Búsqueda multi-entidad limitada por RBAC. |
| CRUD | `/api/v1/saved-views` | Vistas privadas, por rol o por tenant. |
| CRUD | `/api/v1/tags` | Catálogo tenant-scoped de etiquetas. |
| `GET/POST` | `/api/v1/tags/{id}/assignments` | Consulta o asigna una etiqueta. |
| `DELETE` | `/api/v1/tags/{id}/assignments/{assignmentId}` | Retira una asignación. |

Todas las rutas autenticadas aceptan `Authorization: Bearer ...` y `X-Tenant-ID`. El tenant del payload nunca sustituye al tenant resuelto desde la membresía.

## Customer 360 y timeline

El overview reúne el contacto y, cuando el actor posee el permiso correspondiente, empresas, leads, oportunidades, actividades, tareas, documentos, relaciones, conversaciones —incluidos conteos separados de email y WhatsApp— y eventos de auditoría. `recent_limit` acepta de 1 a 10 elementos por módulo. Los módulos de fases posteriores se informan como no disponibles en vez de simular datos.

El timeline combina dos fuentes:

- `activity`: eventos de dominio y actividades ordinarias;
- `audit`: mutaciones auditadas, presentadas solo con nombres de campos modificados, sin exponer valores sensibles.

Admite `source`, `event`, `from`, `to`, `page` y `per_page` (máximo 100). Después de una fusión también consulta el linaje del contacto absorbido. Los observers generan, entre otros, `contact.created`, `contact.updated`, `contact.merged`, `company.created`, `lead.assigned`, `opportunity.stage_changed` y `task.completed`.

## Detección de duplicados

La detección es determinista e indexable. Normaliza correo, teléfono, identificadores, nombre y dominio web; no ejecuta comparación difusa ni un barrido libre sobre todo el tenant. Se evalúan como máximo 250 candidatos indexados y se devuelven hasta 50.

Pesos para contactos:

| Campo | Puntos |
|---|---:|
| identificación o tax ID | 80 |
| email | 60 |
| teléfono | 45 |
| nombre completo | 30 |
| empresa vinculada | 20 |

Pesos para organizaciones:

| Campo | Puntos |
|---|---:|
| identificación o tax ID | 80 |
| dominio web | 55 |
| email | 50 |
| nombre | 40 |
| teléfono | 35 |

El score se limita a 100. La confianza es `high` desde 70, `medium` desde 40 y `low` por debajo de 40. `minimum_score` permite ajustar el umbral (25 por defecto) y `exclude_id` evita comparar el registro que se está editando. Todos los IDs se validan dentro del tenant activo.

## Semántica de fusión

La fusión se ejecuta dentro de una transacción con bloqueo pesimista y tres intentos ante conflictos. El ID de la ruta es el destino y `duplicate_id` es el registro absorbido.

- El destino conserva sus valores no vacíos; completa vacíos desde el origen.
- `field_overrides` permite elegir valores validados de forma explícita.
- Los campos personalizados se combinan con precedencia `origen < destino < override`.
- Se reasignan pivotes empresa/contacto, oportunidades, leads, actividades, tareas, documentos, tags y relaciones genéricas.
- Las colisiones idempotentes se consolidan y no producen duplicados.
- El origen se elimina mediante soft delete.
- `record_merges` conserva linaje y conteos de relaciones movidas.
- Se registran auditoría y el evento de timeline correspondiente.

La respuesta solo carga relaciones que el actor puede consultar. En particular, una fusión preserva tags aunque el actor no tenga `tags.view`, pero no los expone en el JSON.

## Búsqueda global

`GET /search?q=...&types[]=contacts` busca contactos, empresas, leads, oportunidades, documentos y objetos personalizados. Requiere `search.view` y además filtra cada tipo con su permiso `*.view`; no devuelve resultados de un recurso no autorizado ni de otro tenant.

La implementación actual usa búsqueda SQL escapada, ranking simple y paginación global. Es adecuada para el volumen inicial y evita agregar infraestructura sin evidencia. Cuando mediciones reales muestren degradación, el siguiente paso es un ADR para índices `pg_trgm`/full-text de PostgreSQL; OpenSearch se evaluará solo si se necesitan ranking avanzado, tolerancia lingüística o escalado independiente.

## Vistas guardadas

Una vista guarda entidad, columnas, orden y hasta 50 filtros declarativos. Los nombres de campo se validan y los operadores provienen de la lista segura de configuración; no se almacena SQL ejecutable.

Visibilidades:

- `private`: solo propietario;
- `team`: usuarios que comparten el rol indicado dentro del tenant;
- `tenant`: cualquier usuario del tenant que también pueda ver el recurso.

En esta arquitectura no existe una entidad separada `Team`; por ello `team` corresponde deliberadamente al rol tenant-scoped. Solo el propietario o un actor con `settings.manage` puede modificar una vista compartida.

## Tags

Los nombres se recortan y normalizan para impedir duplicados por mayúsculas o acentos dentro del tenant. El color, cuando existe, usa `#RRGGBB`. Las asignaciones son polimórficas, tenant-safe e idempotentes para contactos, organizaciones, leads, oportunidades, tareas, actividades, documentos y registros personalizados.

Consultar tags requiere `tags.view`; crear, editar, eliminar, asignar o retirar requiere `tags.manage` y permiso de acceso al tipo de recurso involucrado.

## Permisos agregados

- `search.view`
- `saved_views.view`
- `saved_views.manage`
- `tags.view`
- `tags.manage`
- `duplicates.manage`

La migración los concede a roles de sistema y roles administrativos existentes. Los despliegues con roles personalizados deben asignarlos de forma explícita según la responsabilidad de cada rol.

## Verificación

```bash
php artisan migrate
php artisan test tests/Feature/Api/Customer360ProductivityTest.php
php artisan test
```

La colección `docs/postman/Vantex CRM API.postman_collection.json` incluye un flujo encadenado en **06 - Customer 360 y productividad**. OpenAPI está en `docs/openapi.yaml` y se sirve desde `/api/v1/openapi.yaml`.

Resultado local de cierre: 7 pruebas focalizadas con 88 aserciones y 31 pruebas completas con 195 aserciones; 221 archivos PHP propios pasaron lint. La migración y su rollback individual se comprobaron sobre una SQLite temporal.

Antes de desplegar a PostgreSQL se debe ejecutar backup, migración, smoke test y revisión de métricas. El rollback de esta migración elimina datos de vistas, tags y linaje de fusiones, por lo que solo debe usarse dentro de la ventana de reversión y después de respaldar esos datos.
