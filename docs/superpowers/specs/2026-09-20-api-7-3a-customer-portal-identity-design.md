# API-7.3A — Diseño de identidad y acceso del Portal de Clientes

Fecha: 2026-09-20 (America/Guayaquil).
Estado: diseño aprobado durante la conversación del 2026-09-20; esta especificación no acredita implementación.

## 1. Objetivo y división de API-7.3

API-7.3 completará el Portal de Clientes solicitado por el documento maestro sin exponer directamente las APIs internas del CRM. Para limitar el riesgo y permitir entregas verificables, se divide en tres bloques:

- **API-7.3A — Identidad y acceso:** configuración del portal, invitaciones, cuentas de clientes, sesiones, recuperación de contraseña, perfil y lectura autenticada de conocimiento `CUSTOMER`.
- **API-7.3B — Soporte y documentos:** tickets visibles por el contacto, comentarios públicos y documentos autorizados.
- **API-7.3C — Relación comercial:** cotizaciones, reuniones y datos de contratos, facturas, pagos y suscripciones provenientes de las integraciones ERP correspondientes.

Esta especificación cubre exclusivamente API-7.3A. Incluye:

- una configuración de Portal de Clientes por tenant;
- una identidad externa `PortalUser`, separada de `User` y vinculada a un `Contact`;
- alta solo por invitación, revocación y caducidad;
- inicio y cierre de sesión mediante tokens Sanctum exclusivos del portal;
- recuperación segura de contraseña;
- consulta y edición acotada del perfil del contacto;
- lectura autenticada de artículos publicados `PUBLIC` y `CUSTOMER`;
- permisos internos, auditoría, aislamiento multiempresa, rate limiting, pruebas, OpenAPI y Postman.

No se usarán slugs. Los IDs numéricos de tenant, portal, cuenta, contacto y artículo continúan siendo internos; los UUID generados por el servidor se usan como localizadores públicos opacos. Los IDs de categorías y etiquetas conservan únicamente su uso como vocabulario de filtro definido en API-7.2. PostgreSQL continúa configurado solo mediante `DATABASE_URL` y Redis solo mediante `REDIS_URL`; este bloque no agrega variables de conexión.

## 2. Alternativas consideradas

### Alternativa elegida: identidad externa por tenant y contacto

Cada tenant tiene como máximo un `CustomerPortal`, identificado externamente por un UUID `public_id`. Cada cuenta `PortalUser` pertenece obligatoriamente a ese tenant y a un `Contact` del mismo tenant. La autenticación, tokens y middleware del portal son distintos de los usados por los usuarios internos.

Esta alternativa mantiene un límite de seguridad explícito entre colaboradores y clientes, evita convertir los roles internos en permisos de portal y permite que un mismo correo exista de manera legítima en tenants diferentes.

### Alternativa descartada: reutilizar `User`, roles y membresías internas

Reutilizar usuarios internos reduciría modelos, pero mezclaría dos tipos de principal con permisos y superficies diferentes. También abriría el riesgo de que un cliente acceda a rutas internas por una asignación accidental de rol o una colisión de identificadores.

### Alternativa descartada: cuenta global con membresías en varios portales

Una cuenta global simplificaría el cambio entre organizaciones para algunos contactos, pero añadiría selección de tenant, recuperación de contraseña ambigua y una relación de membresías que el alcance actual no necesita. En API-7.3A cada cuenta es local al tenant.

### Alternativa descartada: seleccionar tenant en login mediante body o slug

Aceptar `tenant_id` en el payload expone identificadores internos y aumenta el riesgo de confusión entre tenants. Los slugs ya fueron eliminados por decisión del usuario. El UUID del portal, incluido en la URL, es el único localizador externo.

## 3. Arquitectura y separación de principales

Se conserva el monolito modular Laravel. Las rutas se declararán en `routes/customer_portal.php`, requerido bajo `/api/v1`.

Habrá tres superficies independientes:

