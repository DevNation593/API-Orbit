# API-7.1 — Diseño de tickets y SLA

Fecha: 2026-09-09 (America/Guayaquil).
Estado: alcance general aprobado en conversación; especificación escrita pendiente de revisión del usuario. No acredita implementación.

## 1. Objetivo y límites

Implementar el primer bloque de API-7 del documento maestro de Vantex CRM: tickets, categorías, colas, agentes, políticas SLA y escalaciones programadas. Las secciones 31–32 exigen estos dominios, los seis estados y las cuatro prioridades recogidos aquí, y las métricas de primera respuesta y resolución.

El usuario aprobó este orden: tickets/SLA, después base de conocimiento/portal, y finalmente Customer Success/encuestas. Este documento desarrolla solo el primer bloque; las decisiones operativas no especificadas por el documento maestro se proponen aquí para su revisión.

Incluye configuración de soporte, asignación, comentarios públicos/internos, transiciones, calendarios, pausas, reapertura, escalaciones, permisos, auditoría, pruebas y OpenAPI/Postman.

Fuera del bloque: autenticación/endpoints de clientes, artículos, encuestas, health scores, fusión de tickets, watchers, round-robin, adjuntos específicos y sincronización automática de tickets con email o WhatsApp. Estos límites no declaran completado todo API-7.

Se mantienen las decisiones del usuario: sin slug; IDs internos numéricos; PostgreSQL solo mediante DATABASE_URL y Redis solo mediante REDIS_URL. No se requieren nuevas credenciales ni cambios en .env.

## 2. Arquitectura y componentes

Se conserva el monolito modular Laravel existente. Las rutas de soporte se separan en routes/support.php bajo /api/v1, con Sanctum y contexto de tenant. Se reutilizan TenantScoped, TenantContext, ChecksTenantPermission, BaseApiRequest, ApiResponse, AuditService y las notificaciones existentes.

Los agentes referencian User y tenant_user; no se crean identidades ni roles paralelos. Un ticket es independiente de Conversation: su enlace opcional no sincroniza estados/mensajes ni concede acceso al inbox.

Se elige un módulo interno integrado para conservar transacciones y autorización. Reutilizar Conversation como ticket mezclaría estados y métricas diferentes; un servicio separado añadiría coordinación y despliegue innecesarios para este bloque.

Responsabilidades propuestas:

- SupportConfigurationService: catálogos, agentes, miembros de colas y bajas protegidas.
- TicketService: alta idempotente, edición, asignación y máquina de estados.
- TicketCommentService: notas/respuestas idempotentes y primera respuesta.
- SlaCalendarService: cálculo de tiempo hábil y vencimientos a partir de una configuración capturada.
- SlaEngine: selección de política, snapshot, medición, pausa, reanudación e incumplimientos.
- SlaEscalationService: registro único de escalaciones y despacho recuperable de notificaciones.

Controllers autorizan, validan y serializan. Las transacciones bloquean primero el ticket y después su ejecución SLA; revalidan el estado antes de mutar y registran auditoría junto con el cambio. Los trabajos se despachan después del commit.

## 3. Modelo de datos

Todas las tablas nuevas tienen tenant_id y timestamps. Las referencias tienen claves foráneas y validación de pertenencia al tenant. Los modelos de negocio usan TenantScoped; los jobs requieren contexto explícito.

| Entidad / tabla | Datos y responsabilidad |
| --- | --- |
| SupportAgent / support_agents | user_id, is_active; único tenant/user. |
| TicketCategory / ticket_categories | name, description, is_active; catálogo plano sin slug. |
| SupportQueue / support_queues | name, description, is_active, sla_policy_id opcional y escalation_agent_id opcional. |
| support_queue_agents | Asociación única tenant/queue/agent. |
| Ticket / tickets | subject, description, status, priority, category_id opcional, queue_id obligatorio, assigned_agent_id opcional, contact_id/organization_id/conversation_id opcionales, created_by, resolution_summary, first_response_at, resolved_at, closed_at, idempotency_key, payload_hash. |
| TicketComment / ticket_comments | ticket_id, author_user_id, visibility PUBLIC o INTERNAL, body, idempotency_key, payload_hash. Inmutable. |
| SlaBusinessCalendar / sla_business_calendars | name, timezone IANA, mode ALWAYS o BUSINESS, weekly_schedule, holidays, is_active. |
| SlaPolicy / sla_policies | name, description, calendar_id opcional, pause_on_waiting_customer, is_active. |
| SlaRule / sla_rules | policy_id, priority, first_response_minutes, resolution_minutes; única policy/priority. |
| SlaExecution / sla_executions | Una por ticket: snapshot de política/regla/calendario, estado RUNNING/PAUSED/RESOLVED/CLOSED, vencimientos, presupuesto restante, pausas, respuesta, resolución y banderas de incumplimiento. |
| SlaEscalation / sla_escalations | execution_id, metric FIRST_RESPONSE o RESOLUTION, breached_at, detected_at, destinatarios y estado de despacho; única tenant/execution/metric. |

