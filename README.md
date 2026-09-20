# Vantex CRM API

Backend Laravel 13 para un CRM SaaS multiindustria con arquitectura Modular Monolith.

## Principios implementados

- PostgreSQL como base principal, Redis para cache/colas y S3-compatible para archivos.
- Aislamiento por tenant_id en todas las entidades empresariales, con ResolveTenant centralizado.
- Selección de tenant únicamente entre membresías del usuario autenticado mediante X-Tenant-ID; el payload no puede elegir el tenant.
- Sanctum Bearer tokens, rate limiting, CORS configurable y reset de contraseña.
- RBAC propio con roles por tenant, permisos explícitos y Policies.
- Custom Fields sobre JSON/JSONB lógico, Custom Entities y relaciones dinámicas.
- Conversión Lead -> Contact/Organization/Deal dentro de una transacción.
- Workflows validados como JSON declarativo; no se ejecuta código enviado por usuarios.
- Integraciones tenant-scoped con credenciales cifradas y contrato de adapters/providers.
- Jobs separados por colas: notifications, automations, integrations, sequences, marketing, support, documents, imports, exports.
- Idempotencia para operaciones externas, firma HMAC de webhooks y auditoría inmutable desde API ordinaria.
- Customer 360, timeline normalizado, deduplicación/fusión, búsqueda global, vistas guardadas y tags tenant-scoped.
- Inbox omnicanal con conversaciones, lectura, asignaciones, respuestas rápidas, notificaciones y eventos en tiempo real.
- Correo Google/Microsoft/SMTP-IMAP con sync incremental, threads, adjuntos, tracking, plantillas, firmas y envío programado.
- WhatsApp Cloud API con mensajes multimedia/plantillas, firma HMAC, idempotencia, estados y sincronización de plantillas.
- Formularios públicos UUID, atribución, anti-spam, lead routing y scoring configurable con historial.
- Sales sequences con jobs idempotentes y pasos email, WhatsApp, SMS Twilio, tareas, waits, condiciones y notificaciones.
- Meeting Scheduler UUID con timezones, buffers, exclusiones, conflictos, round robin, reprogramación y Google Meet, Teams y Zoom.
- Catálogo, listas de precios, CPQ, cotizaciones versionadas, PDF, aceptación UUID, Approval Engine y sincronización ERP idempotente.
- Sucursales, equipos y territorios jerárquicos con membresías, reglas y asignación automática tenant-safe.
- Metas por ámbito, forecast decimal con snapshots, analítica comercial optimizada y playbooks versionados con acciones idempotentes.
- Segmentos dinámicos, audiencias, campañas email con métricas, consentimiento por canal y journeys versionados con esperas y objetivos.
- Tickets internos tenant-safe con agentes, categorías, colas, comentarios idempotentes, snapshots SLA, pausas, reapertura y escalaciones recuperables.
- OpenAPI en /api/v1/openapi.yaml.
- RLS de PostgreSQL opcional con TENANT_RLS_ENABLED=true; la aplicación continúa siendo la primera barrera.
- Docker Compose para Laravel, PostgreSQL, Redis, MinIO y Mailpit; Horizon y Reverb incluidos.
- CI con Pint, tests y composer audit.

## Arranque local

1. Copiar .env.example a .env.
2. Ejecutar `php artisan key:generate`.
3. Ejecutar `docker compose up --build`.
4. Ejecutar `docker compose exec app php artisan db:seed` para datos demo explícitos.

El seeder demo usa `admin@example.com / password`; es exclusivamente local y no debe ejecutarse ni conservarse en producción.

## API

La API está versionada bajo /api/v1. Tras autenticarse, el tenant activo se resuelve desde la primera membresía activa o desde el header X-Tenant-ID si el usuario pertenece a ese tenant.

Todos los recursos de negocio se identifican mediante IDs numéricos. El login puede recibir `tenant_id` para seleccionar una membresía concreta y los registros personalizados usan `/entities/{entityDefinition}/records`, donde `entityDefinition` es el ID de la definición. Los campos de una entidad personalizada se enlazan mediante `entity_definition_id`.

Ejemplo:

    Authorization: Bearer <sanctum-token>
    X-Tenant-ID: 1

En Docker, el backend publica hacia Reverb usando `REVERB_HOST=reverb`; el frontend debe usar `VITE_REVERB_HOST=localhost`.

Redis se configura únicamente con `REDIS_URL`. La URL puede incluir usuario, contraseña, puerto, base y TLS, por ejemplo `redis://redis:6379` en Docker o `rediss://usuario:contraseña@host:6380/0` en un proveedor administrado. La aplicación usa `phpredis` cuando la extensión está disponible y cambia automáticamente a `predis` en entornos como PHP para Windows.

