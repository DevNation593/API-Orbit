# API-7.2 — Diseño de Base de Conocimiento

Fecha: 2026-09-12 (America/Guayaquil).
Estado: diseño conversacional aprobado por el usuario el 2026-09-12; pendiente de revisión de esta especificación escrita. No acredita implementación.

## 1. Objetivo y alcance

Implementar el siguiente bloque del orden de API-7 del documento maestro: una Base de Conocimiento multiempresa con configuración propia, categorías, etiquetas, artículos, historial inmutable, publicación controlada y lectura pública segura.

Este bloque incluye:

- configuración de una base por tenant;
- CRUD protegido de categorías y etiquetas;
- creación y edición versionada de artículos;
- estados `DRAFT`, `PUBLISHED` y `ARCHIVED`;
- visibilidades `PUBLIC`, `CUSTOMER` e `INTERNAL`;
- publicación, archivado, restauración y recuperación de versiones anteriores;
- lectura anónima exclusivamente de versiones publicadas con visibilidad `PUBLIC`;
- permisos, auditoría, aislamiento por tenant, pruebas, OpenAPI y colección Postman.

Se mantienen las decisiones expresas del usuario: no se usan slugs; los recursos internos conservan IDs numéricos; los UUID se reservan para localizadores públicos opacos y no editables. PostgreSQL se configura únicamente mediante `DATABASE_URL` y Redis únicamente mediante `REDIS_URL`; API-7.2 no añade ni cambia variables de conexión.

Quedan fuera de este bloque la autenticación de clientes, el Portal de Clientes, el acceso a contenido `CUSTOMER`, contratos, facturas, pagos, suscripciones, adjuntos específicos, traducciones, votos, analítica de lectura, recomendaciones y funciones de IA. El Portal se diseñará como API-7.3 y no reutilizará directamente los endpoints internos.

## 2. Alternativas consideradas

### Alternativa elegida: módulo editorial interno y lectura pública acotada

La gestión usa Sanctum y contexto de tenant. Una API pública separada entrega solo la versión publicada de artículos `PUBLIC`, bajo un identificador UUID de la base y otro del artículo. `CUSTOMER` queda almacenado y probado, pero no se expone hasta que exista autenticación de portal.

Esta alternativa completa el significado de `PUBLIC`, conserva el límite de seguridad del futuro portal y permite probar la Base de Conocimiento de extremo a extremo ahora.

### Alternativa descartada: solo gestión interna

Reduciría superficie pública, pero dejaría la visibilidad `PUBLIC` sin un consumidor real y aplazaría una parte esencial del flujo de publicación.

### Alternativa descartada: Base de Conocimiento y Portal completos juntos

Mezclaría el dominio editorial con identidades externas y recursos que todavía no existen, como contratos, facturas, pagos y suscripciones. El resultado tendría más acoplamiento, decisiones prematuras y una entrega difícil de verificar.

## 3. Arquitectura

Se conserva el monolito modular Laravel y las convenciones existentes. Las rutas vivirán en `routes/knowledge.php`, requerido dentro de `/api/v1` junto a los módulos `marketing.php` y `support.php`.

Habrá dos superficies HTTP independientes:

- interna: middleware `auth:sanctum` y `tenant.context`, IDs numéricos y policies;
- pública: sin sesión, con rate limit específico, UUID de base/artículo y consultas que omiten el scope global solo para localizar contenido expresamente publicable.

Los controladores se limitan a autorizar, validar y serializar. Las reglas de negocio se concentran en servicios:

- `KnowledgeConfigurationService`: configuración, categorías, etiquetas y bajas protegidas;
- `KnowledgeArticleService`: identidad del artículo, versiones, publicación y máquina de estados;
- `PublicKnowledgeService`: consultas públicas con proyección mínima y restauración temporal de `TenantContext`.

Se reutilizan `TenantScoped`, `TenantContext`, `ChecksTenantPermission`, `BaseApiRequest`, `ApiResponse`, `AuditService` y `HtmlSanitizer`. API-7.2 no necesita colas, proveedores externos ni tráfico de red.

## 4. Modelo de datos

Todas las tablas nuevas incluyen `tenant_id` y timestamps. Las relaciones se validan en aplicación y se refuerzan con claves foráneas, restricciones únicas, índices y RLS opcional en PostgreSQL.

