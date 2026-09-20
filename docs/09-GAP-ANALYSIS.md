# 09 — GAP Analysis de la API de Vantex CRM

Fecha del análisis: 2026-09-01

## 1. Alcance y fuentes revisadas

Este documento compara la implementación real de `API Orbit` con el prompt maestro de ampliación y con los documentos funcionales y técnicos 00–08 ubicados en `Documentos/`.

Se inspeccionaron, como mínimo:

- rutas HTTP, controladores, requests, modelos, policies, servicios, eventos, listeners y jobs;
- migraciones, índices, JSON/JSONB, aislamiento por `tenant_id` y RLS opcional;
- autenticación Sanctum, resolución del tenant, catálogo RBAC y auditoría;
- integraciones, webhooks entrantes/salientes, importaciones, exportaciones y archivos;
- pruebas unitarias y de integración, CI, Docker, OpenAPI y colección Postman;
- documentación 00–08 y el prompt maestro de fases API-1 a API-10.

Estados utilizados:

- `DONE`: el flujo existe de extremo a extremo y tiene una base verificable.
- `PARTIAL`: existe una base útil, pero faltan capacidades requeridas por el prompt maestro.
- `MISSING`: no existe implementación funcional.
- `BLOCKED`: depende de una decisión, contrato o credencial externa que no está disponible en el repositorio.

## 2. Decisiones arquitectónicas que se preservan

- Monolito modular Laravel con API REST versionada en `/api/v1`.
- PostgreSQL como base productiva y SQLite en memoria para la suite rápida.
- Redis mediante una única variable `REDIS_URL`.
- PostgreSQL mediante una única variable `DATABASE_URL`.
- Multi-tenancy por base compartida y `tenant_id`, con scope Eloquent obligatorio y RLS opcional.
- IDs numéricos como identificadores internos. No se reintroducen slugs ni identificadores de negocio eliminados.
- Sanctum para sesiones API, policies y permisos RBAC por tenant.
- Respuestas uniformes `{data, meta}` y errores `{message, errors}`.
- Auditoría con redacción de secretos y `X-Request-ID`.
- Operaciones asíncronas mediante jobs/colas y eventos de dominio existentes.
- Dinero almacenado como `decimal`; no se usa `float` para persistencia.

La ampliación continuará dentro de la estructura existente (`app/Models`, `app/Services`, `app/Http/...`). Una reorganización completa por módulos cambiaría la arquitectura actual y requeriría un ADR separado; no es necesaria para agregar las capacidades solicitadas.

## 3. Línea base existente

| Capacidad base | Estado | Evidencia / observación |
|---|---|---|
| Tenants y contexto activo | DONE | Middleware `ResolveTenant`, `TenantContext` y scopes por `tenant_id`. |
| Aislamiento multi-tenant | DONE | Scope Eloquent, validación de relaciones cruzadas y RLS opcional. |
| Registro, login, logout y contraseñas | DONE | Sanctum, recuperación y cambio de contraseña. |
| Roles y permisos | DONE | Catálogo RBAC, policies, roles por tenant y cambio de rol. |
| Invitaciones de equipo | DONE | Invitación, consulta, aceptación, revocación y notificación. |
| Contactos y organizaciones | DONE | CRUD, propietarios, vínculos y campos personalizados. |
| Leads y conversión | DONE | CRUD y conversión transaccional a contacto/oportunidad. |
| Pipelines, etapas y oportunidades | DONE | CRUD y validación estricta etapa-pipeline. |
| Tareas y actividades | DONE | CRUD aplicable, relaciones polimórficas y eventos de finalización. |
| Campos y entidades personalizadas | DONE | Definiciones, validación dinámica, registros y filtros. |
| Relaciones genéricas | DONE | CRUD tenant-safe con tipos canónicos. |
| Archivos | DONE | Persistencia de metadatos, descarga y autorización. |
| Importación y exportación | DONE | Procesamiento en jobs y seguimiento de estado. |
| Automatizaciones base | PARTIAL | Motor, validación y jobs disponibles; faltan fórmulas, reglas avanzadas y biblioteca ampliada. |
| Auditoría | DONE | Registro por tenant, actor, entidad, request e información antes/después. |
| Webhooks salientes y entrantes | DONE | Firma, entregas, reintentos, idempotencia y rutas de ingreso. |
| Catálogo de integraciones | PARTIAL | Configuración cifrada, conexión y health checks; faltan OAuth, sincronización y objetos de negocio por proveedor. |
| OpenAPI y Postman | PARTIAL | Cubren la API existente; deben crecer con cada fase nueva. |

