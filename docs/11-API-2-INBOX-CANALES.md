# 11 — API-2: Inbox, correo, WhatsApp y notificaciones

Fecha de cierre: 2026-09-02

## Alcance implementado

API-2 agrega una bandeja omnicanal tenant-safe sobre la arquitectura existente. Incluye inboxes y canales, conversaciones, participantes, mensajes, adjuntos, asignaciones históricas, estados, lectura por usuario, respuestas rápidas, etiquetas reutilizables, notificaciones y preferencias.

Los transportes operativos son `web_chat`, `email` y `whatsapp`. El catálogo reserva `sms`, `facebook`, `instagram`, `telegram` y `call`, pero la API rechaza el envío por esos canales hasta que exista un adaptador real; no se simula una entrega exitosa.

## Endpoints principales

Todos los endpoints autenticados requieren `Authorization: Bearer ...` y el tenant activo mediante `X-Tenant-ID` cuando el usuario tiene varias membresías.

| Área | Endpoints |
|---|---|
| Notificaciones | `GET /notifications`, `GET /notifications/unread-count`, `PATCH /notifications/{uuid}/read`, `PATCH /notifications/{uuid}/unread`, `PATCH /notifications/read-all`, `DELETE /notifications/{uuid}` |
| Preferencias | `GET /notification-preferences`, `PUT /notification-preferences` |
| Inboxes | `GET/POST /inboxes`, `GET/PATCH /inboxes/{id}`, `POST /inboxes/{id}/channels`, `PATCH/DELETE /inbox-channels/{id}` |
| Conversaciones | `GET/POST /conversations`, `GET /conversations/{id}`, `PATCH /conversations/{id}/assign`, `PATCH /conversations/{id}/status`, `PATCH /conversations/{id}/read` |
| Mensajes | `GET/POST /conversations/{id}/messages` |
| Respuestas rápidas | `GET/POST /canned-responses`, `PATCH/DELETE /canned-responses/{id}` |
| Cuentas de correo | `GET/POST /email/accounts`, `GET/PATCH /email/accounts/{id}`, acciones `connect`, `disconnect` y `sync` |
| Plantillas de correo | `GET/POST /email/templates`, `GET/PATCH/DELETE /email/templates/{id}` |
| WhatsApp | `GET/POST /whatsapp/accounts`, `GET/PATCH /whatsapp/accounts/{id}`, listado y sync de plantillas |
| Webhook Meta | `GET/POST /webhooks/whatsapp` (público y limitado por tasa) |

El contrato completo, filtros, payloads y errores está en `docs/openapi.yaml`.

## Flujo de mensajes

`POST /conversations/{id}/messages` crea primero un mensaje durable. El campo `client_message_id` y el header `Idempotency-Key` permiten reintentos seguros. Envíos inmediatos pasan a la cola `integrations`; un fallo conserva metadata anterior y agrega `delivery_error`.

Para programar un envío se agrega `scheduled_at` en ISO 8601 con una fecha futura. El mensaje queda en estado `scheduled`; `inbox:dispatch-scheduled` reclama de forma atómica los vencidos y recién entonces los entrega. Docker Compose ejecuta `php artisan schedule:work`, mientras Horizon procesa los jobs.

Los estados posibles son `scheduled`, `queued`, `sent`, `delivered`, `read`, `received` y `failed`. Los eventos se publican por el canal privado Reverb `tenants.{tenantId}.inbox`.

## Correo

La lógica CRM depende de `EmailProviderInterface`, no de un proveedor concreto:

- Google envía MIME RFC-compatible codificado en base64url y sincroniza inicialmente el inbox; después usa `historyId`.
- Microsoft envía mediante Graph `sendMail` y sincroniza incrementalmente con enlaces delta opacos.
- SMTP envía con Symfony Mailer. IMAP recibe por UID cuando `ext-imap` está instalada.

Los cursores de sincronización, credenciales y tokens se cifran con el cifrado de atributos de Laravel. Los cursores URL aceptan únicamente hosts HTTPS oficiales para evitar SSRF. La sincronización puede solicitarse con `POST /email/accounts/{id}/sync` y también corre cada cinco minutos mediante `inbox:sync-email`.

Los mensajes entrantes se deduplican por cuenta/canal e ID externo, reconstruyen conversaciones por thread, enlazan contactos por correo normalizado, sanitizan HTML y almacenan adjuntos en el disco configurado. Gmail y Microsoft limitan cada adjunto a 25 MiB por defecto.

