# API-3 — Captación, scoring, secuencias y agenda

API-3 completa el flujo desde una captación pública hasta la asignación, calificación, seguimiento automático y reserva de una reunión. Todos los recursos empresariales conservan `tenant_id`, RBAC, auditoría y aislamiento por scope/RLS.

## Decisiones de contrato

- Los IDs internos continúan siendo numéricos.
- Los enlaces públicos de formularios y reuniones usan UUID en `public_id`.
- No existe ni se requiere `slug`.
- PostgreSQL se configura solo mediante `DATABASE_URL` y Redis solo mediante `REDIS_URL`.
- Las operaciones externas reintentables usan claves de idempotencia y jobs; no hay esperas bloqueantes.

## Módulos incluidos

### Formularios y lead capture

Entidades: `Form`, `FormField`, `FormSubmission` y `LeadCaptureEvent`.

Rutas principales:

```text
GET|POST       /api/v1/forms
GET|PATCH|DELETE /api/v1/forms/{form}
POST           /api/v1/forms/{form}/rotate-public-id
GET            /api/v1/forms/{form}/submissions
GET            /api/v1/public/forms/{publicId}
POST           /api/v1/public/forms/{publicId}/submit
```

El formulario soporta campos dinámicos validados, mapeo a lead/contact/custom fields, atribución UTM, archivos, deduplicación, sesiones cifradas, honeypot, rate limiting e integración opcional con Cloudflare Turnstile. Un envío puede mandar `Idempotency-Key` o `idempotency_key`.

Para activar Turnstile:

```dotenv
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

### Lead routing

Entidades: `LeadRoutingRule`, `LeadRoutingCondition`, `LeadRoutingAction` y `LeadRoutingExecution`.

Soporta prioridades, condiciones declarativas, fallback, round robin ponderado, balance por carga, disponibilidad y estrategias especializadas. Los candidatos siempre se validan contra membresías activas del tenant.

```text
GET|POST          /api/v1/lead-routing-rules
GET|PATCH|DELETE  /api/v1/lead-routing-rules/{rule}
POST              /api/v1/leads/{lead}/route
GET               /api/v1/leads/{lead}/routing-executions
```

### Lead scoring

Entidades: `ScoringModel`, `ScoringRule`, `ScoringCondition`, `ScoringEvent` y `LeadScore`.

Los modelos aceptan reglas `explicit`, `behavioral` y `negative`, eventos idempotentes, límites de puntuación y clasificación `cold`, `warm` o `hot`.

```text
GET|POST          /api/v1/scoring-models
GET|PATCH|DELETE  /api/v1/scoring-models/{model}
POST              /api/v1/scoring-models/{model}/rules
PATCH|DELETE      /api/v1/scoring-rules/{rule}
GET               /api/v1/leads/{lead}/scores
POST              /api/v1/leads/{lead}/scores/recalculate
POST              /api/v1/leads/{lead}/score-events
```

### Sales sequences

Entidades: `Sequence`, `SequenceStep`, `SequenceEnrollment` y `SequenceExecution`.

Pasos disponibles:

```text
email, whatsapp, sms, call_task, task, wait, condition, notification
```

Condiciones de parada:

```text
email_reply, whatsapp_reply, meeting_booked, opportunity_created,
deal_won, manual_stop
```

El renderer solo sustituye variables permitidas como `{{lead.first_name}}`; nunca evalúa código. Cada ejecución tiene clave idempotente, reintentos y estado. Los pasos de mensajes reutilizan los transportes de Inbox; SMS usa una integración Twilio activa y números E.164.

```text
GET|POST          /api/v1/sequences
GET|PATCH|DELETE  /api/v1/sequences/{sequence}
POST              /api/v1/sequences/{sequence}/enrollments
GET               /api/v1/sequence-enrollments
GET               /api/v1/sequence-enrollments/{enrollment}
POST              /api/v1/sequence-enrollments/{enrollment}/pause
POST              /api/v1/sequence-enrollments/{enrollment}/resume
POST              /api/v1/sequence-enrollments/{enrollment}/stop
GET               /api/v1/sequence-enrollments/{enrollment}/executions
```

El scheduler ejecuta cada minuto:

```bash
php artisan sequences:dispatch-due
```

En producción deben estar activos el scheduler y los workers de las colas `sequences`, `integrations`, `automations` y `notifications`.

### Meeting Scheduler

Entidades: `MeetingType`, `AvailabilityRule`, `AvailabilityExclusion`, `MeetingBooking`, `MeetingParticipant` y `CalendarConnection`.

El cálculo de slots contempla:

- zona horaria por regla y zona solicitada por el visitante;
- horario laboral, vigencias y exclusiones;
- duración, intervalo, buffers, aviso mínimo y horizonte máximo;
- conflictos locales y free/busy externo;
- anfitrión fijo o round robin transaccional;
- cancelación y reprogramación mediante token de gestión cifrado;
- creación/vinculación de contacto y lead;
- sincronización asíncrona y reintentable.

Rutas públicas, siempre con UUID:

```text
GET  /api/v1/public/meetings/{publicId}
GET  /api/v1/public/meetings/{publicId}/availability
POST /api/v1/public/meetings/{publicId}/book
POST /api/v1/public/meeting-bookings/{bookingPublicId}/cancel
POST /api/v1/public/meeting-bookings/{bookingPublicId}/reschedule
```

Rutas administrativas:

```text
GET|POST          /api/v1/meeting-types
GET|PATCH|DELETE  /api/v1/meeting-types/{meetingType}
POST              /api/v1/meeting-types/{meetingType}/rotate-public-id
GET|POST           /api/v1/calendar-connections
GET|PATCH|DELETE   /api/v1/calendar-connections/{connection}
GET                /api/v1/meeting-bookings
GET                /api/v1/meeting-bookings/{booking}
POST               /api/v1/meeting-bookings/{booking}/cancel
POST               /api/v1/meeting-bookings/{booking}/sync
```

Los proveedores operativos son Google Calendar/Meet, Microsoft Calendar/Teams y Zoom. Google usa un ID de evento determinista para tolerar reintentos; Microsoft usa `transactionId`; Zoom conserva el ID externo devuelto por su API.

## Configuración de proveedores

Primero se crea y conecta `/api/v1/integrations`. Después se registra `/api/v1/calendar-connections` para el usuario anfitrión.

Ejemplo de conexión de calendario:

```json
{
  "user_id": 1,
  "integration_id": 10,
  "provider": "google",
  "external_calendar_id": "primary",
  "timezone": "America/Guayaquil",
  "settings": {
    "send_updates": "all"
  }
}
```

Para Teams usa `provider: microsoft`; para Zoom usa `provider: zoom`. La integración seleccionada debe estar en estado `active` y pertenecer al mismo tenant.

Twilio requiere:

```json
{
  "provider": "twilio",
  "name": "SMS principal",
  "credentials": {
    "account_sid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "auth_token": "<secret>"
  },
  "settings": {
    "from_number": "+15551234567"
  }
}
```

Después se crea un canal Inbox `sms` enlazado a esa integración. Los secretos nunca se devuelven en la API ni deben guardarse en Postman o Git.

## Permisos

```text
forms.view, forms.manage, form_submissions.view
lead_routing.view, lead_routing.manage
lead_scoring.view, lead_scoring.manage
sequences.view, sequences.manage, sequences.enroll
meetings.view, meetings.manage, calendar_connections.manage
```

## Pruebas con Postman

Importa los dos archivos de `docs/postman`, selecciona **Vantex CRM - Local** y ejecuta la colección en orden. La carpeta **08 - API-3 adquisición y agenda** crea y encadena un formulario, submission, routing, scoring, secuencia y reserva pública; sus scripts calculan una fecha futura y guardan automáticamente todos los UUID, IDs y tokens temporales.

Las integraciones reales de Google, Microsoft, Zoom y Twilio requieren credenciales de una cuenta sandbox propia. La carpeta API-3 usa un meeting type `custom`, por lo que el smoke test base no necesita secretos externos.

## Verificación automatizada

La cobertura focalizada está en:

```text
tests/Feature/Api/LeadAcquisitionTest.php
tests/Feature/Api/SequenceMeetingSchedulerTest.php
```

Incluye éxito, validación, permisos, aislamiento tenant, idempotencia, anti-spam, round robin, stop conditions, conflictos, reprogramación, cancelación y contratos HTTP de Google, Zoom y Twilio.