## 4. GAP por fase del prompt maestro

### API-1 — Customer 360 y productividad base

| Capacidad | Estado inicial | GAP concreto |
|---|---|---|
| Customer 360 | PARTIAL | `contacts.show` entrega propietario y organizaciones, pero no consolida oportunidades, tareas, archivos, relaciones, métricas ni recientes. |
| Timeline unificado | PARTIAL | Existen actividades y auditoría, pero no hay endpoint cronológico normalizado por contacto ni filtros dedicados. |
| Detección de duplicados | MISSING | No existen normalización, puntuación de coincidencias ni endpoints de comprobación. |
| Fusión de duplicados | MISSING | No existe servicio transaccional que reasigne relaciones y audite la fusión. |
| Búsqueda global | MISSING | Solo hay búsqueda por listado; no hay búsqueda multi-entidad con control RBAC. |
| Vistas guardadas | MISSING | No hay persistencia ni CRUD de filtros/orden/columnas por usuario. |
| Etiquetas | MISSING | No hay catálogo de tags ni asignaciones polimórficas. |

Dependencias: etiquetas y vistas guardadas requieren nuevas tablas tenant-scoped; la fusión requiere un resolvedor de relaciones y transacciones; la búsqueda reutilizará los permisos actuales de cada recurso. Esta es la primera fase a implementar.

### API-2 — Inbox, Email, WhatsApp y notificaciones

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Centro de notificaciones | DONE | API tenant-safe de listado, conteo, leído/no leído, borrado y preferencias por evento/canal. |
| Inbox omnicanal | DONE | Inboxes, canales, conversaciones, participantes, mensajes, adjuntos, asignación, lectura, filtros, tags y respuestas rápidas. |
| Email operativo | DONE | Google/Microsoft/SMTP-IMAP desacoplados, sync incremental, threads, adjuntos, tracking, plantillas, firmas y programación. |
| WhatsApp operativo | DONE | Cloud API para texto/media/plantillas, webhook firmado e idempotente, estados y sync de plantillas. |

### API-3 — Captación y aceleración de ventas

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Formularios públicos y lead capture | DONE | Definiciones UUID publicables, submissions idempotentes, anti-spam, Turnstile, archivos, mapeo, atribución y deduplicación. |
| Lead routing | DONE | Reglas priorizadas, condiciones, fallback, round robin, balance de carga e historial. |
| Lead scoring configurable | DONE | Modelos, reglas explícitas/conductuales/negativas, eventos idempotentes, cálculo, clasificación e historial. |
| Sales sequences | DONE | Pasos multicanal, enrolamientos, pausas, reintentos, waits no bloqueantes, condiciones y stop conditions. |
| Meeting scheduler | DONE | Disponibilidad timezone-safe, buffers, exclusiones, conflictos, round robin, tokens, reprogramación y calendarios externos. |

### API-4 — Cotizaciones, productos y aprobaciones

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Productos y listas de precios | DONE | Catálogo, categorías, variantes, monedas, impuestos, descuentos, bundles, vigencias, price books y CPQ tenant-safe. |
| Cotizaciones | DONE | Versiones, líneas snapshot, cálculo decimal, impuestos, descuentos, PDF privado, envío y aceptación UUID idempotente. |
| Approval Engine | DONE | Procesos versionados, pasos, solicitudes, decisiones, delegaciones, escalaciones, eventos y auditoría. |
| Conectores ERP operativos | DONE | Provider HTTP configurable, health check, sync de productos/órdenes, idempotencia, jobs, reintentos y logs sanitizados. |

