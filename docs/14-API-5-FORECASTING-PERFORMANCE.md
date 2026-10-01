# API-5 — Forecasting y performance comercial

API-5 completa la estructura comercial del CRM y agrega territorios, metas, forecast, analítica de ventas y playbooks ejecutables. La implementación conserva el monolito modular de Laravel, el aislamiento por `tenant_id`, IDs internos numéricos y respuestas `{data, meta}`.

## Decisiones de contrato

- No se utilizan slugs. Las sucursales y territorios tienen `code` como dato de negocio, pero las rutas siempre usan el ID numérico.
- PostgreSQL se configura únicamente con `DATABASE_URL` y Redis únicamente con `REDIS_URL`.
- Los importes, metas, ponderaciones, porcentajes y ratios se calculan con precisión decimal y se serializan como strings de seis decimales.
- Una oportunidad siempre valida que su etapa pertenezca al pipeline seleccionado.
- Si una oportunidad recibe equipo o territorio con sucursal, `branch_id` se infiere automáticamente. Una combinación incompatible devuelve `422`.
- `as_of_date` excluye oportunidades creadas o ganadas después de la fecha de corte. Los snapshots conservan el resultado calculado para comparaciones históricas.
- Las definiciones de playbook que ya tienen ejecuciones no se modifican estructuralmente; se crea una versión nueva, inactiva por defecto.

## Modelo de datos

La migración `2026_09_03_010000_create_sales_performance_foundations.php` incorpora:

```text
Branch, BranchMember
SalesTeam, SalesTeamMember
Territory, TerritoryMember, TerritoryRule, TerritoryAssignment
Goal, GoalTarget, GoalProgress
ForecastSnapshot
Playbook, PlaybookSection, PlaybookQuestion
PlaybookExecution, PlaybookAnswer, PlaybookActionLog
```

También amplía:

```text
Contact        -> territory_id
Organization   -> territory_id, industry
Lead           -> territory_id
Deal           -> sales_team_id, branch_id, territory_id,
                  forecast_category, closed_at
```

La migración incluye índices por tenant, jerarquía, membresía, estado, fecha de cierre, fecha esperada, dimensión de meta y fecha de snapshot. Es reversible tanto en PostgreSQL como en SQLite de pruebas.

## Sucursales, equipos y miembros

Las sucursales y equipos admiten jerarquía sin ciclos. No se permite eliminar un nodo que todavía tenga hijos o relaciones operativas incompatibles.

```text
GET|POST          /api/v1/branches
GET|PATCH|DELETE  /api/v1/branches/{branch}
GET|POST          /api/v1/branches/{branch}/members
PATCH|DELETE      /api/v1/branches/{branch}/members/{user}

GET|POST          /api/v1/sales-teams
GET|PATCH|DELETE  /api/v1/sales-teams/{team}
GET|POST          /api/v1/sales-teams/{team}/members
PATCH|DELETE      /api/v1/sales-teams/{team}/members/{user}
```

`POST .../members` agrega o reactiva una membresía. `PATCH .../members/{user}` cambia rol, capacidad, peso de cuota o estado sin requerir nuevamente `user_id` en el body.

Roles de estructura:

```text
member, manager
```

## Territorios

Los territorios soportan árboles arbitrarios, sucursal responsable, manager, posición y tipos:

```text
geographic, named, account, product, custom
```

Rutas:

```text
GET|POST          /api/v1/territories
GET|PATCH|DELETE  /api/v1/territories/{territory}
GET|POST          /api/v1/territories/{territory}/members
PATCH|DELETE      /api/v1/territories/{territory}/members/{user}

GET|POST          /api/v1/territory-rules
GET|PATCH|DELETE  /api/v1/territory-rules/{rule}

GET|POST          /api/v1/territory-assignments
DELETE            /api/v1/territory-assignments/{assignment}
```

Las reglas usan condiciones declarativas, prioridad, modo `all`/`any` y `stop_processing`. Pueden asignar contactos, organizaciones, leads y oportunidades. No se evalúa código enviado por clientes.

Ejemplo:

```json
{
  "territory_id": 12,
  "name": "Empresas tecnológicas",
  "entity_type": "organization",
  "conditions": [
    { "field": "industry", "operator": "eq", "value": "technology" }
  ],
  "match_type": "all"
}
```

La asignación conserva la regla aplicada, el actor, la fecha, el origen `manual|rule` y sincroniza `territory_id` en la entidad.

## Metas

Métricas disponibles:

```text
revenue, deals_won, deals_created, calls, meetings,
new_customers, quotes, activities
```

Ámbitos disponibles:

```text
tenant, user, team, branch, territory, product, industry
```

Rutas:

```text
GET|POST          /api/v1/goals
GET|PATCH|DELETE  /api/v1/goals/{goal}
POST              /api/v1/goals/{goal}/refresh
POST              /api/v1/goals/{goal}/targets
PATCH|DELETE      /api/v1/goals/{goal}/targets/{target}
POST              /api/v1/goals/{goal}/targets/{target}/refresh
```

