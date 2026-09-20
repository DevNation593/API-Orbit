# Pruebas de Vantex CRM con Postman

## Archivos

- `Vantex CRM API.postman_collection.json`: flujo de humo encadenado.
- `Vantex CRM Local.postman_environment.json`: entorno para `http://localhost:8000`.

## Preparación

Desde el directorio del backend:

```bash
docker compose up -d --build
```

Comprueba que `http://localhost:8000/up` responda antes de ejecutar la colección.

## Ejecución en Postman

1. Importa los dos archivos JSON.
2. Selecciona el entorno **Vantex CRM - Local**.
3. Abre la colección **Vantex CRM API - Smoke Test**.
4. Pulsa **Run collection** y conserva el orden original.
5. Ejecuta la colección de forma secuencial, sin paralelismo, con un delay de 750 ms entre solicitudes para respetar el límite de API.

La primera solicitud genera un usuario y tenant únicos. Sus scripts guardan automáticamente el token, `tenant_id` y los IDs de los recursos creados.

La colección valida:

- registro, login, sesión y selección del tenant por ID;
- organizaciones, contactos, pipelines, leads, conversión, negocios, tareas y actividades;
- entidades y registros personalizados identificados por ID;
- usuarios, cambio de roles, invitaciones pendientes y revocación;
- relaciones seleccionables entre contactos y organizaciones;
- catálogo de integraciones, almacenamiento cifrado y rechazo de credenciales inválidas;
- Customer 360 con overview y timeline paginado;
- detección y fusión transaccional de contactos duplicados;
- búsqueda global tenant-safe y limitada por permisos;
- vistas guardadas con filtros y etiquetas reutilizables con asignación idempotente;
- inbox web chat, conversación, mensaje idempotente, asignación, estados y respuestas rápidas;
- preferencias y conteo del centro de notificaciones;
- formularios públicos, lead capture, routing y scoring;
- secuencias con inscripción y parada manual;
- agenda pública UUID con disponibilidad, reserva idempotente y cancelación por token;
- catálogo, lista de precios, CPQ, cotización decimal, aprobación, duplicación, revisión y PDF encolado;
- sucursales, equipos, cambio de rol, territorios, reglas, metas, forecast, snapshots, analítica y playbooks;
- filtros anidados, audiencias, consentimiento, bajas públicas, campañas programadas/canceladas y journeys versionados;
- agentes, categorías, colas, tickets idempotentes, comentarios internos/públicos, snapshots y transiciones SLA;
- auditoría y OpenAPI;
- respuestas 401, 403 y 422;
- revocación del token al cerrar sesión.

Cada ejecución conserva los datos creados para poder inspeccionarlos desde la API. Si Postman responde `429`, espera un minuto: autenticación está limitada a 10 solicitudes por minuto.

La solicitud **Rechazar conexión con token inválido** espera deliberadamente un `422`: confirma que una integración no se marca activa sin verificar primero al proveedor. Para probar una conexión exitosa, reemplaza el token de ejemplo por uno vigente del proveedor y cambia la aserción esperada a `200`.

La carpeta **06 - Customer 360 y productividad** es encadenada: crea un segundo contacto, detecta el duplicado, lo fusiona y reutiliza el contacto destino en overview, timeline, vistas guardadas y tags. Sus scripts administran `duplicate_contact_id`, `saved_view_id`, `tag_id` y `tag_assignment_id`; no es necesario copiarlos manualmente.

La carpeta **07 - Inbox y notificaciones** crea un inbox `web_chat`, una conversación y un mensaje sin necesitar credenciales externas. Guarda automáticamente `inbox_id`, `inbox_channel_id`, `conversation_id`, `message_id` y `canned_response_id`.

La carpeta **08 - API-3 adquisición y agenda** es completamente encadenada y tampoco necesita secretos externos. Publica un formulario, crea un lead/contact, lo enruta y puntúa, lo inscribe en una secuencia y prueba una reserva pública. Calcula una fecha futura y administra `form_public_id`, `api3_lead_id`, `sequence_enrollment_id`, `meeting_public_id`, `meeting_booking_public_id` y `meeting_manage_token` automáticamente.