### API-5 — Forecasting y performance comercial

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Forecasting | DONE | Forecast general, por usuario/equipo, categorías, conversión monetaria, fecha de corte y snapshots. |
| Goals | DONE | Metas por periodo y siete ámbitos, ocho métricas y avance diario recalculable. |
| Territories | DONE | Jerarquía, sucursales/equipos, membresías, reglas declarativas y asignación automática. |
| Playbooks | DONE | Definiciones versionadas, secciones/preguntas, ejecuciones, score y cuatro acciones idempotentes. |
| Sales analytics | DONE | Métricas comerciales y cinco desgloses de ingresos mediante agregaciones sin N+1. |

### API-6 — Marketing Automation

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Segmentos | DONE | Árbol AND/OR seguro, cinco tipos de entidad, campos declarados y snapshots en cola. |
| Campañas | DONE | Audiencias estáticas/dinámicas, campañas email, destinatarios deduplicados, estados y métricas decimales con tracking. |
| Consentimiento y preferencias | DONE | Ledger por canal/destino, evidencia/IP/revocación, idempotencia y centro público de bajas con tokens hasheados. |
| Journeys | DONE | Grafos y coordenadas para canvas, validación sin ciclos, versiones inmutables, esperas, ramas, tareas, email y objetivos en cola. |

### API-7 — Service, soporte y Customer Success

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Tickets y colas | PARTIAL | API-7.1 entrega agentes, categorías, colas, tickets, comentarios, asignación y ciclo interno; quedan watchers, fusión, adjuntos propios, asignación automática y sincronización de canales. |
| SLA | DONE | Políticas con cuatro prioridades, calendarios ALWAYS/BUSINESS, snapshot inmutable, timers, pausa/reapertura y escalaciones recuperables al equipo. |
| Knowledge Base | DONE | Configuración única por tenant, categorías/etiquetas, revisiones inmutables, publicación con snapshot, API pública UUID y contratos OpenAPI/Postman. Portal y visibilidad `CUSTOMER` permanecen fuera de este bloque. |
| Portal de cliente | MISSING | No hay usuarios externos, sesiones ni endpoints públicos/portal. |
| Customer Success | MISSING | No hay health scores, renovaciones, riesgos ni success plans. |
| Encuestas | MISSING | No hay CSAT/NPS/CES, envíos, respuestas ni agregados. |

### API-8 — No-code y extensibilidad interna

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Custom Object Builder | PARTIAL | Objetos/campos/registros existen; faltan layouts, permisos por objeto y UX metadata avanzada. |
| Validation Rules | PARTIAL | Hay reglas por campo; faltan reglas multi-campo/versionadas y mensajes configurables. |
| Formula Fields | MISSING | No existe parser seguro, AST, dependencias ni recálculo. |
| Workflow Library | PARTIAL | Hay motor base; faltan triggers/acciones avanzadas, waits y observabilidad granular. |
| Plantillas por industria | MISSING | No hay manifiestos, versionado, instalación ni rollback. |

### API-9 — Integración y plataforma para desarrolladores

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Integration Hub | PARTIAL | Catálogo y lifecycle presentes; faltan OAuth, sync mappings, logs y reconciliación. |
| Public API | PARTIAL | La REST API autenticada existe; faltan apps, scopes, cuotas y credenciales por aplicación. |
| OAuth para terceros | MISSING | No hay authorization server, consent screen, grants ni revocación. |
| Webhooks para terceros | DONE | Registro, firma, entrega, reintento e ingreso idempotente disponibles. |
| Developer Apps | MISSING | No hay modelo de apps, secretos rotables, scopes ni métricas. |

### API-10 — Capa de IA

