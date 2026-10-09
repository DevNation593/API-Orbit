# API-6 — Marketing, segmentos, consentimiento y journeys

Implementa las secciones 28–30 y la fase API-6 del documento maestro. Conserva los IDs numéricos, el aislamiento por tenant y las respuestas `{data, meta}`. No utiliza slugs ni cambia `DATABASE_URL` o `REDIS_URL`.

## Puesta en marcha

La migración nueva es `2026_09_07_000000_create_marketing_foundations.php`. No se ha aplicado a la base de datos de trabajo.

Después de revisar el destino de `DATABASE_URL` y respaldar la base, ejecutar en el entorno de despliegue:

```bash
php artisan migrate
php artisan horizon
php artisan schedule:work
```

En Windows, donde Horizon requiere extensiones de Unix, se puede usar el worker de Laravel:

```bash
php artisan queue:work redis --queue=marketing,integrations --timeout=300 --tries=3
php artisan schedule:work
```

Horizon incorpora el supervisor `marketing`. El scheduler ejecuta `marketing:dispatch-due` cada minuto. Los envíos salen por `integrations`; campañas, snapshots y nodos de journeys se procesan en `marketing`. No hace falta agregar variables de conexión.

## Modelo y permisos

Se agregan 14 tablas: segmentos y sus snapshots, audiencias y sus miembros, ledger de consentimiento y enlaces, campañas, configuración email, destinatarios, eventos, journeys, versiones, inscripciones y ejecuciones.

Los roles de sistema y los roles con `settings.manage` reciben los permisos nuevos durante la migración. Los roles personalizados restantes deben recibirlos explícitamente.

| Módulo | Lectura | Administración | Ejecución |
|---|---|---|---|
| Segmentos | `segments.view` | `segments.manage` | Refresh requiere manage |
| Audiencias | `audiences.view` | `audiences.manage` | Miembros y refresh requieren manage |
| Consentimiento | `consent.view` | `consent.manage` | Baja pública mediante enlace secreto |
| Campañas | `campaigns.view` | `campaigns.manage` | `campaigns.send` |
| Journeys | `journeys.view` | `journeys.manage` | `journeys.enroll` |

Las 52 operaciones nuevas están documentadas en `docs/openapi.yaml`, con 39 schemas nuevos. Salvo el centro público de preferencias, requieren Bearer token y una membresía válida para `X-Tenant-ID`.

## Segmentación

```text
GET                 /api/v1/segments/fields?entity_type=contacts
POST                /api/v1/segments/preview
GET|POST            /api/v1/segments
GET|PATCH|DELETE    /api/v1/segments/{segment}
GET                 /api/v1/segments/{segment}/members
POST                /api/v1/segments/{segment}/refresh
```

Entidades: `contacts`, `organizations`, `leads`, `deals` y `custom_objects`. También se normalizan los alias `companies` y `opportunities`. Los objetos personalizados requieren `entity_definition_id` del tenant activo.

Ejemplo para crear un segmento:

```json
{
  "name": "Contactos interesados",
  "entity_type": "contacts",
  "definition": {
    "operator": "and",
    "conditions": [
      {"field": "status", "operator": "equals", "value": "active"},
      {
        "operator": "or",
        "conditions": [
          {"field": "email", "operator": "contains", "value": "@example.com"},
          {"field": "phone", "operator": "is_not_empty"}
        ]
      }
    ]
  }
}
```

Operadores: `equals`, `not_equals`, `contains`, `greater_than`, `less_than`, `before`, `after`, `is_empty` e `is_not_empty`. Los grupos utilizan `and` u `or`, con máximo 100 nodos y cinco niveles de anidación. La comparación de texto es exacta; `contains` trata `%` y `_` como caracteres literales.

Los campos permitidos se consultan en `/segments/fields`. Los escalares personalizados activos se exponen como `custom_fields.nombre` o `data.nombre`; no se aceptan rutas JSON arbitrarias, SQL, relaciones ejecutables ni campos de otro tenant. Los campos multiselección, relación y archivo no son filtros escalares.

Los operadores vacíos consideran NULL y, para texto, cadena vacía. `not_equals` también incluye valores ausentes. Números, booleanos y fechas tienen validación de tipo; las fechas requieren formato ISO. Las comparaciones numéricas JSON no utilizan orden lexicográfico.

`preview` devuelve resultados actuales y paginados. `refresh` responde 202 y reemplaza el snapshot de miembros en una transacción. Cambiar la definición invalida el snapshot y aumenta `revision`; el tipo de entidad es inmutable. `members` devuelve IDs del último snapshot, no una consulta dinámica.