Las metas de ingresos requieren moneda. `GoalProgress` genera como máximo un snapshot diario por target y fecha; un recálculo actualiza el mismo registro de forma transaccional. Para actividades sin `occurred_at`, el API asigna la fecha actual al crearlas.

## Forecast

Rutas requeridas por el documento maestro:

```text
GET /api/v1/forecast
GET /api/v1/forecast/users
GET /api/v1/forecast/teams
```

Rutas adicionales para persistir el histórico:

```text
GET|POST /api/v1/forecast/snapshots
```

Indicadores:

```text
pipeline
weighted_pipeline
commit
best_case
closed_won
target
coverage
```

Fórmulas:

```text
weighted_pipeline = SUM(opportunity_amount × stage_probability / 100)
coverage          = pipeline / target
```

Los montos se convierten a la moneda solicitada o a la moneda base activa del tenant. Si una oportunidad usa una moneda no configurada, el cálculo se rechaza con `422` en lugar de mezclar importes incompatibles.

Filtros comunes:

```text
starts_at, ends_at, as_of_date, currency_id, pipeline_id,
owner_id, team_id, branch_id, territory_id
```

## Sales Analytics

```text
GET /api/v1/sales-analytics
```

Métricas:

```text
win_rate, loss_rate, average_deal_size, sales_cycle,
pipeline_velocity, conversion_rate, lead_to_opportunity,
opportunity_to_sale
```

Desgloses de ingresos:

```text
by_owner, by_product, by_industry, by_territory, by_source
```

Las agregaciones se ejecutan en consultas agrupadas y cargas por lote, sin N+1. Los filtros admiten periodo, moneda, pipeline, propietario, equipo, sucursal, territorio, producto, industria y origen.

## Playbooks

Rutas de definición:

```text
GET|POST          /api/v1/playbooks
GET|PATCH|DELETE  /api/v1/playbooks/{playbook}
POST              /api/v1/playbooks/{playbook}/versions
```

Rutas de ejecución:

```text
GET|POST  /api/v1/playbook-executions
GET       /api/v1/playbook-executions/{execution}
POST      /api/v1/playbook-executions/{execution}/answers
POST      /api/v1/playbook-executions/{execution}/complete
POST      /api/v1/playbook-executions/{execution}/cancel
```

Tipos de pregunta:

```text
text, textarea, number, select, multiselect, boolean, date
```

Acciones permitidas:

```text
update_field, calculate_score, trigger_workflow, create_task
```

Las respuestas se validan contra tipo, opciones, límites y configuración de score. Cada acción genera `PlaybookActionLog` con clave idempotente. Repetir la misma respuesta no duplica tareas ni workflows; las acciones naturalmente idempotentes de campo y score se reaplican para mantener el estado correcto cuando una respuesta cambia y luego vuelve a un valor anterior.

## Permisos

```text
sales_structure.view, sales_structure.manage
territories.view, territories.manage
goals.view, goals.manage
forecast.view, forecast.manage
analytics.view
playbooks.view, playbooks.manage, playbooks.execute
```

Todos los endpoints autenticados también requieren una membresía activa para `X-Tenant-ID`. Los IDs relacionados se validan dentro del tenant y las policies verifican tanto permiso como pertenencia del modelo.

## Oportunidades

`POST|PATCH /api/v1/deals` ahora acepta:

```json
{
  "sales_team_id": 4,
  "branch_id": 2,
  "territory_id": 9,
  "forecast_category": "commit"
}
```

Las respuestas de creación, consulta y actualización incluyen `pipeline`, `stage`, `team`, `branch` y `territory`. La moneda y los estados se normalizan antes de validar.

## Postman y pruebas

La carpeta **10 - API-5 forecasting y performance comercial** ejecuta 20 solicitudes encadenadas sin credenciales externas. Crea sucursal, equipo, miembro, cambio de rol, territorio, regla, asignación, oportunidad, meta, snapshot y playbook; también consulta forecast y analítica.

Cobertura automatizada principal:

```text
tests/Feature/Api/SalesPerformancePlaybookTest.php
```

Verificación de cierre:

- suite API-5: 5 pruebas, 123 aserciones;
- suite completa: 65 pruebas, 876 aserciones;
- OpenAPI: 235 paths, 90 schemas, 394 operaciones y 992 referencias locales resueltas;
- paridad API-5: 63 operaciones reales y 63 documentadas;
- Postman: 13 carpetas, 78 variables y 117 solicitudes; JSON y bodies de API-5 válidos;
- migración completa, rollback individual y reaplicación correctos sobre SQLite temporal.

## Consideración histórica

El filtro `as_of_date` evita incluir altas y cierres posteriores a la fecha de corte, pero no reconstruye cambios pasados de importe, etapa o categoría de una oportunidad. Para comparaciones históricas exactas debe utilizarse `ForecastSnapshot`; reconstruir cualquier fecha anterior al despliegue requeriría event sourcing o snapshots preexistentes.