| Capacidad | Estado | GAP concreto |
|---|---|---|
| Infraestructura AI | MISSING | No hay proveedor abstracto, prompts versionados, políticas de datos ni presupuesto. |
| Scoring/predicción/resúmenes | MISSING | No existen casos de uso, jobs, feedback ni métricas de calidad. |
| Copilot y recomendaciones | MISSING | No existen contexto autorizado, trazabilidad ni endpoints. |

## 5. Riesgos y elementos bloqueados

| Elemento | Estado | Motivo / salida requerida |
|---|---|---|
| OAuth interactivo y validación E2E con cuentas reales Google/Microsoft/Meta/Twilio | BLOCKED | Los providers, envío y sync están implementados; registrar apps, redirect URIs y cuentas sandbox sigue dependiendo de credenciales externas del usuario. |
| Validación E2E con un ERP externo | BLOCKED | El provider y contrato HTTP configurable están implementados; falta la URL, versión, credencial y cuenta sandbox del ERP elegido. |
| Proveedor de IA y presupuesto | BLOCKED | Requiere proveedor/modelos permitidos, política de PII, retención y límites de consumo. |
| Facturación/pagos de cotizaciones | BLOCKED | Requiere proveedor de pago, monedas/países y reglas fiscales. |
| Reglas legales de consentimiento/retención | BLOCKED | Requiere jurisdicciones objetivo y política aprobada. |

Estos bloqueos no impiden desarrollar los modelos, contratos y flujos internos que no dependan de credenciales externas.

## 6. Orden de implementación aprobado por dependencias

1. `P0 / API-1`: Customer 360, timeline, deduplicación/fusión, búsqueda global, vistas guardadas y tags.
2. `P0 / API-2`: notificaciones e inbox; luego canales email/WhatsApp.
3. `P0 / API-3`: formularios, routing y scoring; después secuencias y scheduler.
4. `P1 / API-4`: catálogo, price books, quotes y approval engine.
5. `P1 / API-5`: territorios/metas antes de forecasting y analytics.
6. `P1 / API-6`: consentimiento y segmentos antes de campañas/journeys.
7. `P1 / API-7`: tickets y calendarios antes de SLA/portal/success.
8. `P2 / API-8`: reglas y fórmulas seguras antes de ampliar workflows/templates.
9. `P2 / API-9`: developer apps/scopes antes de OAuth público y sync hub.
10. `P2 / API-10`: infraestructura y gobierno AI antes de cualquier caso de uso.

Cada fase debe cerrar migraciones, modelos, autorización, validación, servicios, endpoints, auditoría/eventos, aislamiento por tenant, tests de éxito/error/permiso/tenant y actualización OpenAPI antes de iniciar la siguiente.

## 7. Criterio inmediato de cierre de API-1

- Migraciones reversibles para tags, asignaciones y vistas guardadas, compatibles con PostgreSQL y SQLite de pruebas.
- Endpoints de overview y timeline del contacto, paginados y autorizados.
- Comprobación y fusión transaccional de duplicados de contactos y organizaciones.
- Búsqueda global que solo devuelva tipos autorizados para el usuario activo.
- CRUD de vistas guardadas privadas o compartidas dentro del tenant.
- CRUD y asignación de tags sobre tipos permitidos, con validación tenant-safe.
- Auditoría de toda mutación y eventos útiles de dominio.
- Pruebas de aislamiento, permisos, validaciones, fusión y regresión.
- OpenAPI y ejemplos Postman actualizados.

## 8. Resultado de implementación de API-1

API-1 quedó cerrada el 2026-09-01 sin reintroducir slugs ni variables separadas de conexión.