- **administración interna:** `/api/v1/customer-portal/*`, con `auth:sanctum`, principal exacto `App\Models\User`, `tenant.context`, `X-Tenant-ID` y permiso `portal.manage`;
- **autenticación externa:** invitaciones y `/api/v1/portal/{portalPublicId}/auth/*`, sin `X-Tenant-ID`, con resolución controlada del UUID antes de autenticar;
- **portal autenticado:** `/api/v1/portal/{portalPublicId}/*`, con token Sanctum de habilidad `portal` y middleware de resolución y principal externo.

El pipeline externo se divide para respetar el orden real de middleware de Laravel:

1. `portal.context` valida el UUID, consulta el localizador mínimo no sujeto a RLS, establece `TenantContext`, carga el portal tenant-scoped y garantiza la limpieza del contexto en `finally`;
2. `auth:sanctum` resuelve el token con el tenant ya disponible;
3. `portal.principal` exige que el principal sea exactamente `PortalUser`, que el token tenga habilidad `portal` y que portal, cuenta, contacto y tenant coincidan; además rechaza cuentas suspendidas y contactos eliminados.

Las rutas de login y solicitud/reset de contraseña usan `portal.context` sin los dos middleware autenticados. Las rutas protegidas usan, en ese orden, `portal.context`, `auth:sanctum` y `portal.principal`. Las invitaciones consultan primero un localizador por hash de token, establecen el tenant encontrado y cargan después la invitación tenant-scoped.

No se intentará consultar directamente una tabla con RLS forzado antes de conocer el tenant. Los localizadores contienen únicamente las claves opacas y referencias necesarias para establecer el contexto; todas las decisiones de estado y autorización se toman después contra las entidades tenant-scoped.

Se endurecerá `ResolveTenant` para aceptar únicamente una instancia exacta de `App\Models\User` antes de consultar `tenant_user`. Un `PortalUser` nunca podrá ser interpretado como usuario interno aunque su ID numérico coincida con el de un `User`. De forma simétrica, las rutas del portal rechazan un `User` interno aunque presente un token Sanctum válido.

Los controladores se limitarán a validación, autorización y serialización. Los casos de uso se concentrarán en servicios transaccionales:

- `CustomerPortalService`: configuración, estado del portal y administración de cuentas;
- `PortalInvitationService`: emisión, consulta segura, aceptación y revocación;
- `PortalAuthService`: login, logout, recuperación y restablecimiento de contraseña;
- `PortalProfileService`: proyección y edición permitida del contacto;
- `PublishedKnowledgeQuery`: consulta compartida de snapshots publicados con una lista explícita de visibilidades permitidas;
- `PortalKnowledgeService`: adaptación autenticada que autoriza `PUBLIC` y `CUSTOMER`.

`PortalUser` será un autenticable independiente con `HasApiTokens` y `Notifiable`; no heredará roles, membresías ni helpers de autorización de `User`.

No se requieren proveedores externos, nuevos workers ni tráfico de red para completar API-7.3A. Las notificaciones usarán la infraestructura de correo ya existente y podrán probarse con `Notification::fake()`.

## 4. Modelo de datos

Todas las tablas del portal incluyen timestamps, claves foráneas, índices y restricciones compatibles con SQLite para desarrollo y PostgreSQL para producción. Las entidades de negocio incluyen `tenant_id` y participan en RLS opcional siguiendo el patrón del proyecto. Dos tablas de localización mínimas se excluyen deliberadamente de RLS para resolver el tenant antes de acceder a esas entidades, igual que el patrón existente de ingreso de webhooks.

### 4.1 `customer_portals`

Una fila por tenant:

| Campo | Regla |
| --- | --- |
| `id` | PK interna numérica. |
| `tenant_id` | FK única a `tenants`; una configuración por tenant. |
| `public_id` | UUID globalmente único, generado en servidor, inmutable y no aceptado en payloads. |
| `title` | Nombre visible, de 1 a 120 caracteres. |
| `is_active` | Habilita autenticación y uso del portal. |
| `settings` | JSON con `welcome_message` anulable (máximo 500 caracteres) y `support_email` anulable (correo RFC, máximo 190); no contiene secretos ni reglas de autorización. |