La carpeta **09 - API-4 catálogo, CPQ, cotizaciones y aprobaciones** también es autocontenida. Verifica importes exactos como cadenas decimales, ausencia de `slug`, recálculo sin descuentos duplicados, aprobación declarativa, duplicación, revisión y encolado del PDF. Guarda automáticamente los IDs del catálogo, proceso, solicitud y cotizaciones.

La carpeta **10 - API-5 forecasting y performance comercial** crea una estructura de sucursal/equipo/territorio, cambia el rol del miembro mediante `PATCH`, asigna una organización por regla y crea una oportunidad verificando pipeline y etapa. Después crea una meta, consulta forecast y analítica, guarda un snapshot y ejecuta un playbook dos veces para comprobar que no duplica acciones. Administra automáticamente los 15 valores temporales e IDs agregados para esta fase.

Los flujos reales de Gmail, Microsoft, WhatsApp, Google Calendar/Meet, Microsoft Calendar/Teams, Zoom, Twilio y Vantex ERP no forman parte del runner automático porque requieren cuentas y secretos propios. Crea primero una integración y ejecuta `connect`; después registra el recurso de canal o `/calendar-connections` correspondiente. El envío real de una cotización también requiere una cuenta de email activa. Nunca guardes tokens reales dentro de la colección exportada.

La carpeta **11 - API-6 marketing, segmentos, consentimiento y journeys** agrega 35 solicitudes y 13 variables automáticas. Crea recursos propios después de autenticarse, prueba una baja pública idempotente, programa una campaña a 24 horas y la cancela, y comprueba que una inscripción conserva su versión al publicar otra. No conecta proveedores ni envía emails. Los refresh de segmentos/audiencias responden 202 y necesitan el worker `marketing`; no es necesario esperar su finalización para continuar el runner.

Antes de usarla, aplica la migración API-6 en tu entorno revisado y mantén activos el worker y el scheduler. Las 35 solicitudes también se verifican automáticamente con `php artisan test --filter=MarketingPostmanWorkflowTest` en SQLite aislado.

La carpeta **12 - API-7.1 tickets y SLA** contiene 34 solicitudes y nueve variables automáticas. Busca el agente del usuario autenticado y lo reutiliza; si no existe, lo registra antes de crear categoría, calendario, política y cola. Después crea un ticket `MEDIUM`, verifica el replay 200, cambia la prioridad sin alterar el snapshot, registra una nota `INTERNAL` y una respuesta `PUBLIC`, recorre espera/pausa/reapertura y termina en `CLOSED`. La respuesta pública solo documenta una comunicación ya realizada: el runner no crea mensajes ni envía email o WhatsApp.

El flujo no fuerza vencimientos ni necesita proveedores externos. Sus dos ramas —agente nuevo y existente— se ejecutan contra Laravel con `php artisan test --filter=SupportPostmanWorkflowTest`. Antes de usarla en un entorno desplegado, aplica `2026_09_09_000000_create_support_foundations.php` y mantén activos el scheduler y `php artisan queue:work redis --queue=support,notifications --tries=3 --timeout=120`.

La carpeta **13 - API-7.2 base de conocimiento** contiene 18 solicitudes autocontenidas. Configura una base pública, crea una categoría y etiqueta, crea cuatro revisiones de un artículo, valida que el snapshot público no cambie antes de republicar, lo archiva y lo deja en `DRAFT`. Las lecturas públicas usan UUID y no heredan Bearer ni `X-Tenant-ID`; no necesitan Portal ni secretos de terceros. Se valida con `php artisan test --filter=KnowledgePostmanWorkflowTest` sobre SQLite aislado. La carpeta general pasa a ser **14 - Seguridad y validaciones** y conserva sus solicitudes.

## URLs remotas

Duplica el entorno y cambia únicamente `base_url`, por ejemplo:

```text
https://api.example.com
```

No añadas `/api/v1`; la colección ya lo incluye.