## Audiencias

```text
GET|POST            /api/v1/audiences
GET|PATCH|DELETE    /api/v1/audiences/{audience}
GET|POST|DELETE    /api/v1/audiences/{audience}/members
POST                /api/v1/audiences/{audience}/refresh
```

Las audiencias de marketing son de contactos o leads:

- `static`: miembros explícitos; POST/DELETE reciben `{"entity_ids":[1,2]}`, hasta 500 por petición. Agregar de nuevo no duplica filas.
- `dynamic`: requieren un segmento activo del mismo tipo. El refresh reconstruye sus miembros; no se admite modificar miembros manualmente.

Al preparar una campaña, la audiencia dinámica se evalúa con los datos vigentes y se congela un snapshot propio. Cambios posteriores de audiencia no alteran esa campaña. La actualización del consentimiento sí se respeta durante el envío.

## Consentimiento y bajas

```text
GET|POST    /api/v1/consents
GET         /api/v1/consents/preferences?entity_type=contacts&entity_id=1
POST        /api/v1/consent-links
DELETE      /api/v1/consent-links/{consentLink}
GET|POST    /api/v1/public/preferences/{token}
```

GET `/consents` requiere `entity_type` y `entity_id` para consultar el historial. POST registra un evento inmutable:

```json
{
  "entity_type": "contacts",
  "entity_id": 1,
  "channel": "email",
  "status": "opt_in",
  "source": "registration_form",
  "evidence": "Checkbox aceptado; versión del formulario 2.",
  "idempotency_key": "form-submission-123-email"
}
```

Los canales son `email`, `sms` y `whatsapp`, cada uno con `opt_in`/`opt_out`. Así se representan los seis estados email/sms/whatsapp opt-in y opt-out del documento. El estado inicial es `unknown`: no habilita marketing.

Se registran fecha del servidor, origen, IP, evidencia y revocación. El opt-in exige evidencia. Una clave repetida con el mismo payload devuelve el registro original; un cambio de payload devuelve 409. Repetir un opt-in antiguo no invalida una baja posterior.

El consentimiento se vincula al destino normalizado dentro del tenant. Los registros que comparten un correo/teléfono comparten su estado efectivo; cambiar el destino no hereda un permiso anterior. Los teléfonos usan formato E.164.

Los enlaces son secretos aleatorios de 64 caracteres, almacenados como hash y con vigencia de 90 días. Conservan los destinos que existían al emitirse; una baja desde un enlace antiguo nunca afecta un correo nuevo. Se pueden revocar individualmente.

GET público es de solo lectura: muestra una página de baja en navegador o JSON con `Accept: application/json`. POST solo permite opt-out:

```json
{
  "preferences": {"email": "opt_out"},
  "idempotency_key": "unsubscribe-click-123"
}
```

Las respuestas públicas no exponen identidad ni direcciones. Usan no-store, protección frente a iframes y política de referrer. Este mecanismo técnico no sustituye la definición de políticas legales de consentimiento/retención para las jurisdicciones del negocio.

## Campañas de email y métricas

```text
GET|POST            /api/v1/campaigns
GET|PATCH|DELETE    /api/v1/campaigns/{campaign}
POST                /api/v1/campaigns/{campaign}/launch
POST                /api/v1/campaigns/{campaign}/pause
POST                /api/v1/campaigns/{campaign}/resume
POST                /api/v1/campaigns/{campaign}/cancel
GET                 /api/v1/campaigns/{campaign}/members
GET|POST            /api/v1/campaigns/{campaign}/events
GET                 /api/v1/campaigns/{campaign}/metrics
```

Crear con nombre, audiencia, remitente activo, moneda de tres letras y `email`:

```json
{
  "name": "Invitación",
  "audience_id": 1,
  "sender_user_id": 1,
  "currency": "USD",
  "cost": "50.000001",
  "email": {
    "inbox_channel_id": 1,
    "subject": "Hola {{contact.first_name}}",
    "body": "Te invitamos a nuestra presentación.",
    "body_html": "<p>Te invitamos a nuestra presentación.</p>"
  }
}
```

Estados: draft → scheduled/sending → completed, con pausa, reanudación y cancelación. El contenido se modifica únicamente en draft. Una campaña lanzada no se elimina: se cancela y conserva su historial.