No existe columna `slug`. Al crear, `is_active` es `false` salvo que el administrador lo active expresamente. Desactivar el portal revoca sus tokens activos dentro de la misma operación y bloquea nuevos accesos sin borrar cuentas ni historial.

### 4.2 `portal_users`

Identidad autenticable externa:

| Campo | Regla |
| --- | --- |
| `id` | PK interna numérica. |
| `tenant_id` | FK obligatoria al tenant. |
| `contact_id` | FK obligatoria a un contacto del mismo tenant. |
| `email` | Correo normalizado en minúsculas; no es globalmente único. |
| `password` | Hash producido por `Hash`, nunca serializado. |
| `status` | `ACTIVE` o `SUSPENDED`. |
| `email_verified_at` | Se fija al aceptar una invitación válida. |
| `last_login_at` | Último login correcto. |

Restricciones únicas: `(tenant_id, contact_id)` y `(tenant_id, email)`. La aplicación y las FKs compuestas o validaciones equivalentes garantizan que contacto y cuenta pertenezcan al mismo tenant. No hay roles ni permisos internos asociados a `PortalUser`.

### 4.3 `portal_invitations`

Invitación de un contacto:

| Campo | Regla |
| --- | --- |
| `tenant_id`, `contact_id` | Destinatario tenant-safe. |
| `email` | Snapshot normalizado del correo del contacto al emitir. |
| `invited_by` | FK al `User` interno que inició la invitación. |
| `token_hash` | SHA-256 del token aleatorio; único. El token plano nunca se almacena. |
| `status` | `PENDING`, `ACCEPTED`, `REVOKED` o `EXPIRED`. |
| `expires_at` | Siete días desde la emisión. |
| `accepted_at` | Fecha de consumo exitoso. |

Solo puede existir una invitación `PENDING` utilizable por contacto. Reinvitar revoca la pendiente anterior dentro de una transacción y crea un token nuevo. La aceptación es de un solo uso y bloquea la fila para evitar carreras.

La solicitud interna recibe `contact_id`, no un correo libre. El servidor toma el correo actual del contacto, lo normaliza y rechaza con 422 contactos sin correo válido. Si ya existe una cuenta activa o suspendida para ese contacto, responde 409 y no crea otra identidad.

### 4.4 `portal_password_reset_tokens`

Almacenamiento propio para evitar la ambigüedad del reset estándar indexado solo por correo:

| Campo | Regla |
| --- | --- |
| `tenant_id`, `portal_user_id` | Cuenta destinataria y tenant. |
| `token_hash` | SHA-256 único; nunca se persiste el token plano. |
| `expires_at` | Sesenta minutos desde la emisión. |
| `used_at` | Marca de consumo único. |
| `created_at` | Permite invalidar solicitudes anteriores y aplicar limpieza. |

Solicitar un reset invalida tokens pendientes anteriores de esa cuenta. Completarlo cambia la contraseña y revoca todos sus tokens Sanctum en una sola transacción.

### 4.5 Localizadores de ingreso

`customer_portal_locators` contiene `public_id` UUID único, `portal_id` único y `tenant_id`, con timestamps. `portal_invitation_locators` contiene `token_hash` SHA-256 único, `invitation_id` único y `tenant_id`, con timestamps. No almacenan correos, nombres, estados, configuración, tokens planos ni datos del contacto.

Estas dos tablas no usan el trait tenant-scoped ni RLS porque su única función es establecer `TenantContext`. El servicio consulta por coincidencia exacta de UUID/hash, establece el tenant y vuelve a cargar `CustomerPortal` o `PortalInvitation` comprobando simultáneamente su ID, tenant y clave opaca. Un localizador huérfano o inconsistente se trata como 404.

La entidad y su localizador se crean, actualizan o eliminan en la misma transacción. Las FKs eliminan localizadores huérfanos al borrar su entidad durante un rollback de migración o una eliminación de tenant. No se exponen modelos ni endpoints CRUD para estas tablas.