| Modelo / tabla | Responsabilidad y campos principales |
| --- | --- |
| `KnowledgeBase` / `knowledge_bases` | Una por tenant. `public_id` UUID globalmente único, `title`, `description`, `is_public`. El UUID se genera en servidor y nunca se acepta en altas o cambios. |
| `KnowledgeCategory` / `knowledge_categories` | `name`, `normalized_name`, `description`, `position`, `is_active`. Catálogo plano, sin slug. Nombre normalizado único por tenant. |
| `KnowledgeTag` / `knowledge_tags` | `name`, `normalized_name`, `description`, `is_active`. Nombre normalizado único por tenant. |
| `KnowledgeArticle` / `knowledge_articles` | Identidad estable: `public_id`, `status`, `current_version_id`, `published_version_id`, `created_by`, `updated_by`, `published_at`, `archived_at`. No almacena un slug. |
| `KnowledgeArticleVersion` / `knowledge_article_versions` | Snapshot inmutable: `article_id`, `version`, `category_id`, `author_id`, `title`, `summary`, `body_html`, `visibility`, `seo_title`, `seo_description`, `change_summary`. Única por artículo y número de versión. |
| `knowledge_article_version_tags` | Asociación tenant-safe entre una versión y sus etiquetas. Captura las etiquetas de esa versión sin alterar el historial. |

`current_version_id` señala la revisión de trabajo más reciente. `published_version_id` señala la revisión visible externamente. Ambas referencias son anulables y apuntan a versiones del mismo artículo y tenant; el servicio comprueba esa condición dentro de una transacción. La migración crea primero la identidad, después las versiones y por último las claves foráneas circulares.

Los UUID de base y artículo son localizadores públicos, no sustituyen los IDs internos ni participan en autenticación. No se pueden buscar ni modificar desde la API interna.

Índices mínimos:

- base por `tenant_id` único y `public_id` único;
- categorías por tenant, estado, posición y nombre normalizado;
- etiquetas por tenant, estado y nombre normalizado;
- artículos por tenant/estado/fechas y `public_id` único;
- versiones por tenant/artículo/versión, categoría, visibilidad y título;
- pivot por tenant/versión/etiqueta con asociación única.

No hay borrado físico de artículos ni versiones mediante API. Una categoría o etiqueta sin referencias se puede borrar; si cualquier versión la referencia, la API devuelve 409 y debe desactivarse. El historial no se elimina al archivar.

## 5. Ciclo editorial y versionado

La configuración de la base se crea o actualiza mediante un `PUT` idempotente. `is_public=false` deshabilita toda lectura anónima sin cambiar artículos ni versiones.

Crear un artículo genera su identidad y la versión 1 dentro de una sola transacción. El estado inicial siempre es `DRAFT`; IDs, autores, estado, versión y fechas provienen del servidor.

Cada `PATCH` autorizado crea una nueva versión inmutable copiando los valores no enviados desde la versión actual. Nunca modifica una versión existente. Para evitar actualizaciones perdidas exige `expected_version`; si no coincide con la revisión actual devuelve 409.

Publicar fija `published_version_id=current_version_id`, cambia el estado a `PUBLISHED` y registra `published_at`. Publicar de nuevo la misma versión es un no-op 200 sin auditoría duplicada. Si un artículo publicado se edita, la versión publicada anterior continúa visible y la respuesta interna marca `has_unpublished_changes=true` hasta una nueva publicación.

Archivar cambia el estado a `ARCHIVED`, fija `archived_at` y retira inmediatamente el artículo de la API pública, pero conserva las referencias de versión. Restaurar un archivado lo devuelve a `DRAFT`, limpia `published_version_id`, `published_at` y `archived_at`, y exige una publicación explícita posterior.

Restaurar una versión histórica no rebobina ni modifica registros: crea una nueva versión con el siguiente número y el contenido del snapshot seleccionado. También exige `expected_version`. Un artículo archivado debe restaurarse a `DRAFT` antes de editar o recuperar una versión.

Las categorías y etiquetas inactivas continúan siendo visibles dentro de versiones históricas o publicadas, pero no se pueden seleccionar para una versión nueva. Desactivarlas no altera silenciosamente contenido ya publicado.

## 6. Contenido y validación

El cuerpo se guarda como HTML saneado mediante el servicio existente. Scripts, handlers de eventos, URLs peligrosas y elementos no permitidos se eliminan antes de crear la versión. La auditoría nunca copia `body_html`.

Límites propuestos:

- título: 1–255 caracteres;
- resumen: opcional, máximo 1.000 caracteres;
- cuerpo HTML: 1–200.000 caracteres antes del saneamiento y no vacío después de sanear;
- categoría: opcional, activa y del tenant actual;
- etiquetas: máximo 20 IDs distintos, activos y del tenant actual;
- visibilidad: `PUBLIC`, `CUSTOMER` o `INTERNAL`;
- título SEO: opcional, máximo 70 caracteres;
- descripción SEO: opcional, máximo 170 caracteres;
- resumen del cambio: opcional, máximo 500 caracteres.

Los nombres se recortan y normalizan para unicidad sin convertirlos en slugs. La normalización sigue el patrón Unicode/ASCII ya utilizado por etiquetas del CRM. Títulos de artículos no necesitan ser únicos.

Se rechazan campos controlados por servidor, entre ellos `tenant_id`, `public_id`, `status`, números o punteros de versión, autores y timestamps. Las referencias de otro tenant se responden como 422 sin revelar sus datos; una URL interna de otro tenant responde 404.

## 7. Contrato HTTP interno

Todas las rutas internas están bajo `/api/v1/knowledge` y requieren Bearer Sanctum y `X-Tenant-ID`.

| Recurso | Operaciones |
| --- | --- |
| `/knowledge/settings` | `GET`, `PUT` para consultar o crear/actualizar la configuración única. |
| `/knowledge/categories` | `GET`, `POST`; `GET`, `PATCH`, `DELETE` sobre `/{category}`. |
| `/knowledge/tags` | `GET`, `POST`; `GET`, `PATCH`, `DELETE` sobre `/{tag}`. |
| `/knowledge/articles` | `GET`, `POST`; `GET`, `PATCH` sobre `/{article}`. |
| `/knowledge/articles/{article}/versions` | `GET` paginado. |
| `/knowledge/articles/{article}/versions/{version}` | `GET` de una versión y `POST /restore` para copiarla como nueva revisión. `{version}` es el número secuencial dentro del artículo, no el ID global de la fila. |
| `/knowledge/articles/{article}/publish` | `POST` con `expected_version`. |
| `/knowledge/articles/{article}/archive` | `POST`. |
| `/knowledge/articles/{article}/restore` | `POST` para volver de `ARCHIVED` a `DRAFT`. |

Las listas aceptan `per_page` entre 1 y 100. Artículos filtra por `q`, `status`, `visibility`, `category_id`, `tag_id`, `has_unpublished_changes` y fechas. El orden permitido es `created_at`, `updated_at`, `published_at` o `title`, con dirección explícita y desempate por ID. Categorías y etiquetas filtran por texto y actividad. No se interpolan columnas ni expresiones SQL del cliente.

Las respuestas usan `{data, meta}`. El artículo interno incluye identidad, estado, revisión actual, revisión publicada, `has_unpublished_changes`, contenido actual, categoría y etiquetas. El listado no incluye `body_html`; el detalle y la consulta explícita de versión sí.

## 8. Contrato HTTP público

Las rutas públicas son de solo lectura y usan un limitador `public-knowledge`:

```text
GET /api/v1/public/knowledge/{basePublicId}
GET /api/v1/public/knowledge/{basePublicId}/categories
GET /api/v1/public/knowledge/{basePublicId}/articles
GET /api/v1/public/knowledge/{basePublicId}/articles/{articlePublicId}
```

Solo responden cuando la base está habilitada, el artículo está `PUBLISHED` y su versión publicada tiene visibilidad `PUBLIC`. Contenido `CUSTOMER` e `INTERNAL`, borradores, versiones no publicadas y archivados responden 404, igual que una combinación de base/artículo de tenants distintos.

La lista pública filtra `q`, categoría y etiqueta, pagina de 1 a 100 y ordena únicamente por título o fecha de publicación. La búsqueda usa valores escapados y consulta el snapshot publicado, nunca el borrador actual.

La salida pública omite `tenant_id`, IDs internos de artículo, autores, revisiones de trabajo, auditoría y campos operativos. El listado devuelve el UUID del artículo, título, resumen, categoría, etiquetas, SEO, número de versión publicada y fecha de publicación, pero no `body_html`. El detalle agrega el HTML saneado. Categorías y etiquetas pueden exponer sus IDs numéricos como vocabulario dentro de esa base pública.

El controlador localiza la base por UUID sin scope tenant, verifica su estado, establece temporalmente `TenantContext`, ejecuta una consulta restringida y restaura el contexto en `finally`. Nunca acepta un tenant libre en el body ni reutiliza una API interna.