| Capacidad | Estado final | Evidencia principal |
|---|---|---|
| Customer 360 | DONE | Overview condicionado por RBAC con empresas, leads, oportunidades, actividades, tareas, documentos, relaciones y auditoría. |
| Timeline unificado | DONE | Fuentes `activity`/`audit`, filtros, paginación, redacción de valores y linaje de merge. |
| Detección de duplicados | DONE | Normalización, índices tenant-scoped, scoring determinista, umbral y aliases de compañías. |
| Fusión de duplicados | DONE | Transacción, locks, reasignación de relaciones, soft delete, linaje, auditoría y eventos. |
| Búsqueda global | DONE | Seis tipos de recursos, ranking, paginación, escaping SQL, RBAC por tipo y aislamiento tenant. |
| Vistas guardadas | DONE | Filtros declarativos, columnas, orden, default y visibilidad privada/rol/tenant. |
| Etiquetas | DONE | Catálogo, CRUD, asignaciones idempotentes, tipos canónicos y conteos limitados por permisos. |
| OpenAPI y Postman | DONE | 74 paths parseados y validados semánticamente; flujo Postman encadenado de 10 solicitudes. |

Verificación ejecutada:

- suite focalizada API-1: 7 pruebas, 88 aserciones;
- suite completa: 31 pruebas, 195 aserciones;
- lint PHP: 221 archivos propios sin errores;
- migración completa y rollback individual correctos sobre SQLite temporal;
- colección Postman JSON válida y OpenAPI sin referencias ni parámetros de path inconsistentes.

La guía técnica, decisiones de escala y contrato de endpoints están en `docs/10-API-1-CUSTOMER-360.md`. La ejecución PostgreSQL continúa cubierta por `phpunit.postgres.xml` y el job PostgreSQL de CI; debe formar parte del pipeline previo a producción.

## 9. Resultado de implementación de API-2

API-2 quedó cerrada el 2026-09-02 con inbox omnicanal, notificaciones, correo entrante/saliente y WhatsApp Cloud API, manteniendo `DATABASE_URL` y `REDIS_URL` como conexiones únicas de infraestructura.

| Capacidad | Estado final | Evidencia principal |
|---|---|---|
| Inbox y conversaciones | DONE | Inboxes, canales, participantes, asignación, lectura por usuario, estados, adjuntos, tags y respuestas rápidas. |
| Notificaciones | DONE | Centro tenant-safe, conteos, lectura individual/masiva y preferencias por evento/canal. |
| Correo saliente | DONE | Gmail, Microsoft Graph y SMTP mediante transportes desacoplados, tracking opcional y plantillas. |
| Correo entrante | DONE | Sync incremental Gmail/Graph e IMAP por UID, threading, contactos, HTML sanitizado y adjuntos limitados. |
| WhatsApp | DONE | Envío de texto/media/templates, webhook firmado e idempotente, estados entrantes y sync de plantillas. |
| Programación y tiempo real | DONE | Mensajes programados, scheduler, Horizon y eventos privados Reverb por tenant. |
| OpenAPI y Postman | DONE | 107 paths, 31 schemas y flujo Postman encadenado en la carpeta 07. |

Verificación ejecutada:

- suite focalizada API-1/API-2: 18 pruebas, 285 aserciones;
- suite completa: 42 pruebas, 392 aserciones;
- Pint y lint PHP: 303 archivos propios sin errores;
- ciclo `migrate:fresh`, rollback de las tres migraciones API-2 y reaplicación correctos sobre SQLite temporal;
- colección Postman JSON válida: 10 carpetas y 33 variables;
- OpenAPI válido: 442 referencias resueltas y cero inconsistencias en parámetros de path.

La guía técnica y operativa está en `docs/11-API-2-INBOX-CANALES.md`. La conexión E2E con cuentas reales continúa condicionada únicamente a registrar credenciales y aplicaciones sandbox de cada proveedor.

## 10. Resultado de implementación de API-3

API-3 quedó cerrada el 2026-09-03 sin slugs: los formularios y meeting types públicos usan UUID `public_id` y los bookings un UUID más un token de gestión cifrado.

