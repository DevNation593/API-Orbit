# CRM Backend

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
- Jobs separados por colas: notifications, automations, integrations, imports, exports.
- Idempotencia para operaciones externas, firma HMAC de webhooks y auditoría inmutable desde API ordinaria.
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