Índices y restricciones mínimas:

- `customer_portals`: únicos `tenant_id`, `public_id` y el par de soporte `(id, tenant_id)`;
- `portal_users`: únicos `(tenant_id, contact_id)`, `(tenant_id, email)` y `(id, tenant_id)`, más índice de listado por tenant/estado;
- `portal_invitations`: `token_hash` único e índices por tenant/contacto/estado/caducidad;
- `portal_password_reset_tokens`: `token_hash` único e índice por tenant/usuario/uso/caducidad;
- localizadores: clave opaca única, entidad única e índice por tenant.

La migración agrega a `contacts` el índice único de soporte `(id, tenant_id)` y usa FKs compuestas para que `portal_users` e invitaciones no puedan apuntar a un contacto de otro tenant. El mismo patrón relaciona resets y localizadores con sus entidades. `audit_logs.portal_user_id` usa una FK nullable simple con `SET NULL` para preservar el log si desaparece el actor; `AuditService` exige que actor y log compartan tenant, evitando una FK compuesta cuyo `SET NULL` intentaría anular también `tenant_id`. Las validaciones de servicio y RLS se conservan como capas adicionales, no como sustitutos de la integridad de base de datos.

### 4.6 Auditoría

`audit_logs` añadirá `portal_user_id` anulable con FK a `portal_users`. `user_id` continuará reservado para el actor interno. Cada evento tendrá como máximo uno de los dos actores autenticados; los procesos sin principal pueden conservar ambos en `null`.

`AuditService` se volverá consciente del tipo real de principal. No copiará `auth()->id()` ciegamente a `user_id`, porque IDs de `User` y `PortalUser` pueden coincidir. Contraseñas, tokens, hashes, URLs de invitación/reset y cuerpos sensibles nunca se guardan en auditoría.

## 5. Configuración y administración interna

Todas estas rutas requieren un `User` interno autenticado, `X-Tenant-ID`, membresía activa y `portal.manage`:

```text
GET    /api/v1/customer-portal/settings
PUT    /api/v1/customer-portal/settings
GET    /api/v1/customer-portal/users
PATCH  /api/v1/customer-portal/users/{portalUser}
GET    /api/v1/customer-portal/invitations
POST   /api/v1/customer-portal/invitations
DELETE /api/v1/customer-portal/invitations/{invitation}
```

`GET /settings` devuelve 404 mientras el tenant no tenga portal. `PUT /settings` es idempotente: recibe `title` e `is_active`, ambos obligatorios, y un objeto `settings` opcional limitado a `welcome_message` y `support_email`; crea la configuración con UUID generado por servidor o reemplaza esos valores. Claves desconocidas dentro de `settings` devuelven 422. La operación devuelve 201 al crear y 200 al actualizar. El UUID y el tenant nunca son editables. Las listas de usuarios e invitaciones también devuelven 404 hasta que exista la configuración.

La lista de cuentas permite filtros acotados por `q`, `status` y `contact_id`, paginación de 1 a 100 y orden por `created_at`, `last_login_at` o `email`. La respuesta omite contraseña, tokens y datos privados del contacto que no sean necesarios para administrar el acceso.

`PATCH /users/{portalUser}` solo cambia `status`. Suspender revoca todas las sesiones de esa cuenta dentro de la transacción; reactivar no genera automáticamente un token ni cambia la contraseña. Un ID de otro tenant responde 404.

La lista de invitaciones filtra por estado, contacto y fechas. `POST /invitations` recibe únicamente `contact_id`; no acepta IDs de tenant, correo, expiración, token, URL ni texto libre. Requiere que la configuración exista y esté activa; de otro modo responde 409. `DELETE` revoca una invitación pendiente y es idempotente para una ya revocada; una aceptada no se borra ni vuelve a utilizar.

El permiso nuevo `portal.manage` se agrega al catálogo y se concede, siguiendo el patrón existente, a roles de sistema y a roles que ya poseen `settings.manage`. No se asigna automáticamente a los demás roles personalizados. Este permiso no da acceso a recursos futuros de API-7.3B o API-7.3C.