| Capacidad | Estado final | Evidencia principal |
|---|---|---|
| Forms y lead capture | DONE | Builder dinámico, sesiones cifradas, honeypot, Turnstile, archivos, atribución, idempotencia y creación/vinculación CRM. |
| Lead routing | DONE | Reglas declarativas tenant-safe, prioridades, round robin ponderado, load balancing, fallback, auditoría e historial. |
| Lead scoring | DONE | Modelos y reglas configurables, eventos conductuales idempotentes, límites, clasificación e historial por lead. |
| Sequences | DONE | Ocho tipos de pasos, renderer seguro, jobs únicos/reintentables, lifecycle, historial y parada por respuestas/reuniones/oportunidades. |
| Meeting Scheduler | DONE | Working hours, timezones, buffers, exclusiones, conflictos internos/externos, round robin, cancelación y reprogramación. |
| Integraciones API-3 | DONE | Google Calendar/Meet, Microsoft Calendar/Teams, Zoom y SMS Twilio mediante adapters desacoplados. |
| OpenAPI y Postman | DONE | 149 paths, 50 schemas y flujo encadenado en la carpeta 08. |

Verificación ejecutada:

- suite focalizada API-3: 10 pruebas, 167 aserciones;
- suite completa: 52 pruebas, 559 aserciones;
- colección Postman JSON válida: 11 carpetas y 50 variables;
- OpenAPI parseable y sin referencias locales faltantes;
- rutas públicas limitadas y recursos administrativos protegidos por permisos/policies;
- migraciones API-3 reversibles sobre SQLite temporal.

La guía funcional, operativa y de Postman está en `docs/12-API-3-CAPTACION-SECUENCIAS-AGENDA.md`. Las pruebas E2E contra proveedores reales requieren únicamente credenciales y cuentas sandbox con los scopes correspondientes.

## 11. Resultado de implementación de API-4

API-4 quedó cerrada el 2026-09-03 sin slugs, con importes decimales serializados como strings y enlaces públicos de cotización basados en UUID `public_id` más token.

| Capacidad | Estado final | Evidencia principal |
|---|---|---|
| Catálogo y price books | DONE | Monedas, categorías, cuatro tipos de producto, variantes, impuestos, descuentos, bundles y listas por cantidad/vigencia. |
| CPQ | DONE | Reglas declarativas de precio/descuento/bundle/aprobación y dependencias `requires`, `excludes` y `recommends`. |
| Cotizaciones | DONE | Numeración tenant-safe, versiones, duplicación, snapshots, estados, actividades, aceptación idempotente y Customer 360. |
| PDF y envío | DONE | mPDF, almacenamiento privado, `FileRecord`, job `documents` y entrega mediante el canal Email existente. |
| Approval Engine | DONE | Pasos por usuario/rol/permiso, `any`/`all`, delegaciones, escalamiento programado y decisiones con replay. |
| ERP | DONE | Provider `vantex_erp`, boundary SSRF-safe, jobs únicos, logs, reintentos y actualización de IDs externos. |
| OpenAPI y Postman | DONE | 200 paths, 72 schemas, 331 operaciones y flujo encadenado en la carpeta 09. |

La suite focalizada API-4 cubre 8 pruebas y 193 aserciones. La verificación final de regresión y sus cifras globales se ejecutan antes de cada entrega.

La guía funcional, operativa y de Postman está en `docs/13-API-4-CATALOGO-COTIZACIONES-APROBACIONES.md`. La conexión E2E con el ERP y el envío real de cotizaciones requieren credenciales sandbox del proveedor y de una cuenta de correo activa.

## 12. Resultado de implementación de API-5

API-5 quedó cerrada el 2026-09-07 sin slugs y manteniendo `DATABASE_URL` y `REDIS_URL` como únicas variables de conexión a PostgreSQL y Redis.

