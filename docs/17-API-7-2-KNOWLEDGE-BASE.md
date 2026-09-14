# API-7.2 — Base de conocimiento

API-7.2 agrega una base de conocimiento por tenant. Las rutas internas usan IDs numéricos, Bearer Sanctum y `X-Tenant-ID`; no utiliza ni acepta `slug`.

## Configuración y permisos

`PUT /api/v1/knowledge/settings` crea o actualiza el título, descripción y visibilidad pública. El servidor asigna el UUID `public_id`; este UUID se usa exclusivamente bajo `/api/v1/public/knowledge/{basePublicId}`.

| Permiso | Uso |
| --- | --- |
| `knowledge.view` | Leer configuración, catálogos, artículos y revisiones. |
| `knowledge.manage` | Configurar, administrar categorías/etiquetas y crear revisiones. |
| `knowledge.publish` | Publicar, archivar y restaurar el ciclo del artículo. |

Las categorías y etiquetas deben estar activas y pertenecer al tenant. Una categoría o etiqueta referenciada no se borra: se desactiva o responde `409`.

## Artículos y versiones

`POST /knowledge/articles` crea un artículo `DRAFT` y su versión 1. `PATCH /knowledge/articles/{article}` exige `expected_version` y siempre crea una revisión nueva; nunca muta las anteriores. `POST /versions/{version}/restore` copia una revisión histórica a una nueva versión editable.

Los estados son `DRAFT`, `PUBLISHED` y `ARCHIVED`. Publicar fija el snapshot público en la versión actual. Una edición posterior no cambia ese snapshot hasta publicar otra vez. Archivar quita el artículo de la API pública; restaurarlo lo devuelve a `DRAFT` sin puntero publicado.

El HTML de `body_html` se sanea. Los listados de artículos, versiones y auditoría no exponen cuerpos privados; el detalle autorizado y el detalle público publicado contienen únicamente el cuerpo que corresponde al contrato.

## Rutas públicas

Las cuatro consultas públicas de lectura del flujo son:

```text
GET /api/v1/public/knowledge/{basePublicId}/articles
GET /api/v1/public/knowledge/{basePublicId}/articles/{articlePublicId}
```

No llevan `Authorization` ni `X-Tenant-ID`. Solo exponen artículos `PUBLISHED` con visibilidad `PUBLIC`. Los artículos archivados, no publicados, `CUSTOMER` o `INTERNAL` devuelven deliberadamente `404`. La base pública está limitada por IP y UUID de base; no habilita Portal ni acceso `CUSTOMER`.

## Postman y pruebas

La carpeta **13 - API-7.2 base de conocimiento** contiene 18 solicitudes encadenadas: configura la base, crea categoría/etiqueta, recorre versiones 1–4, valida el snapshot público, archiva y restaura. No necesita IDs manuales, credenciales externas, Redis externo ni tráfico saliente.

```powershell
$env:APP_ENV='testing'; $env:DATABASE_URL='sqlite:///:memory:'
php artisan test --compact tests/Feature/Api/KnowledgeOpenApiContractTest.php
php artisan test --compact tests/Feature/Api/KnowledgePostmanWorkflowTest.php
```

OpenAPI documenta 17 paths y 26 operaciones de este bloque. PostgreSQL/RLS, índices y carreras deben verificarse con PostgreSQL desechable en CI o despliegue; las pruebas locales usan SQLite en memoria y nunca deben usar la base real.

## Límites explícitos

API-7.2 no implementa Portal de cliente, usuarios externos, autenticación de clientes ni rutas para artículos `CUSTOMER`. Esas capacidades permanecen pendientes dentro de API-7.