## 6. Invitación y activación

La consulta de una invitación no requiere sesión:

```text
GET  /api/v1/portal/invitations/{token}
POST /api/v1/portal/invitations/{token}/accept
```

`GET` calcula SHA-256 del token recibido, localiza la invitación sin revelar su valor y devuelve únicamente `portal_public_id`, nombre del portal, correo enmascarado, nombre del contacto, fecha de expiración y estado utilizable. No entrega `tenant_id`, `contact_id`, `invited_by` ni IDs internos.

`POST /accept` recibe `password`, `password_confirmation` y un `device_name` opcional de máximo 120 caracteres, cuyo valor predeterminado es `portal-web`. Los tokens de invitación y reset son valores CSPRNG de 64 caracteres antes de aplicar SHA-256. Dentro de una transacción:

1. bloquea la invitación;
2. comprueba estado pendiente, caducidad, portal activo, contacto existente y tenant coherente;
3. vuelve a validar que el correo normalizado actual del contacto coincida con el snapshot invitado;
4. crea exactamente un `PortalUser` activo con correo verificado;
5. marca la invitación como aceptada;
6. emite después del commit un token Sanctum con habilidad exclusiva `portal`.

Una restricción única evita cuentas duplicadas aun en concurrencia. Un token aceptado o revocado responde 409; uno caducado responde 410 y se marca `EXPIRED`; un token inexistente o mal formado responde 404. Ninguno de estos errores revela otros datos del tenant.

La respuesta 201 de aceptación incluye `access_token`, `token_type=Bearer`, `expires_at`, `portal_public_id` y la misma proyección de cuenta/contacto que `GET /me`. La respuesta de login usa ese mismo contrato. Ninguna otra respuesta vuelve a mostrar el bearer token.

La notificación de invitación se envía después de confirmar la transacción. Un fallo de correo no revierte la invitación; la respuesta interna incluye `meta.notification_sent=false` para que el administrador pueda reintentar. La URL/token plano solo puede incluirse en respuestas cuando el entorno sea `local` o `testing`; nunca en producción ni logs.

## 7. Sesiones y recuperación de contraseña

Las rutas se localizan por UUID del portal y nunca requieren ni aceptan `X-Tenant-ID`:

```text
POST /api/v1/portal/{portalPublicId}/auth/login
POST /api/v1/portal/{portalPublicId}/auth/forgot-password
POST /api/v1/portal/{portalPublicId}/auth/reset-password
POST /api/v1/portal/{portalPublicId}/auth/logout
```

### Login

El payload contiene `email`, `password` y `device_name` opcional, con valor predeterminado `portal-web` y máximo 120 caracteres. El correo se normaliza antes de buscarlo dentro del tenant resuelto. Un UUID de portal desconocido responde 404; cuenta o contraseña inválidas comparten un mensaje genérico 401. Una cuenta conocida suspendida o un portal desactivado responde 403 sin emitir token.

Cada login correcto crea un token Sanctum con habilidad `portal`, nombre de dispositivo y expiración absoluta de 30 días, y actualiza `last_login_at`. Si ya existe un token de esa cuenta con el mismo nombre de dispositivo, se reemplaza para no acumular sesiones indistinguibles. Los tokens del portal no reciben habilidades internas.

### Logout

Requiere el pipeline protegido completo (`portal.context`, `auth:sanctum`, `portal.principal`) y revoca únicamente el token actual. Logout con token ausente o inválido responde 401. Suspensión, desactivación del portal y reset de contraseña revocan todos los tokens afectados.

### Recuperación

`forgot-password` recibe un correo y, una vez resuelto un UUID de portal existente, siempre responde 200 con el mismo mensaje aunque el portal esté inactivo o la cuenta no exista, para evitar enumeración. Solo genera y notifica un token si portal y cuenta están activos. El token plano no se persiste.