| Capacidad | Estado final | Evidencia principal |
|---|---|---|
| Estructura comercial | DONE | Sucursales y equipos jerárquicos, managers, membresías y cambio explícito de rol por `PATCH`. |
| Territories | DONE | Árboles sin ciclos, reglas declarativas priorizadas, membresías y asignaciones manuales/automáticas. |
| Goals | DONE | Ocho métricas, siete ámbitos, periodos, targets y snapshots diarios de avance con cálculo decimal. |
| Forecasting | DONE | Pipeline, ponderado, commit, best case, won, target, coverage, rollups y snapshots con fecha de corte. |
| Sales Analytics | DONE | Ratios y velocidad comercial más ingresos por owner, producto, industria, territorio y origen. |
| Playbooks | DONE | Versionado, tipos de respuesta, validación, score y acciones de campo/workflow/tarea con replay seguro. |
| Oportunidades | DONE | Pipeline/etapa tenant-safe, estructura comercial coherente, sucursal inferida y relaciones incluidas en respuesta. |
| OpenAPI y Postman | DONE | 235 paths, 90 schemas, 394 operaciones y flujo encadenado de 20 solicitudes en la carpeta 10. |

Verificación ejecutada:

- suite focalizada API-5: 5 pruebas, 123 aserciones;
- suite completa: 65 pruebas, 876 aserciones;
- paridad de contrato: 63 operaciones API-5 reales y 63 documentadas;
- OpenAPI válido: 992 referencias locales resueltas y cero inconsistencias de parámetros de path;
- colección Postman JSON válida: 13 carpetas, 78 variables y 117 solicitudes;
- ciclo `migrate:fresh`, rollback individual de API-5 y reaplicación correctos sobre SQLite temporal.

La guía técnica y operativa está en `docs/14-API-5-FORECASTING-PERFORMANCE.md`. Las comparaciones históricas exactas deben usar snapshots: `as_of_date` no puede reconstruir cambios de etapa o importe anteriores a la instalación si esos eventos no fueron almacenados.

## 13. Resultado de implementación de API-7.1

El primer bloque de API-7 quedó implementado el 2026-09-12 sin declarar terminado el resto de Service y Customer Success.

| Capacidad | Estado final | Evidencia principal |
|---|---|---|
| Configuración de soporte | DONE | Agentes basados en usuarios, categorías, colas, membresías, responsable de escalación y bajas protegidas. |
| Tickets internos | DONE | Alta idempotente, vínculos CRM tenant-safe, filtros, edición, asignación, seis estados, cierre definitivo y auditoría. |
| Comentarios | DONE | Notas `INTERNAL`, respuestas `PUBLIC`, primera respuesta inmutable y ausencia de envío implícito a email/WhatsApp. |
| SLA | DONE | Reglas por cuatro prioridades, calendarios 24/7/hábiles con zonas y DST, snapshot, presupuesto, pausas, resolución y reapertura. |
| Escalaciones | DONE | Detección programada, una escalación por métrica, destinatarios revalidados, reserva recuperable, tres intentos y notificación interna. |
| Seguridad | DONE | Permisos independientes, referencias cruzadas 422, rutas externas 404, campos del servidor protegidos y cuerpos privados fuera de logs/alertas. |
| OpenAPI y Postman | DONE | 36 operaciones documentadas y flujo autocontenido de 34 solicitudes con ramas de agente nuevo/existente. |

La regresión focalizada de API-7.1 cubre 160 pruebas y 1.472 aserciones; la suite completa pasa con 240 pruebas y 2.698 aserciones sobre SQLite aislado. OpenAPI conserva las 446 operaciones previas y coincide con las 482 rutas Laravel documentadas tras normalizar únicamente los nombres equivalentes de parámetros antiguos.

La guía funcional y operativa está en `docs/16-API-7-1-TICKETS-SLA.md`. La base de conocimiento quedó cubierta en `docs/17-API-7-2-KNOWLEDGE-BASE.md`; portal de clientes, Customer Success, health scores, renovaciones y encuestas continúan pendientes. Las carreras SQL, índices y RLS deben ejecutarse además en PostgreSQL desechable mediante CI antes de producción.