No se borran tickets, comentarios, ejecuciones ni escalaciones por API. Un catálogo sin referencias puede borrarse; con referencias devuelve 409 y se desactiva en su lugar. El snapshot guarda datos suficientes para interpretar el historial aunque cambie la configuración.

Índices mínimos: tickets por tenant/status/priority, tenant/queue/status, tenant/assigned_agent/status y tenant/created_at; comentarios por tenant/ticket/id; ejecuciones por tenant/estado/vencimientos; escalaciones por tenant/estado de despacho. La base de datos garantiza las asociaciones y claves idempotentes únicas.

### Agentes y colas

Un agente elegible es un registro activo que referencia a un miembro activo del tenant con tickets.view y tickets.reply. Se valida al registrarlo, asignarlo y notificarlo. Ser administrador de soporte no convierte al usuario automáticamente en agente.

El agente asignado debe pertenecer a la cola activa del ticket. Para cambiar de cola se indica un agente válido de la nueva cola o assigned_agent_id=null. No hay selección automática. escalation_agent_id también debe pertenecer a su cola.

No se permite desactivar una cola, desactivar/quitar un agente ni retirarlo de una cola mientras mantenga allí tickets no CLOSED; primero se reasignan. Un agente usado como responsable de escalaciones también exige cambiar esa configuración antes de desactivarlo o retirarlo.

La baja de un usuario desde el módulo de equipo no se modifica en este bloque. Si deja asignaciones antiguas, se conserva el historial, la API muestra assignee_available=false y las nuevas acciones del agente/notificaciones vuelven a validar su elegibilidad. Un miembro autorizado puede reasignar el ticket.

Desactivar categoría o política impide seleccionarla para nuevos tickets, sin alterar ejecuciones anteriores. Si una cola referencia una política inactiva, el alta devuelve 422 hasta corregirla; no omite silenciosamente el SLA.

## 4. Operación del ticket

El alta exige subject de 1–255 caracteres y queue_id. description es opcional, hasta 20.000 caracteres; priority por defecto MEDIUM y status inicial OPEN. Las prioridades válidas son LOW, MEDIUM, HIGH y URGENT. Proporcionar assigned_agent_id en el alta requiere además tickets.assign; se valida igual que la acción de asignación.

Los vínculos CRM son opcionales y deben ser del mismo tenant. Si se proporcionan conversación y contacto y la conversación ya tiene contacto, ambos deben coincidir. La API expone sus IDs sin expandir perfiles/mensajes protegidos por otros permisos.

El cliente no establece tenant_id, autores, timestamps, banderas ni snapshots. PATCH edita solo subject, description, priority, category_id y vínculos CRM; estado y asignación tienen acciones dedicadas. Cambiar prioridad o cola no recalcula el SLA capturado.

### Estados

| Desde | Destinos permitidos |
| --- | --- |
| OPEN | IN_PROGRESS, WAITING_CUSTOMER, WAITING_INTERNAL, RESOLVED |
| IN_PROGRESS | WAITING_CUSTOMER, WAITING_INTERNAL, RESOLVED |
| WAITING_CUSTOMER | IN_PROGRESS, WAITING_INTERNAL, RESOLVED |
| WAITING_INTERNAL | IN_PROGRESS, WAITING_CUSTOMER, RESOLVED |
| RESOLVED | CLOSED, IN_PROGRESS |
| CLOSED | Ninguno |

WAITING_CUSTOMER y RESOLVED requieren una primera respuesta pública registrada. Resolver exige resolution_summary de 1–5.000 caracteres; reabrir exige reason de 1–2.000 caracteres, auditado. CLOSED es definitivo.

Repetir el estado actual es un no-op: 200 sin nueva auditoría ni cambio de relojes. Otras transiciones inválidas devuelven 409; payload inválido, 422. RESOLVED permite reasignación y notas internas; para edición operativa o respuesta pública debe reabrirse. CLOSED solo permite lectura y replays idempotentes, nunca cambios nuevos.