`reset-password` recibe `email`, `token`, `password` y confirmación. Valida el hash, tenant, cuenta, vigencia y uso único bajo bloqueo. En éxito cambia la contraseña, marca el token como usado y revoca todas las sesiones. Token inválido o cuenta no coincidente devuelve un 422 genérico; token válido pero caducado devuelve 410 sin revelar más datos.

Las contraseñas usan las reglas centrales del proyecto y `Hash`; ningún servicio compara o registra hashes manualmente.

## 8. Perfil autenticado

```text
GET   /api/v1/portal/{portalPublicId}/me
PATCH /api/v1/portal/{portalPublicId}/profile
```

Ambas rutas requieren el pipeline protegido completo. `GET /me` devuelve la identidad del portal, datos básicos de la cuenta y una proyección del contacto enlazado. No devuelve IDs internos, tenant, propietario interno, notas, campos personalizados privados, auditoría ni información de otras relaciones.

`PATCH /profile` solo acepta:

- `first_name`: al enviarse, no vacío y máximo 120 caracteres;
- `last_name`: anulable, máximo 120 caracteres;
- `phone`: anulable, máximo 50 caracteres.

Estos campos actualizan directamente `Contact.first_name`, `Contact.last_name` y `Contact.phone`; no se introduce un nombre duplicado en `portal_users`. El correo no se puede modificar en API-7.3A porque requiere un flujo separado de verificación. También se rechazan `tenant_id`, `contact_id`, `email`, estado, contraseña, propietario, organización, timestamps y cualquier campo controlado por servidor.

`GET /me` usa `portal_users.email` como dirección verificada de acceso. Un cambio administrativo posterior en `contacts.email` no cambia silenciosamente la identidad de login ni transfiere acceso a una dirección sin verificar; esa sincronización queda para el flujo futuro de cambio de correo.

La edición actualiza el contacto vinculado dentro del tenant de la sesión y registra únicamente los nombres de campos modificados y valores no sensibles. Un contacto eliminado o que deje de pertenecer al tenant invalida el acceso con 403 y no se recrea automáticamente.

## 9. Base de Conocimiento para clientes

El portal autenticado agrega lectura de conocimiento bajo su propio límite de seguridad:

```text
GET /api/v1/portal/{portalPublicId}/knowledge
GET /api/v1/portal/{portalPublicId}/knowledge/categories
GET /api/v1/portal/{portalPublicId}/knowledge/articles
GET /api/v1/portal/{portalPublicId}/knowledge/articles/{articlePublicId}
```

Estas rutas requieren un `PortalUser` activo del mismo portal. Solo consultan `published_version_id` de artículos con estado `PUBLISHED` y visibilidad del snapshot publicado en `PUBLIC` o `CUSTOMER`. Nunca entregan `INTERNAL`, borradores, revisiones no publicadas, artículos archivados ni contenido de otro tenant.

La configuración `KnowledgeBase.is_public` controla únicamente la lectura anónima existente. Un cliente autenticado puede consultar conocimiento `PUBLIC` y `CUSTOMER` aunque `is_public=false`, siempre que la base exista y el portal esté activo.

La lógica se extraerá a `PublishedKnowledgeQuery` con un conjunto de visibilidades obligatorio:

- `PublicKnowledgeService` pasa exactamente `['PUBLIC']` y conserva su comportamiento actual;
- `PortalKnowledgeService` pasa exactamente `['PUBLIC', 'CUSTOMER']`.

No habrá un valor predeterminado permisivo. La consulta siempre exige snapshot publicado y tenant explícito. Las proyecciones, filtros, paginación, sanitización y UUID de artículo conservan el contrato de API-7.2. Solo los IDs tenant-locales de categoría/etiqueta se conservan como vocabulario de filtro; no se exponen IDs numéricos de tenant, portal, cuenta, contacto, artículo, autor o versión, ni se aceptan slugs.

## 10. Autorización, rate limiting y auditoría

### Matriz de principal