## 9. Autorización, auditoría y errores

Permisos nuevos:

- `knowledge.view`: leer configuración, catálogos, artículos y versiones internas;
- `knowledge.manage`: crear/editar configuración, catálogos, artículos y versiones; también requiere lectura;
- `knowledge.publish`: publicar, archivar y restaurar artículos; también requiere lectura.

Las migraciones agregan las claves al catálogo y las conceden a roles de sistema y administración según el patrón existente. No amplían silenciosamente roles personalizados sin autoridad administrativa.

La auditoría cubre configuración, catálogos, creación de artículo, nueva versión, publicación, archivado y restauraciones. Registra IDs, número de versión, estado, visibilidad y campos modificados; no cuerpos HTML ni secretos.

Códigos principales:

- 200 para lectura, `PATCH`, actualización de configuración o acción idempotente;
- 201 para la primera configuración, creación de catálogo/artículo y restauración histórica que genera una nueva versión;
- 401 sin autenticación en rutas internas;
- 403 sin permiso;
- 404 para recurso ausente, ajeno al tenant o no publicable;
- 409 para nombres duplicados, versión esperada obsoleta, transición inválida o borrado protegido;
- 422 para payload, HTML vacío tras saneamiento o referencias inválidas;
- 429 por exceso de solicitudes públicas.

La creación de una versión bloquea el artículo, relee la revisión actual y asigna el siguiente número dentro de la transacción. La restricción única de artículo/versión funciona como defensa adicional frente a carreras.

## 10. Pruebas y criterios de aceptación

El desarrollo seguirá RED–GREEN–REFACTOR sobre SQLite aislado. PostgreSQL desechable en CI debe verificar RLS, claves, índices y carreras; nunca se usa la `DATABASE_URL` real para rollback o pruebas destructivas.

Casos obligatorios:

1. Configuración única por tenant, UUIDs generados por servidor y ausencia total de slugs.
2. CRUD de categorías/etiquetas, normalización, desactivación y borrado protegido.
3. Artículo `DRAFT` con versión 1; cada edición crea una versión nueva e inmutable.
4. Conflicto por `expected_version` y serialización correcta de dos ediciones concurrentes.
5. Publicación, edición posterior sin reemplazar el snapshot público, republicación, archivado y restauración.
6. Recuperación de una versión histórica como revisión nueva, sin modificar el historial.
7. Sanitización de HTML y rechazo de campos controlados por servidor.
8. Matriz de permisos `view/manage/publish`, 401/403 y aislamiento entre al menos dos tenants.
9. API pública: solo `PUBLIC` publicado; `CUSTOMER`, `INTERNAL`, borradores, archivados y cruces de tenant devuelven 404.
10. Filtros, orden permitido, paginación, proyección reducida y búsqueda escapada.
11. Auditoría sin `body_html`; migración, rollback exclusivo y reaplicación sobre base desechable.
12. Flujo Postman autocontenido: configurar base, crear catálogos, crear borrador, editar, publicar, leer públicamente, crear nueva revisión, confirmar snapshot, republicar y archivar.
13. OpenAPI sin referencias rotas, rutas Laravel alineadas y suite completa sin regresiones.

Las pruebas públicas no realizan tráfico externo. El rate limiter, reloj y sanitización se verifican localmente. No se crean endpoints de depuración ni formas de seleccionar tenant mediante payload.

## 11. Documentación y entrega

La implementación actualizará:

- `docs/17-API-7-2-KNOWLEDGE-BASE.md` con uso, permisos, estados y ejemplos;
- `docs/openapi.yaml` con todos los contratos internos y públicos;
- la colección y guía Postman con variables capturadas automáticamente;
- `README.md` y `docs/09-GAP-ANALYSIS.md` sin declarar terminados Portal, Customer Success ni encuestas.

API-7.2 solo se considerará terminada con migración reversible, modelos, policies, requests, servicios, endpoints, auditoría, pruebas y documentación coherentes. No requiere nuevas dependencias, credenciales, workers ni cambios de despliegue.

## 12. Revisión de consistencia

La especificación cubre las entidades, estados y visibilidades exigidos por el documento maestro; resuelve el versionado señalado como ausente en el GAP Analysis; aplica la decisión posterior del usuario de eliminar slugs; y separa explícitamente la lectura pública del futuro acceso autenticado `CUSTOMER`.

No quedan decisiones marcadas como pendientes ni se atribuye a este bloque funcionalidad de Portal, Customer Success o encuestas.