### Comentarios e idempotencia

body es texto plano de 1–20.000 caracteres. PUBLIC registra una respuesta comunicada al cliente por un agente; INTERNAL es una nota del equipo. Registrar PUBLIC no envía email/WhatsApp ni habilita acceso de clientes: documenta la atención realizada. OpenAPI y Postman deben hacerlo explícito.

PUBLIC requiere un agente elegible y tickets.reply. INTERNAL requiere tickets.comment_internal. La primera respuesta es el primer comentario PUBLIC con fecha del servidor. Ni una nota interna, ni la descripción, ni una asignación cuentan; las respuestas posteriores no cambian first_response_at. Ningún comentario cambia el estado automáticamente.

POST de ticket y comentario requiere idempotency_key no vacía, máximo 120 caracteres. Se delimita por tenant y, para comentarios, ticket. La misma clave/payload normalizado devuelve el recurso existente (200) sin repetir efectos; distinto payload, 409; alta original, 201. La autorización se comprueba antes del replay; un replay válido no vuelve a ejecutar las transiciones ni validaciones temporales del alta original.

## 5. Selección y medición SLA

La política viene de la cola al crear el ticket. Sin política, se crea con sla=null y sigue registrando primera respuesta, pero no objetivos de SLA. No se inventan tiempos predeterminados.

Cada política contiene exactamente una regla para cada prioridad. Los objetivos son minutos enteros positivos, hasta 525.600; primera respuesta no puede superar resolución. calendar_id=null significa ALWAYS en UTC. pause_on_waiting_customer es true por defecto.

Dentro de la transacción del alta se capturan política, regla y calendario activos y se calculan los vencimientos desde created_at. Los cambios posteriores de prioridad, cola o configuración no modifican ese snapshot. Se muestra la prioridad actual y la aplicada al SLA. No hay reaplicación ni SLA retroactivo en este bloque.

Se miden first_response_due_at, first_response_at, resolution_due_at, first_response_breached y resolution_breached. Las banderas verdaderas nunca vuelven a false. La ejecución también conserva el vencimiento incumplido de cada métrica para no perderlo durante pausas/reaperturas.

### Calendario

Los instantes se almacenan en UTC y se devuelven con offset explícito. ALWAYS cuenta tiempo real continuo. BUSINESS usa intervalos semanales en su zona IANA y fechas locales excluidas, sin consultar servicios externos.

weekly_schedule utiliza días ISO 1–7, hasta cuatro intervalos por día, semiabiertos [inicio, fin), sin solapamiento ni cruce de medianoche. 24:00 solo es válido como fin; una jornada nocturna se divide en dos días. Se exige al menos un intervalo semanal. holidays admite hasta 366 fechas distintas YYYY-MM-DD.

Fuera de horario no se consume presupuesto. Un ticket creado fuera de horario comienza a consumir en la siguiente apertura; un plazo que termina al cerrar una jornada vence en ese instante.

Los intervalos locales se materializan en UTC y se cuentan segundos reales. Un límite inexistente por cambio horario se mueve al primer instante válido; un límite ambiguo usa la primera ocurrencia para inicio y la segunda para fin. Se prueba con una zona con horario de verano y con America/Guayaquil.

El cálculo se limita a diez años desde su inicio. Si no alcanza el objetivo, devuelve 422 y revierte la operación; no crea datos parciales ni entra en un bucle ilimitado.

### Pausas, resolución y reapertura

La primera respuesta nunca se pausa. WAITING_INTERNAL sigue consumiendo resolución. WAITING_CUSTOMER pausa solo resolución cuando lo establece el snapshot, y solo se alcanza después de responder.

Antes de pausar se evalúan consumo e incumplimientos. Se guardan presupuesto restante y paused_at; resolution_due_at queda null durante la pausa. Al reanudar se calcula un vencimiento con el presupuesto restante y el calendario capturado. Un incumplimiento anterior conserva su bandera y su vencimiento original.

Responder o resolver exactamente en el vencimiento es puntual; solo una fecha posterior incumple. El job selecciona plazos anteriores a now, no iguales. La acción de respuesta/resolución evalúa su propia fecha incluso si el scheduler estaba detenido.

Resolver detiene la ejecución, conserva el presupuesto restante y guarda resolved_at y el último vencimiento calculado. Si se resuelve desde una espera pausada, no se reanuda el reloj: se mantienen sus datos de pausa en el historial. Cerrar añade closed_at sin reiniciar métricas.