| Superficie | Principal permitido | Contexto |
| --- | --- | --- |
| API interna existente | `User` | Membresía + `X-Tenant-ID` + permiso interno. |
| Administración del portal | `User` | Membresía + `X-Tenant-ID` + `portal.manage`. |
| Invitación | Ninguno | Token opaco hasheado. |
| Login/reset del portal | Ninguno | UUID del portal; sin tenant libre. |
| Portal autenticado | `PortalUser` | Token con habilidad `portal` + coincidencia portal/tenant/contacto. |

La aplicación comprobará la clase del principal, no solo `auth()->id()`, `tokenCan()` o la existencia de un ID. Los escenarios de IDs coincidentes entre tablas son casos de prueba obligatorios.

Se definen limitadores separados:

- login: combinación de UUID del portal, hash SHA-256 del correo normalizado e IP;
- solicitud de reset: UUID, hash SHA-256 del correo normalizado e IP;
- consulta/aceptación de invitación: hash seguro del token e IP;
- API autenticada: `portal_user_id` e IP.

Los valores concretos se configuran centralmente. Como contrato inicial: 5 logins por minuto, 3 solicitudes de reset por hora, 20 consultas/aceptaciones de invitación por minuto y 120 solicitudes autenticadas por minuto. Respuestas limitadas usan 429 y no revelan existencia de cuentas.

Eventos mínimos de auditoría:

- `portal.settings.created`, `portal.settings.updated`, `portal.settings.disabled`;
- `portal.user.invited`, `portal.invitation.revoked`, `portal.invitation.accepted`;
- `portal.user.suspended`, `portal.user.reactivated`;
- `portal.auth.login`, `portal.auth.logout`;
- `portal.password_reset.requested`, `portal.password_reset.completed`;
- `portal.profile.updated`.

Los eventos internos registran `user_id`; los eventos autenticados externos, `portal_user_id`. El reset solicitado para un correo inexistente puede producir una métrica sin correo ni actor, pero no una auditoría que permita inferir la cuenta. Los campos existentes de IP y user agent se recortan y tratan como datos operativos.

## 11. Errores, concurrencia y respuestas

Las respuestas conservan el envoltorio `{data, meta}` y el formato uniforme de errores del proyecto.

| Código | Uso principal |
| --- | --- |
| 200 | Lecturas, actualizaciones, login/logout, reset solicitado y acciones idempotentes. |
| 201 | Primera configuración, invitación y aceptación que crea cuenta/sesión. |
| 401 | Credenciales genéricamente inválidas o token de sesión ausente/inválido. |
| 403 | Portal desactivado, cuenta suspendida, principal de tipo incorrecto o permiso interno ausente. |
| 404 | UUID/token/recurso inexistente, ajeno al tenant o no visible. |
| 409 | Cuenta existente, invitación ya consumida/revocada, duplicado o transición incompatible. |
| 410 | Invitación o reset auténtico pero caducado. |
| 422 | Payload inválido, contacto sin correo o reset inválido genérico. |
| 429 | Rate limit excedido. |

No se diferencian por mensaje los casos que permitirían enumerar correos o tenants. Los bindings internos se acotan por tenant; las rutas externas usan UUID o tokens opacos.

Las operaciones de aceptar invitación, reinvitar, suspender, desactivar portal y restablecer contraseña usan transacciones, `lockForUpdate` donde corresponda y restricciones únicas como segunda defensa. El envío de notificaciones ocurre después del commit. Si el commit falla no se envía una URL utilizable.

## 12. Pruebas y criterios de aceptación

El desarrollo seguirá RED–GREEN–REFACTOR. SQLite aislado cubrirá la suite local y PostgreSQL desechable en CI verificará RLS, restricciones, índices y comportamiento específico del motor. Nunca se ejecutarán migraciones destructivas contra la `DATABASE_URL` real.

Casos obligatorios:

1. Configuración única por tenant, UUID generado por servidor, inmutable y ausencia total de slugs.
2. Permiso `portal.manage`, asignación solo a roles de sistema o con `settings.manage`, y rechazo de los demás roles sin concesión explícita.
3. Invitación usando el correo del contacto, revocación, reinvitación, expiración, consumo único y aceptación concurrente.
4. Creación tenant-safe de `PortalUser`, unicidad por contacto/correo dentro del tenant y posibilidad del mismo correo en tenants distintos.
5. Login, reemplazo por dispositivo, expiración de 30 días, logout del token actual y revocación total al suspender, desactivar o restablecer contraseña.
6. Recuperación con respuesta no enumerable, hash de un solo uso, caducidad y concurrencia segura.
7. Colisión deliberada entre IDs de `User` y `PortalUser`: ninguno puede cruzar a la superficie del otro ni ser atribuido al actor incorrecto.
8. Cruces de UUID del portal, token, cuenta, contacto y recursos entre al menos dos tenants responden sin fuga de datos.
9. Perfil: solo campos permitidos; correo, tenant, contacto, estado y campos internos son rechazados.
10. Conocimiento: `PUBLIC` y `CUSTOMER` publicados visibles; `INTERNAL`, borrador, revisión nueva no publicada y archivado ocultos.
11. Regresión de conocimiento público: `/public/knowledge` continúa ocultando `CUSTOMER`, incluso después de extraer la consulta común.
12. Portal autenticado puede leer conocimiento permitido cuando `KnowledgeBase.is_public=false`; un anónimo no puede.
13. Rate limits independientes, mensajes genéricos, redacción de logs/auditoría y actor correcto en `audit_logs`.
14. Migraciones reversibles, FKs, restricciones, índices, RLS opcional y bootstrap correcto mediante localizadores mínimos sin RLS; rollback/reaplicación exclusivamente sobre bases desechables.
15. OpenAPI sin referencias rotas, tabla de rutas Laravel alineada y suite completa sin regresiones.
16. Flujo Postman autocontenido: configurar portal, invitar contacto, aceptar, login, consultar/editar perfil, leer artículo `CUSTOMER`, solicitar/completar reset, volver a iniciar sesión, suspender y comprobar revocación.

Las pruebas no envían correos reales ni hacen tráfico externo. Usan reloj controlado, notificaciones falsas y tokens conocidos únicamente en entorno de prueba.

## 13. Documentación y entrega

La implementación actualizará:

- una guía `docs/18-API-7-3A-CUSTOMER-PORTAL-IDENTITY.md` con arquitectura, seguridad, uso y ejemplos;
- `docs/openapi.yaml` con administración, invitaciones, auth, perfil y conocimiento autenticado;
- la colección y guía Postman con variables capturadas automáticamente, sin depender de correo real;
- `README.md` y `docs/09-GAP-ANALYSIS.md`, marcando únicamente API-7.3A como terminado.

La colección agregará una carpeta dedicada a API-7.3A y conservará el orden de las carpetas posteriores. Debe crear sus propios datos, capturar `portal_public_id`, token de invitación de testing, token de portal y UUID de artículo, y limpiar o invalidar la sesión al finalizar.

API-7.3A solo se considerará terminado con migraciones, modelos, middleware, requests, servicios, notificaciones, policies/autorización, endpoints, auditoría, pruebas y documentación coherentes. No requiere cambios de Vercel ni intenta resolver su error actual, por decisión expresa del usuario.

## 14. Fuera de alcance y revisión de consistencia

Quedan fuera de API-7.3A:

- registro público, OAuth social, 2FA y cambio de correo;
- frontend del portal y personalización visual avanzada;
- tickets, comentarios y documentos de API-7.3B;
- cotizaciones, reuniones, contratos, facturas, pagos y suscripciones de API-7.3C;
- Customer Success, NPS/CSAT y cambios de despliegue.

La especificación separa identidades internas y externas, no introduce slugs, no acepta IDs de tenant en la superficie pública, reutiliza únicamente consultas de conocimiento con visibilidades explícitas y deja cada recurso futuro detrás de su propio diseño. No quedan decisiones funcionales marcadas como pendientes en el alcance de API-7.3A.