El worker deduplica por dirección, procesa hasta 100 destinatarios pendientes por ejecución y omite destinatarios sin consentimiento. HTML se sanea y se agrega un enlace de preferencias. Los envíos usan las cuentas Google/Microsoft/SMTP existentes: un canal sin cuenta conectada no puede entregar correo.

La elegibilidad se comprueba al encolar y antes de entregar. Una baja, cambio de destinatario, cancelación o remitente inactivo suprime el envío pendiente. Una pausa conserva el mensaje para reanudarlo. Los reintentos locales no vuelven a entregar mensajes cuyo estado aceptado ya se registró; las ambigüedades de red después de una aceptación externa dependen de las garantías del proveedor.

Los eventos automáticos alimentan sent/delivered/opened/clicked. Las conversiones se registran mediante POST events con `campaign_member_id`, `event=converted`, `revenue` como string e `idempotency_key`. Una misma clave no puede representar dos eventos distintos.

Las métricas cuentan destinatarios únicos por evento. Revenue suma conversiones sin duplicarlas, en la moneda de la campaña. Cost, revenue y ROI utilizan aritmética decimal; `roi = (revenue-cost)*100/cost`, y es NULL si cost es cero. Se incluyen pending, skipped, failed, bounced y unsubscribed. La atribución de ingresos requiere eventos de conversión; no se infiere automáticamente de oportunidades.

## Journeys

```text
GET|POST            /api/v1/journeys
GET|PATCH|DELETE    /api/v1/journeys/{journey}
POST                /api/v1/journeys/{journey}/versions
POST                /api/v1/journeys/{journey}/publish
PATCH               /api/v1/journeys/{journey}/status
GET|POST            /api/v1/journeys/{journey}/enrollments
GET                 /api/v1/journeys/{journey}/enrollments/{journeyEnrollment}
POST                /api/v1/journeys/{journey}/enrollments/{journeyEnrollment}/pause
POST                /api/v1/journeys/{journey}/enrollments/{journeyEnrollment}/resume
POST                /api/v1/journeys/{journey}/enrollments/{journeyEnrollment}/cancel
```

Un grafo tiene `entry` y `nodes`. Cada nodo incluye ID propio de grafo, tipo, configuración y enlaces; puede almacenar `position: {x,y}` para un editor visual. La API no implementa el editor frontend.

Tipos: start, condition, wait, email, task, goal y end. Se exige un solo inicio, todos los nodos alcanzables y ausencia de ciclos. Las condiciones reutilizan el motor de segmentos.

Las definiciones se versionan sin sobrescribir las versiones anteriores. Publicar recibe `{"version_id":1}`. Cada inscripción fija su versión y requiere `entity_id`, `sender_user_id` e `idempotency_key`. No se admite otra inscripción activa del mismo destinatario en ese journey.

El scheduler ejecuta un nodo por job. Las esperas guardan una fecha futura y liberan el worker. La pausa conserva esa fecha; al reanudar una espera ya transcurrida continúa en la siguiente ejecución. Una condición elige on_true/on_false; un goal cumplido termina la inscripción y registra goal_reached_at.

Las tareas y mensajes se crean dentro de la misma transacción que el historial y el avance. Los pasos email sin opt-in se registran como omitidos. Publicar otra versión no modifica inscripciones existentes. Los fallos reintentables se reintentan mediante la cola; al agotarse, la inscripción queda failed. Los journeys con historial se archivan, no se eliminan.

## Verificación y Postman

```bash
php artisan test --compact --filter=Marketing
```

La cobertura comprueba filtros anidados, números/fechas, snapshots, tenant isolation, RBAC, consentimiento por canal, claves conflictivas, enlaces públicos, baja posterior al encolado, deduplicación de destinatarios, proveedores simulados, tracking, ROI decimal, versionado, ramas, esperas, objetivos, cancelación y scheduler.

La carpeta **11 - API-6 marketing, segmentos, consentimiento y journeys** contiene 35 solicitudes encadenadas. Después de autenticación, crea sus propios recursos y administra sus IDs. Programa una campaña a 24 horas y la cancela; no requiere credenciales externas ni entrega emails. La prueba `MarketingPostmanWorkflowTest` ejecuta esas solicitudes contra SQLite y verifica sus códigos, el encadenamiento y la ausencia de tráfico saliente.

El contrato completo está en OpenAPI; las cuentas externas reales se configuran aparte. Las pruebas realizadas localmente usan SQLite y proveedores simulados; PostgreSQL y servicios externos deben validarse en CI/despliegue con sus credenciales y roles.