Reabrir RESOLVED conserva snapshot, primera respuesta e incumplimientos. Reanuda el presupuesto de resolución restante; el tiempo resuelto no consume SLA. Si ya estaba incumplido, continúa así. Si quedan cero segundos sin incumplimiento previo (resolución exactamente en el límite), el nuevo vencimiento es el instante de reapertura y se aplica la comparación estricta descrita arriba. No crea otra ejecución ni otra escalación del mismo tipo.

Al reabrir se limpian resolved_at y resolution_summary; la auditoría conserva las resoluciones anteriores y se exige un nuevo resumen al resolver de nuevo.

## 6. Scheduler, concurrencia y escalaciones

support:dispatch-sla se programa cada minuto con withoutOverlapping. Recorre tenants activos y ejecuciones vencidas/despachos pendientes por lotes y encola trabajos en support. No carga todo el historial en memoria ni opera sin un tenant explícito.

El job recibe tenant_id y execution_id, restaura el contexto en finally, verifica que el tenant esté activo y relee el estado bajo bloqueo. Tolera reintentos, concurrencia y tickets resueltos mientras esperaba. Dos respuestas concurrentes no sobrescriben la primera; respuesta y job concurrentes producen el mismo resultado según la fecha efectiva de respuesta.

Cada métrica incumplida genera una sola SlaEscalation mediante restricción única. breached_at guarda el vencimiento incumplido; detected_at, la detección. Si incumplen ambos objetivos, se registran dos escalaciones.

Se captura como destinatarios al agente asignado y al responsable de escalaciones de la cola actual, sin duplicados y solo si son elegibles. Se revalida su elegibilidad antes de despachar, por si cambiaron sus permisos o membresía mientras esperaban. No se envía a todos los usuarios por defecto ni al cliente. Se respetan las preferencias existentes del equipo.

La intención de despacho se persiste separada del incumplimiento, con resultado por destinatario. Estados agregados: pending, dispatched, failed, skipped_no_recipient o skipped_preferences. dispatched confirma la puesta en cola, no la entrega de email; los fallos posteriores pertenecen al sistema de notificaciones existente.

El scheduler recupera pending. Hay tres intentos de despacho; tras fallar el primero espera 60 segundos y tras el segundo, 300. Los trabajos tienen timeout de 120 segundos y una reserva persistente de 300 segundos recuperable al expirar. Tras tres fallos queda failed con error resumido sin credenciales. Una notificación fallida no revierte ni oculta el incumplimiento.

La escalación del dominio es única; la entrega a proveedores es al menos una vez. No se promete exactamente una vez para email ante caídas entre envío y confirmación local. Los destinatarios ya registrados como despachados no se vuelven a procesar en un reintento normal.

Escalar significa registrar el incumplimiento y alertar a los responsables. No hay cambio automático de prioridad ni reasignación. Horizon tendrá supervisor support y documentación del scheduler/worker; la detección puede retrasarse si no corren, pero no cambia la fecha real de incumplimiento.

La auditoría cubre configuración, creación, asignación, estados, primera respuesta, pausas/reanudaciones y escalaciones. No copia cuerpos de comentarios ni datos privados del cliente a logs/notificaciones: usa identificadores y métricas.

## 7. Contrato API y autorización

Todas estas rutas son internas bajo /api/v1, con Bearer y X-Tenant-ID; los parámetros de ruta son IDs numéricos.

| Recurso | Operaciones |
| --- | --- |
| /support/agents | GET, POST; GET/PATCH/DELETE /{agent} |
| /support/categories | GET, POST; GET/PATCH/DELETE /{category} |
| /support/queues | GET, POST; GET/PATCH/DELETE /{queue}; PUT /{queue}/agents reemplaza miembros atómicamente |
| /support/sla-calendars | GET, POST; GET/PATCH/DELETE /{calendar} |
| /support/sla-policies | GET, POST; GET/PATCH/DELETE /{policy}; reglas incluidas y reemplazadas atómicamente |
| /tickets | GET, POST; GET/PATCH /{ticket} |
| /tickets/{ticket}/assign | POST con queue_id y assigned_agent_id, que puede ser null |
| /tickets/{ticket}/status | POST con status y reason/resolution_summary cuando corresponda |
| /tickets/{ticket}/comments | GET, POST |
| /tickets/{ticket}/sla | GET; ejecución o null |
| /support/sla-escalations | GET, solo lectura |