PostgreSQL se configura únicamente con `DATABASE_URL`, incluyendo protocolo, credenciales, host, puerto, base y opciones SSL. Por ejemplo: `postgresql://crm:change-me@postgres:5432/crm` en Docker o `postgresql://usuario:contraseña@host:5432/base?sslmode=require` en un proveedor administrado. Los caracteres especiales de usuario y contraseña deben codificarse para URL.

La colección y el entorno para ejecutar el flujo de humo en Postman están en [docs/postman](docs/postman/README.md).

La fase API-1 está documentada en [docs/10-API-1-CUSTOMER-360.md](docs/10-API-1-CUSTOMER-360.md). Sus rutas principales son `/contacts/{id}/overview`, `/contacts/{id}/timeline`, `/contacts/duplicate-check`, `/contacts/{id}/merge`, `/search`, `/saved-views` y `/tags`; las organizaciones conservan `/organizations` y también aceptan el alias `/companies` únicamente en deduplicación.

La fase API-2 está documentada en [docs/11-API-2-INBOX-CANALES.md](docs/11-API-2-INBOX-CANALES.md). Docker Compose incluye Horizon, Reverb y el scheduler requerido para sincronizaciones y mensajes programados.

La fase API-3 está documentada en [docs/12-API-3-CAPTACION-SECUENCIAS-AGENDA.md](docs/12-API-3-CAPTACION-SECUENCIAS-AGENDA.md). El scheduler también despacha secuencias vencidas cada minuto; los formularios y enlaces de reunión públicos se identifican mediante UUID `public_id`, nunca mediante slugs.

La fase API-4 está documentada en [docs/13-API-4-CATALOGO-COTIZACIONES-APROBACIONES.md](docs/13-API-4-CATALOGO-COTIZACIONES-APROBACIONES.md). Los importes se calculan con precisión decimal, las cotizaciones públicas usan UUID `public_id` y token, y Horizon procesa las colas `documents` e `integrations`.

La fase API-5 está documentada en [docs/14-API-5-FORECASTING-PERFORMANCE.md](docs/14-API-5-FORECASTING-PERFORMANCE.md). Incluye los endpoints de estructura comercial, territorios, metas, forecast, snapshots, analítica y playbooks; las oportunidades devuelven pipeline, etapa, equipo, sucursal y territorio en la misma respuesta.

La fase API-6 está documentada en [docs/15-API-6-MARKETING-SEGMENTOS-CONSENTIMIENTO-JOURNEYS.md](docs/15-API-6-MARKETING-SEGMENTOS-CONSENTIMIENTO-JOURNEYS.md). Incluye campañas, segmentos, audiencias, ledger de consentimiento, centro público de bajas y journeys. Requiere la migración nueva, el worker de la cola `marketing` y el scheduler; los envíos usan `integrations`.

El primer bloque de API-7 está documentado en [docs/16-API-7-1-TICKETS-SLA.md](docs/16-API-7-1-TICKETS-SLA.md). Incluye soporte interno, estados de ticket, comentarios sin envío implícito, calendarios y políticas SLA, snapshots inmutables y escalaciones al equipo. La base de conocimiento se documenta en [docs/17-API-7-2-KNOWLEDGE-BASE.md](docs/17-API-7-2-KNOWLEDGE-BASE.md): añade revisiones inmutables, publicación y lectura pública UUID sin slugs. Portal, Customer Success y encuestas siguen pendientes.

Las respuestas exitosas tienen data y meta; los errores tienen message y errors.

## Producción

- Definir credenciales reales mediante el gestor de secretos del proveedor; no colocar secretos en Git.
- Usar PostgreSQL administrado con backups y restauración ensayada.
- Configurar S3 real y URLs temporales.
- Ejecutar Horizon en workers Linux con pcntl/posix.
- Revisar el gate de Horizon y sustituir el proveedor de correo de prueba.
- Activar RLS después de validar el procedimiento de despliegue y el rol de conexión.
- Aplicar Terraform desde un pipeline protegido y revisar el plan antes de aplicar.

La prueba local usa SQLite en memoria para velocidad. CI también ejecuta la suite con PostgreSQL mediante `phpunit.postgres.xml`; la validación de Docker/Compose y Terraform se ejecuta en CI o en el entorno de despliegue. En el entorno actual se validaron `docker compose config` y `terraform fmt`; no se levantaron contenedores reales durante esta entrega.
