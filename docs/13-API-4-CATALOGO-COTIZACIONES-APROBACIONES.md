# API-4 — Catálogo, CPQ, cotizaciones, aprobaciones y ERP

API-4 completa el ciclo comercial desde la definición de productos y precios hasta la aceptación de una cotización y su sincronización con un ERP. Todos los registros empresariales incluyen `tenant_id`, scopes Eloquent, RLS opcional, permisos, auditoría y validación de referencias dentro del tenant.

## Decisiones de contrato

- Los IDs internos continúan siendo numéricos.
- Una cotización expuesta al cliente usa UUID `public_id` y un token aleatorio de 64 caracteres.
- No existe ni se requiere `slug`.
- Los importes, cantidades, tasas y tipos de cambio se reciben y devuelven como cadenas decimales de hasta seis posiciones. Los cálculos usan `BigDecimal`; no se usa `float`.
- PostgreSQL se configura únicamente mediante `DATABASE_URL` y Redis únicamente mediante `REDIS_URL`.
- La aceptación pública exige `Idempotency-Key` o `idempotency_key`; repetir la misma decisión con la misma clave no duplica efectos.
- Los PDFs y las sincronizaciones ERP se procesan en las colas `documents` e `integrations`.

## Catálogo y precios

Entidades principales:

```text
Currency, ProductCategory, Product, ProductVariant, Tax, Discount,
Bundle, BundleItem, PriceList, PriceListItem
```

Tipos de producto:

```text
product, service, subscription, bundle
```

Rutas:

```text
GET|POST          /api/v1/currencies
GET|PATCH|DELETE  /api/v1/currencies/{currency}
GET|POST          /api/v1/product-categories
GET|PATCH|DELETE  /api/v1/product-categories/{category}
GET|POST          /api/v1/products
GET|PATCH|DELETE  /api/v1/products/{product}
POST              /api/v1/products/{product}/variants
PATCH|DELETE      /api/v1/products/{product}/variants/{variant}
GET|POST          /api/v1/taxes
GET|PATCH|DELETE  /api/v1/taxes/{tax}
GET|POST          /api/v1/discounts
GET|PATCH|DELETE  /api/v1/discounts/{discount}
GET|POST          /api/v1/bundles
GET|PATCH|DELETE  /api/v1/bundles/{bundle}
GET|POST          /api/v1/price-lists
GET|PATCH|DELETE  /api/v1/price-lists/{priceList}
```

La primera moneda creada en un tenant se convierte en base con tipo de cambio `1`. Solo puede existir una moneda base. Un impuesto o descuento fijo requiere moneda; los porcentuales no la requieren.

El precio unitario se resuelve en este orden:

1. tramo aplicable de la lista de precios para producto, variante y cantidad;
2. precio del bundle fijo o suma de sus componentes;
3. precio base del producto más ajuste de variante;
4. conversión a la moneda de la cotización;
5. reglas CPQ activas por prioridad.

## CPQ

Entidades:

```text
PricingRule, DiscountRule, BundleRule, ProductDependency, ApprovalRule
```

Rutas:

```text
GET|POST          /api/v1/cpq/rules/{pricing|discount|bundle|approval}
GET|PATCH|DELETE  /api/v1/cpq/rules/{ruleType}/{rule}
GET|POST          /api/v1/product-dependencies
PATCH|DELETE      /api/v1/product-dependencies/{dependency}
```

Las condiciones son declarativas:

```json
{
  "field": "quantity",
  "operator": "gte",
  "value": "10"
}
```

No se evalúa código enviado por clientes. Las dependencias `requires` y `excludes` detienen una configuración inválida; `recommends` agrega una advertencia a `metadata.configuration_warnings`.

## Cotizaciones

Entidades:

```text
Quote, QuoteItem, QuoteTax, QuoteDiscount, QuoteActivity, QuoteApproval
```

Estados:

```text
draft, pending_approval, approved, sent, viewed, accepted,
rejected, expired, cancelled
```

Rutas administrativas:

```text
GET|POST          /api/v1/quotes
GET|PATCH|DELETE  /api/v1/quotes/{quote}
POST              /api/v1/quotes/{quote}/duplicate
POST              /api/v1/quotes/{quote}/revise
POST              /api/v1/quotes/{quote}/submit
POST              /api/v1/quotes/{quote}/approve
POST              /api/v1/quotes/{quote}/reject-approval
POST              /api/v1/quotes/{quote}/send
POST              /api/v1/quotes/{quote}/accept
POST              /api/v1/quotes/{quote}/reject
POST              /api/v1/quotes/{quote}/cancel
POST|GET          /api/v1/quotes/{quote}/pdf
GET               /api/v1/quotes/{quote}/activities
POST              /api/v1/quotes/{quote}/sync-erp
```

Rutas públicas:

```text
GET  /api/v1/public/quotes/{publicId}?token=...
GET  /api/v1/public/quotes/{publicId}/pdf?token=...
POST /api/v1/public/quotes/{publicId}/accept
POST /api/v1/public/quotes/{publicId}/reject
```

Una actualización solo es válida mientras la cotización está en `draft`. Una revisión conserva el número documental, incrementa `version` y obtiene un `public_id` nuevo; una duplicación obtiene un número nuevo.

Ejemplo de línea:

```json
{
  "product_id": 10,
  "product_variant_id": 14,
  "quantity": "2.000000",
  "discount_ids": [3]
}
```

El cálculo conserva snapshots de nombre, SKU, precio, descuentos e impuestos. El total sigue:

```text
subtotal
- descuentos de línea
- descuentos de cotización/CPQ
+ impuestos exclusivos
= grand_total
```

Los impuestos inclusivos se informan en `tax_total`, pero no se suman otra vez al total.

## PDF y envío

`QuotePdfService` genera el documento con mPDF, lo almacena de forma privada mediante el disco configurado y registra un `FileRecord` relacionado con la cotización. `POST /quotes/{quote}/pdf` encola `GenerateQuotePdfJob`.

El envío reutiliza el canal de correo del Inbox. Debe existir una cuenta de email activa y conectada dentro del tenant. La respuesta entrega el token de aceptación solo al actor autorizado y el mensaje al cliente contiene la URL pública segura.

En producción Horizon incluye supervisores para:

```text
default, notifications, automations, integrations, sequences,
documents, imports, exports
```

## Approval Engine

Entidades:

```text
ApprovalProcess, ApprovalStep, ApprovalRequest, ApprovalDecision,
ApprovalDelegation, ApprovalEscalation
```

Rutas:

```text
GET|POST          /api/v1/approval-processes
GET|PATCH|DELETE  /api/v1/approval-processes/{process}
POST              /api/v1/approval-processes/{process}/versions
GET               /api/v1/approval-requests
GET               /api/v1/approval-requests/{approval}
POST              /api/v1/approval-requests/{approval}/decide
POST              /api/v1/approval-requests/{approval}/cancel
GET|POST          /api/v1/approval-delegations
PATCH|DELETE      /api/v1/approval-delegations/{delegation}
```

Los procesos son reutilizables para `quote`, `discount`, `deal` y `contract`. Soportan pasos ordenados, aprobador por usuario/rol/permiso, modo `any`/`all`, mínimo de decisiones, condiciones por paso, delegaciones temporales y escalamiento.

El scheduler ejecuta cada cinco minutos:

```bash
php artisan approvals:escalate-due
```

Una definición que ya tiene solicitudes no puede modificar sus pasos; se crea una versión nueva.

## ERP

El provider `vantex_erp` implementa un límite HTTP configurable, sin simular respuestas de producción:

```json
{
  "provider": "vantex_erp",
  "name": "ERP principal",
  "credentials": {
    "api_token": "<secret>"
  },
  "settings": {
    "base_url": "https://erp.example.com",
    "health_path": "/api/health",
    "products_path": "/api/v1/crm/products/upsert",
    "sales_orders_path": "/api/v1/crm/sales-orders"
  }
}
```

Las URLs privadas o inseguras se rechazan, no se siguen redirects, los secretos permanecen cifrados y cada envío lleva `Idempotency-Key` y `X-Vantex-CRM-Tenant`.

```text
POST /api/v1/products/{product}/sync-erp
POST /api/v1/quotes/{quote}/sync-erp
GET  /api/v1/erp/syncs
GET  /api/v1/erp/syncs/{sync}
POST /api/v1/erp/syncs/{sync}/retry
```

Solo las cotizaciones aceptadas se convierten en órdenes ERP. Cada intento genera un log sanitizado y actualiza `erp_product_id` o `erp_document_id` con el identificador externo. El contrato exacto de un ERP concreto puede ajustarse mediante los paths configurables o un provider adicional; la prueba E2E real requiere credenciales sandbox del proveedor.

## Permisos

```text
catalog.view, catalog.manage
pricing.view, pricing.manage
quotes.view, quotes.create, quotes.update, quotes.delete,
quotes.send, quotes.approve, quotes.accept
approvals.view, approvals.manage, approvals.decide
erp_sync.view, erp_sync.manage
```

## Postman y pruebas automatizadas

La carpeta **09 - API-4 catálogo, CPQ, cotizaciones y aprobaciones** crea un flujo completo sin secretos externos: moneda, impuesto, producto, variante, descuento, lista de precios, regla CPQ, proceso de aprobación, cotización, recálculo, aprobación, duplicación, revisión y encolado del PDF.

La cobertura automatizada principal está en:

```text
tests/Feature/Api/ProductQuoteApprovalTest.php
```

Incluye precisión decimal, actualización sin doble descuento, CPQ, bundles, dependencias, aprobación multinivel, escalamiento, delegación, replay, PDF real, decisión pública, contrato HTTP ERP, permisos y aislamiento entre tenants.