Permisos nuevos:

- support.view y support.manage: lectura/gestión de agentes, categorías, colas y membresías.
- tickets.view: tickets, comentarios y SLA del ticket; es permiso del equipo, no del portal.
- tickets.create, tickets.update, tickets.assign, tickets.reply, tickets.comment_internal, tickets.change_status: acciones correspondientes; cada una requiere además tickets.view.
- sla.view y sla.manage: lectura/gestión de políticas y calendarios; sla.view también permite listar escalaciones.

tickets.view permite leer notas internas al equipo autorizado. La política del portal futuro será independiente. No se expanden perfiles CRM ni mensajes del inbox sin sus propios permisos. Se añaden claves al catálogo y a roles de sistema/administración siguiendo las migraciones existentes, sin ampliar otros roles personalizados.

Listas paginadas: per_page entre 1 y 100, por defecto 25. Tickets filtra q sobre subject, status, priority, category_id, queue_id, assigned_agent_id, unassigned y fechas de creación. Orden permitido: created_at, updated_at, priority; prioridad se ordena URGENT/HIGH/MEDIUM/LOW, con desempate por id. Escalaciones filtra ticket_id, metric y estado de despacho. No se admiten columnas arbitrarias ni SQL del cliente.

ApiResponse conserva 200/201 para éxito; 401 sin sesión; 403 sin permiso; 404 para recurso fuera del tenant; 409 para conflictos de estado/idempotencia/borrado protegido; 422 para payload y referencias inválidas. Las referencias cruzadas no revelan datos del otro tenant.

## 8. Pruebas y criterios de aceptación

Desarrollo con pruebas primero sobre SQLite aislado. Cuando exista PostgreSQL desechable, verificar concurrencia, índices y RLS allí; nunca usar la DATABASE_URL real del usuario para pruebas o rollback.

Casos obligatorios:

1. Catálogos completos; agentes inactivos, sin permiso o de otro tenant rechazados; asignación coherente con cola y bajas protegidas.
2. Alta con/sin SLA; reglas para cuatro prioridades; snapshot inmutable; replay y conflicto de idempotencia.
3. Matriz completa de estados; resumen de resolución, motivo de reapertura y cierre definitivo.
4. INTERNAL no cuenta como respuesta; primer PUBLIC sí; respuestas posteriores y concurrencia conservan la primera fecha.
5. Calendarios 24/7/hábiles: fines de semana, feriados, límites exactos, zonas horarias, cambios de hora y horizonte máximo.
6. Pausa, espera interna, resolución y reapertura conservan presupuesto; incumplimientos no se borran.
7. Respuestas puntuales/tardías y scheduler atrasado; duplicación de jobs, aislamiento/restauración de contexto y notificación fallida.
8. Permisos separados, relaciones cruzadas, filtros/paginación, campos del servidor protegidos y ausencia de filtraciones.
9. Migrar, revertir exclusivamente la nueva migración y migrar de nuevo en base desechable; ejecutar toda la regresión.
10. OpenAPI sin referencias rotas y Postman con captura automática de IDs y flujo ticket → respuesta → espera → reanudación → resolución → cierre.

Las pruebas usan reloj controlado y notificaciones/HTTP simulados. Postman crea sus propios datos sin credenciales externas. No se añaden endpoints de depuración para cambiar el reloj o forzar incumplimientos en producción.

## 9. Entrega y despliegue

El bloque solo estará implementado al entregar migración reversible, modelos, policies, requests, servicios, endpoints, scheduler, jobs, pruebas y documentación coherentes. Se actualizan README, OpenAPI, Postman y análisis de brechas sin marcar todo API-7 terminado.

La migración seguirá las convenciones JSONB y RLS opcional del proyecto, sin sembrar políticas comerciales arbitrarias. Los tiempos de ejemplo pertenecen a pruebas/Postman.

El despliegue requiere aplicar la migración, iniciar el worker support y mantener el scheduler. Durante el desarrollo no se aplican migraciones a la base real, no se envían mensajes reales y no se modifican credenciales ni configuración de conexión.

## 10. Revisión escrita

Comprobar cobertura de las secciones 31–32, alcance acotado, reglas de respuesta/reapertura, comentarios sin envío implícito, permisos, aislamiento e idempotencia. La revisión escrita del usuario precede al plan de implementación; sus ajustes explícitos prevalecen sobre las propuestas de este documento.