El tracking de apertura y clic es opcional por cuenta. Solo se guarda el hash del token público; los destinos quedan cifrados. El redirect acepta exclusivamente URL `http` o `https`, y scripts/event handlers se eliminan de firmas, plantillas y cuerpos HTML.

Referencias de contrato: [Gmail `messages.send`](https://developers.google.com/workspace/gmail/api/guides/sending), [Gmail history](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list), [Microsoft Graph `sendMail`](https://learn.microsoft.com/graph/api/user-sendmail?view=graph-rest-1.0) y [Microsoft Graph delta](https://learn.microsoft.com/graph/api/message-delta?view=graph-rest-1.0).

## WhatsApp Cloud API

La integración requiere `access_token`, `phone_number_id`, `app_secret`, `settings.api_version` y, para plantillas, `business_account_id`. El `verify_token` entregado al crear `WhatsAppAccount` se conserva únicamente como SHA-256 y nunca vuelve en una respuesta.

El webhook GET valida el challenge. El POST identifica la cuenta por `phone_number_id`, comprueba `X-Hub-Signature-256` con HMAC-SHA256 sobre el cuerpo crudo y persiste el evento cifrado antes de procesarlo. La clave de deduplicación evita reprocesar el mismo payload. Se soportan texto, imagen, video, documento, audio, plantillas, respuestas y estados `sent`, `delivered`, `read` y `failed`.

`POST /whatsapp/accounts/{id}/templates/sync` importa hasta diez páginas de plantillas oficiales y actualiza estado, idioma, categoría y componentes. Los enlaces de paginación también se restringen a `graph.facebook.com` y a la versión configurada.

## Notificaciones y autorización

El centro de notificaciones aísla incluso al mismo usuario cuando pertenece a varios tenants. Las preferencias se definen por combinación evento/canal y admiten wildcard `*`. `in_app` y `email` son canales operativos; push, WhatsApp y SMS permanecen declarados como no operativos para notificaciones hasta implementar sus drivers específicos.

Permisos agregados:

- `notifications.view`, `notifications.manage`;
- `inboxes.view`, `inboxes.manage`;
- `conversations.view`, `conversations.reply`, `conversations.assign`;
- `email_accounts.view`, `email_accounts.manage`;
- `email_templates.view`, `email_templates.manage`;
- `whatsapp.view`, `whatsapp.manage`.

Policies, reglas `exists` limitadas por tenant, scopes Eloquent y RLS opcional protegen todas las relaciones.

## Operación

Servicios mínimos en producción:

```bash
php artisan horizon
php artisan schedule:work
php artisan reverb:start
```

La imagen PHP 8.4 instala IMAP desde PECL, porque dejó de formar parte del núcleo de PHP. En un PHP local sin esa extensión, Gmail y Microsoft continúan funcionando y el sync IMAP devuelve un error explícito. Véase [instalación oficial de IMAP](https://www.php.net/manual/es/imap.installation.php).

Las conexiones de infraestructura siguen usando exclusivamente `DATABASE_URL` y `REDIS_URL`. Las credenciales de proveedores son registros cifrados por tenant, no variables globales compartidas.

## Pruebas

Las suites focalizadas son:

```bash
php artisan test tests/Feature/Api/InboxNotificationTest.php
php artisan test tests/Feature/Api/ChannelIntegrationTest.php
php artisan test tests/Feature/Api/EmailSyncSchedulingTest.php
```

Cubren aislamiento tenant/RBAC, lectura y preferencias, ciclo de conversaciones, idempotencia, Gmail/Graph, SMTP desacoplado, tracking, adjuntos, programación, firmas WhatsApp, estados y plantillas. La carpeta `07 - Inbox y notificaciones` de la colección Postman permite probar el núcleo sin credenciales externas.

## Validación de cierre

Resultado reproducible al 2026-09-02:

- 18 pruebas focalizadas de API-1/API-2 con 285 aserciones;
- 42 pruebas completas con 392 aserciones, sin regresiones;
- Pint y `php -l` correctos sobre 303 archivos PHP propios;
- migraciones verificadas con creación limpia, rollback de las tres migraciones API-2 y reaplicación;
- OpenAPI parseado con 107 paths, 31 schemas, 442 referencias locales válidas y cero errores de parámetros;
- colección Postman parseada con 10 carpetas y 33 variables.
