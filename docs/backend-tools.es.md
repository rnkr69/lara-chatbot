# Backend Tools — Guía de implementación

*[English](backend-tools.md) · Español*

> **Nota sobre versiones**: las etiquetas `v2.x` que aparecen en
> este documento referencian milestones internos del periodo pre-0.4,
> no releases públicas. La funcionalidad descrita está en la release `0.4.0`.

> Esta guía cubre el contrato `BackendTool`. Para frontend tools (que
> extienden `BackendTool`) ver [`docs/FRONTEND_TOOLS.es.md`](FRONTEND_TOOLS.es.md).
>
> Se complementa con [`docs/authorization.es.md`](authorization.es.md) (cascada permission → scope →
> tenant → ownership) y [`docs/getting-started.es.md`](getting-started.es.md) (instalación).

---

## 1. Anatomía de una tool

Toda tool del backend implementa la interfaz
`Rnkr69\LaraChatbot\Tools\Contracts\BackendTool`. La forma normal es extender
`BaseBackendTool`, que se ocupa de validar args y aplicar la cascada de
autorización antes de invocar `handle()`.

```php
namespace App\Chatbot\Tools;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class ListMyInvoicesTool extends BaseBackendTool
{
    public function name(): string { return 'list_my_invoices'; }

    public function description(): string
    {
        return 'Lista las facturas del usuario actual con filtros opcionales.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['paid', 'pending', 'cancelled'],
                    'description' => 'Filtra por estado de pago.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Máximo de filas a devolver.',
                ],
            ],
            'required' => [],
        ];
    }

    public function permissions(): array { return ['invoices.read']; }

    public function defaultScope(): AccessScope { return AccessScope::Self; }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $invoices = $this->accessibleQuery(\App\Models\Invoice::query(), $ctx)
            ->when($args['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->limit($args['limit'] ?? 20)
            ->get();

        return ToolResult::success(['items' => $invoices->toArray()]);
    }
}
```

Stubear con:

```bash
php artisan chatbot:make:tool ListMyInvoices            # tool de lectura
php artisan chatbot:make:tool MarkOrderAsPaid --type=write
```

Si `chatbot.tools.auto_discover=true` (default), la tool se registra al
boot escaneando `app/Chatbot/Tools`. Para registrar manualmente:

```php
// AppServiceProvider::boot()
app(\Rnkr69\LaraChatbot\Tools\ToolRegistry::class)
    ->register(\App\Chatbot\Tools\ListMyInvoicesTool::class);
```

---

## 2. Contrato `BackendTool`

| Método           | Descripción                                                                                                         |
|------------------|---------------------------------------------------------------------------------------------------------------------|
| `name()`         | Identificador único (snake_case). El LLM invoca por nombre.                                                          |
| `description()`  | Una sola frase orientada al LLM ("para qué sirve").                                                                  |
| `parameters()`   | JSON Schema mínimo (`type`/`properties`/`required`/`enum`). Validado antes de `handle()`.                            |
| `permissions()`  | Lista AND de permisos. Vacía = tool pública.                                                                         |
| `defaultScope()` | `AccessScope` aplicado a `accessibleQuery()` y a `accessibleUserIds()`.                                              |
| `confirmation()` | `Auto`/`Confirm`/`Manual`. Backend tools v1 sólo soportan `Auto`.                                                    |
| `tenantScope()`  | `bool`. Si `true`, el `ToolRegistry` exige `TenantResolver` registrado al boot. Default `false`.                     |
| `handle()`       | Lógica de la tool. Recibe args ya validados + `ToolContext`. Devuelve `ToolResult`.                                  |

### `ToolResult`

Tres factory methods cubren todos los estados:

```php
ToolResult::success(['items' => $rows]);                  // ok
ToolResult::error('not_owner', 'Pedido no accesible.');   // error
ToolResult::awaitingUser($pendingActionId, '¿Confirmar?'); // sólo FE tools
```

Categorías de error recomendadas (la lista no es cerrada, pero estos
identificadores los entiende el LLM):

- `validation` — args no pasaron la validación. Lo emite `BaseBackendTool`
  automáticamente; no lo emitas tú.
- `unauthorized` — el usuario no tiene permisos (`Authorizer::check`
  devolvió `false`).
- `out_of_scope` — el scope no autoriza acceso al recurso pedido.
- `not_owner` — el recurso existe pero el usuario no es propietario.
- `runtime` — error inesperado durante la ejecución (DB caída, API externa
  off, ...). El mensaje viaja al LLM, no incluyas internals.

---

## 3. Cascada de autorización

`BaseBackendTool::execute()` aplica los pasos en este orden:

1. **Validación de args** — `parameters()` se mapea a Laravel Validator
   vía `JsonSchemaToRules`. Si fallan: `ToolResult::error('validation', ...)`
   sin invocar `handle()`.
2. **Permission check** — `Authorizer::check($user, permissions())`. Si
   falla: `ToolResult::error('unauthorized', ...)`.
3. **Tenant scope** (opcional, gap cross-host) — sólo si `tenantScope()`
   es `true`. Si `TenantResolver` devuelve `[]` → `error('out_of_scope', ...)`.
   Si `null` (bypass) o lista no vacía, continúa.
4. **`handle()`** — recibe args válidos.

**Ownership puntual** lo verifica cada `handle()` en su query (helper
`accessibleQuery()` aplica `whereIn` por scope automáticamente). Para
tools que toman un `target_id` y mutan, el patrón es:

```php
$order = $this->accessibleQuery(Order::query(), $ctx)
    ->where('id', $args['target_id'])
    ->first();

if (! $order) {
    return ToolResult::error('not_owner', 'Pedido no encontrado o no accesible.');
}
```

---

## 4. Tenant scope (gap cross-host)

Si tu host tiene una dimensión adicional de aislamiento (corporación,
evento, espacio...) que se debe combinar con la cascada estándar:

1. Implementa `TenantResolver` (ver [`docs/authorization.es.md`](authorization.es.md) §4).
2. En tu tool, devuelve `tenantScope(): true`.
3. Si la tabla tiene una columna tenant, pásasela a `accessibleQuery()`:

```php
$rows = $this->accessibleQuery($query, $ctx, tenantColumn: 'corporation_id')->get();
```

El `ToolRegistry` hace **fail fast al boot** si una tool con
`tenantScope=true` se registra y no hay `TenantResolver` bind:
`MissingTenantResolverException`.

---

## 5. Patrón Bulk Actions

> **Origen del patrón**: casos de acciones masivas (aprobaciones en lote,
> envíos masivos de email). El soporte real de `confirmation=confirm` para
> backend tools queda en backlog v2; en v1 las tools bulk usan **frontend
> tools** de confirmación que disparan la backend tool una vez confirmada.

### 5.1 Cuándo aplicar

Una tool entra en el patrón bulk cuando acepta `target_ids[]` (más de un
registro) y la operación tiene efectos significativos (escribe, envía
correos, factura, etc.).

### 5.2 Esqueleto de tool bulk

```php
namespace App\Chatbot\Tools;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class ApproveOrdersBulkTool extends BaseBackendTool
{
    public function name(): string { return 'approve_orders_bulk'; }

    public function description(): string
    {
        return 'Aprueba en bloque varios pedidos por sus IDs.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_ids' => [
                    'type' => 'array',
                    'description' => 'Lista de IDs de pedidos a aprobar (máx 100).',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Razón opcional para auditoría.',
                ],
            ],
            'required' => ['target_ids'],
        ];
    }

    public function permissions(): array { return ['orders.approve']; }

    public function defaultScope(): AccessScope { return AccessScope::Team; }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $ids = array_slice((array) $args['target_ids'], 0, 100); // tope duro

        $orders = $this->accessibleQuery(\App\Models\Order::query(), $ctx)
            ->whereIn('id', $ids)
            ->lockForUpdate() // contra carreras
            ->get()
            ->keyBy('id');

        $succeeded = [];
        $failed    = [];

        foreach ($ids as $id) {
            $order = $orders->get($id);

            if (! $order) {
                $failed[] = ['id' => $id, 'reason' => 'not_owner'];
                continue;
            }

            if ($order->status === 'approved') {
                $succeeded[] = ['id' => $id, 'idempotent' => true];
                continue;
            }

            try {
                $order->approve($args['reason'] ?? null);
                $succeeded[] = ['id' => $id];
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => 'runtime'];
            }
        }

        return ToolResult::success([
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'counts'    => [
                'ok'   => count($succeeded),
                'fail' => count($failed),
            ],
        ]);
    }
}
```

### 5.3 Checklist de "qué considerar" en una tool bulk

- [ ] **Idempotencia**: cada item se puede reprocesar sin duplicar efectos
      (chequear estado actual antes de mutar).
- [ ] **Lock pesimista** (`lockForUpdate`) o **CAS** (`where('status', $expected)`)
      para evitar carreras si dos turnos del LLM disparan la misma tool.
- [ ] **Tope duro** en el tamaño del array (`array_slice` o `array_chunk`)
      para protegerte de prompts maliciosos.
- [ ] **Partial-success** explícito en `ToolResult`: `succeeded[]` +
      `failed[]` con razón por item. Nunca abortes el lote por un fallo
      individual sin dejar trazabilidad de los procesados.
- [ ] **Rate limit / quota**: si la operación llama a una API externa,
      considera throttling por tool (futuro `chatbot.limits.rate_limit_per_tool`).
- [ ] **Audit log**: el evento `Chatbot\Events\ToolInvoked` lleva los
      args y el result; un Listener bulk-aware puede registrar partial
      success por item.
- [ ] **Confirmación**: en v1, **no uses `ConfirmationLevel::Confirm` en la
      backend tool**. En su lugar, define una **frontend tool** con
      `confirmation=confirm` que reciba `target_ids[]` y, una vez confirmada
      por el usuario (`chatbot_pending_actions`), invoca la backend
      tool real. Mientras tanto, el LLM puede usar bloques `actions`
      con un botón "Confirmar aprobación de N pedidos" que dispare la
      frontend tool.

### 5.4 Cómo el LLM decide invocar bulk

El system prompt base ya recomienda al modelo "agrupar acciones
similares en un solo tool call cuando sea posible". Refuerza esta regla en
tu addendum (`chatbot.system_prompt.addendum_view`) si tu host tiene tools
bulk:

```blade
@if(in_array('approve_orders_bulk', $tools ?? []))
- Para aprobar más de un pedido a la vez, usa `approve_orders_bulk` con la
  lista de `target_ids`. No emitas `approve_order` en bucle.
@endif
```

---

## 6. Write tools — patrón canónico `create_*` / `update_*` / `delete_*`

> Reads ya están bien cubiertos por §1–§4; esta sección documenta el patrón
> estándar para tools que mutan datos (crear recursos, actualizar campos,
> borrar). Es el segundo escenario más común tras los reads y, sin patrón
> documentado, cada host re-inventa validation, confirm flow y manejo de errores.

### 6.1 Ejemplo de extremo a extremo — `CreateMissionTool`

```php
namespace App\Chatbot\Tools;

use App\Http\Requests\StoreMissionRequest;
use App\Models\Mission;
use Illuminate\Support\Facades\Validator;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class CreateMissionTool extends BaseBackendTool
{
    public function name(): string { return 'create_mission'; }

    public function description(): string
    {
        return 'Create a new mission. Required: origin_planet_id, '
            . 'destination_planet_id, departure_at, eta, ship_id, priority. '
            . 'Optional: risk, hazmat_certified, crew_size, notes. '
            . '**Ask the user for any required field before calling.** Use '
            . '`list_planets` / `list_ships` to resolve names to IDs. '
            . 'Validation errors come back as `error.validation` — re-prompt '
            . 'the user for the offending field, don\'t abort the turn.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'origin_planet_id'      => ['type' => 'integer', 'description' => 'Planet ID. Use list_planets to resolve names.'],
                'destination_planet_id' => ['type' => 'integer'],
                'departure_at'          => ['type' => 'string', 'description' => 'ISO datetime, must be in the future.'],
                'eta'                   => ['type' => 'string', 'description' => 'ISO datetime, must be after departure_at.'],
                'ship_id'               => ['type' => 'integer'],
                'priority'              => ['type' => 'string', 'enum' => ['standard', 'express', 'critical']],
                'risk'                  => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'hazmat_certified'      => ['type' => 'boolean'],
                'crew_size'             => ['type' => 'integer'],
                'notes'                 => ['type' => 'string'],
            ],
            'required' => ['origin_planet_id', 'destination_planet_id', 'departure_at', 'eta', 'ship_id', 'priority'],
        ];
    }

    public function permissions(): array { return ['missions.create']; }

    public function defaultScope(): AccessScope { return AccessScope::Self; }

    public function confirmation(): ConfirmationLevel
    {
        // Non-destructive create (mission lands in draft, fully editable).
        // The LLM is expected to summarize and ask the user before calling.
        return ConfirmationLevel::Auto;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        // 1) Policy gate — reuse the host's MissionPolicy::create.
        if (! $ctx->user->can('create', Mission::class)) {
            return ToolResult::error('unauthorized', 'You cannot create missions.');
        }

        // 2) Validation — reuse the StoreMissionRequest rules via a static class
        //    (see §6.3) so the FormRequest and the tool stay in sync.
        $validator = Validator::make($args, MissionRules::store());
        if ($validator->fails()) {
            return ToolResult::error('validation', $validator->errors()->first());
        }

        // 3) Mutation — the tool decides which fields to auto-fill.
        $mission = Mission::create([
            ...$args,
            'pilot_id' => $ctx->user->getAuthIdentifier(),
            'fleet_id' => $ctx->user->fleet_id ?? null,
            'status'   => 'draft',
        ]);

        // 4) Result — id + short summary so the LLM can confirm and chain.
        return ToolResult::success([
            'mission_id' => $mission->id,
            'summary'    => sprintf(
                'Mission #%d (priority %s) created in draft.',
                $mission->id,
                $mission->priority,
            ),
        ]);
    }
}
```

### 6.2 Confirmation strategy

| Escenario | `confirmation()` | Justificación |
|---|---|---|
| `create_*` no destructivo (queda en draft, editable) | `Auto` | Backend tools en v1 sólo soportan `Auto`. El LLM debe construir un summary explícito ("voy a crear esta mission con estos datos. ¿Procedo?") y esperar ack del usuario en lenguaje natural antes de llamar. |
| `update_*` campo individual | `Auto` | Idem. Reversible y granular. |
| `delete_*` / `cancel_*` / acciones irreversibles | Indirecto via frontend tool | Crea una `FrontendTool` con `confirmation=confirm` que muestra el banner; tras Accept, el handler JS llama al endpoint del host que ejecuta la backend tool real. Mismo patrón que `ConfirmApproveOrdersBulkTool` en [`integrations/backpack.es.md §6.2`](integrations/backpack.es.md). |
| Bulk approve/cancel | Frontend wrapper | Idem. |

### 6.3 Validation rules reutilizables

Para no duplicar reglas entre el `FormRequest` del controller y la tool,
extrae las rules a una clase static:

```php
namespace App\Validation;

class MissionRules
{
    public static function store(): array
    {
        return [
            'origin_planet_id'      => 'required|integer|exists:planets,id',
            'destination_planet_id' => 'required|integer|exists:planets,id|different:origin_planet_id',
            'departure_at'          => 'required|date|after:now',
            'eta'                   => 'required|date|after:departure_at',
            'ship_id'               => 'required|integer|exists:ships,id',
            'priority'              => 'required|in:standard,express,critical',
            'risk'                  => 'nullable|in:low,medium,high',
            'hazmat_certified'      => 'nullable|boolean',
            'crew_size'             => 'nullable|integer|min:1',
            'notes'                 => 'nullable|string|max:500',
        ];
    }

    public static function update(): array
    {
        return [
            // ...
        ];
    }
}
```

El `StoreMissionRequest::rules()` y la tool consumen ambos `MissionRules::store()`.
Cuando cambias una rule, ambos sitios la ven. Sin duplicación.

### 6.4 Required vs Optional en JSON Schema

Marca como `required` SOLO lo estrictamente obligatorio para que la fila
quede en `draft` válido. Todo lo demás (hazmat, notes, insurance) **opcional**
en el schema — eso permite al LLM:

- Crear con lo mínimo de info que el usuario le dio.
- Ofrecer follow-up natural ("¿quieres añadir hazmat / crew_size / notas?")
  via `update_mission` en turns siguientes.

Si marcas todo `required`, el LLM hará 20 preguntas seguidas antes de
poder ejecutar la tool. UX pobre.

### 6.5 Loop conversacional con `error.validation`

> Cuando devuelves `ToolResult::error('validation', '<message>')`, el LLM
> ve `<message>` y **debería re-preguntar al usuario por el campo
> problemático sin abortar el turn**. Ejemplo: si `departure_at must be
> after now`, el LLM responde "El departure_at que diste (X) es pasado.
> ¿Qué fecha futura?" y vuelve a invocar la tool al recibir nueva input.

Para que el LLM entienda esto sin pensarlo, repítelo en la `description()`
de cada write tool — el system prompt del package ya incluye guidance
general, pero la descripción de la tool es lo más localmente visible
cuando el LLM la elige.

### 6.6 Scaffold con `chatbot:make:tool --type=write`

El comando `chatbot:make:tool` ya soporta `--type=write` desde v1.0 y
genera un esqueleto de write tool con TODOs para policy gate, validation
y mutación. Úsalo:

```
php artisan chatbot:make:tool CreateMission --type=write
```

El stub ya recoge los patrones de §6.2 / §6.4 / §6.5. Sólo tienes que
rellenar `description()`, `parameters()`, `permissions()` y `handle()`.

---

## 7. Eventos

Tras cada invocación (incluida la bulk, las MCP y los rechazos por
autorización), el orquestador `ChatService` dispara
`Rnkr69\LaraChatbot\Events\ToolInvoked` con la siguiente forma:

```php
new ToolInvoked(
    user:         $ctx->user,            // Authenticatable
    tool:         $tool,                 // BackendTool (incluye FrontendTool y McpBackendTool)
    args:         $args,                 // array<string, mixed> tal como llegó del LLM
    result:       $toolResult,           // ToolResult final (post-cascada)
    durationMs:   $elapsed,              // float — wall-clock de la invocación
    conversation: $ctx->conversation,    // ?Conversation
);
```

El evento se dispara una vez por tool call, **incluyendo** los casos
`error('unauthorized', ...)`, `error('out_of_scope', ...)` y
`error('validation', ...)` — el listener distingue por
`$event->result->isOk()` vs `isError()`.

Tu host engancha un Listener en su `EventServiceProvider` (o
`AppServiceProvider::boot()`) para añadir trazabilidad, redaction de
PII, métricas, partial-success bulk, etc., sin tocar el paquete:

```php
// AppServiceProvider::boot()
Event::listen(\Rnkr69\LaraChatbot\Events\ToolInvoked::class, function ($event) {
    Log::channel('audit')->info('chatbot.tool', [
        'user_id'  => $event->user->getAuthIdentifier(),
        'tool'     => $event->tool->name(),
        'args'     => $this->redactor->redact($event->args),
        'result'   => $event->result->toArray(),
        'duration' => $event->durationMs,
        'conv_id'  => $event->conversation?->id,
    ]);
});
```

Para tools bulk (§5), el listener puede leer `$event->result->data`
para extraer counts de partial-success y reportarlos a su pipeline de
auditoría. La cardinalidad del evento es 1 por tool call (no por
target dentro del bulk) — la tool agrupa el resultado.

---

## 8. Bridge MCP

Los servidores MCP externos se exponen como `BackendTool` "remotas" con
prefijo `mcp.<server>.<tool>`. La interfaz contractual es la misma; el
host no diferencia una tool local de una remota salvo por el name.
Detalle en [`docs/mcp.es.md`](mcp.es.md).

---

## 9. Pinnable tools (v2.0)

A partir de v2.0 (Personal Dashboard, ver [`dashboard.es.md`](dashboard.es.md))
una tool puede **opt-in** a que los bloques que produce sean fijables al
dashboard del usuario y re-ejecutables periódicamente desde allí. El
contrato `BackendTool` gana un método:

```php
public function pinnable(): bool;
```

`BaseBackendTool::pinnable()` devuelve `false` por defecto — todas las
tools v1 existentes siguen sin emitir el botón 📌. El opt-in es explícito:

```php
public function pinnable(): bool
{
    return true;
}
```

> **Importante** — `pinnable()` sólo aplica si la tool devuelve **al menos
> un block**. El botón 📌 lo emite el orquestador SSE desde `ToolResult::blocks`,
> NO desde el `data` plano. Una tool que hace `ToolResult::success($data)` sin
> el segundo argumento `blocks: [...]` declarará `pinnable() === true` pero
> nunca generará el botón 📌 — el flag queda inerte y nadie se entera hasta
> el E2E. Ver §9.2 para los dos recipes canónicos (tabla / KPI), que SÍ
> emiten blocks. `php artisan chatbot:tools:test <name>` avisa explícitamente
> cuando una tool pinnable devuelve 0 blocks.

### 9.1 Enforcement: `confirmation === Auto`

`pinnable=true` **sólo es respetado** si `confirmation() === Confirmation::Auto`.
Una tool con `Confirmation::Confirm` o `Confirmation::Manual` no debe
poder re-ejecutarse silenciosamente desde el dashboard (eso burlaría la
guardia explícita de UX en el chat). El orquestador marca el block con
`pinnable=false` aunque el método devuelva `true` cuando esa precondición
falla.

Para detectar tools mal configuradas, `php artisan chatbot:tools:list`
emite un warning explícito:

```
WARN  invoice_dunning is pinnable() but confirmation() != Auto — pinnable will be ignored.
```

### 9.2 Ejemplos: tabla pinnable vs KPI pinnable

**Listing-style** (tabla con filas que el usuario quiere ver fresca cada
día):

```php
class ListMyInvoicesTool extends BaseBackendTool
{
    public function name(): string { return 'list_my_invoices'; }
    public function permissions(): array { return ['invoices.view']; }
    public function defaultScope(): AccessScope { return AccessScope::Self; }
    public function pinnable(): bool { return true; }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $rows = $this->accessibleQuery(Invoice::query(), $ctx)
            ->limit((int) ($args['limit'] ?? 20))
            ->get();

        return ToolResult::success(blocks: [[
            'type' => 'table',
            'data' => ['rows' => $rows->toArray()],
        ]]);
    }
}
```

**Stats-style** (KPI que el usuario quiere monitorizar):

```php
class InvoiceStatsTool extends BaseBackendTool
{
    public function name(): string { return 'invoice_stats_this_month'; }
    public function permissions(): array { return ['invoices.view']; }
    public function defaultScope(): AccessScope { return AccessScope::Team; }
    public function pinnable(): bool { return true; }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $total = (float) $this->accessibleQuery(Invoice::query(), $ctx)
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        return ToolResult::success(blocks: [[
            'type' => 'kpi',
            'data' => [
                'label'    => 'Facturación este mes',
                'value'    => $total,
                'format'   => 'currency',
                'currency' => 'EUR',
            ],
        ]]);
    }
}
```

### 9.3 Qué NO declarar pinnable

- Tools `create_*` / `update_*` / `delete_*` (mutaciones). Aunque la
  llamada haya tenido éxito al pin, re-ejecutarla al refresh duplicaría la
  mutación. Estas tools deben quedarse con `confirmation = Confirm`
  o `Manual` — y como consecuencia `pinnable()` se ignora aunque tu opt-in.
- Tools que envían emails, generan PDFs, llaman a APIs externas con coste
  por request, etc. Igual razón.
- Tools cuyo resultado depende fuertemente de un `page_context` que no
  declaras en `pageContextKeys()`. El replay desde `/chatbot/dashboard`
  no tiene contexto de página propio; ver §9.4.

### 9.4 Page context al pin/replay

Si tu tool depende de claves de `page_context` para producir resultados
correctos, declara qué claves necesita:

```php
public function pageContextKeys(): array
{
    return ['tenant_id', 'team_id'];
}
```

El orquestador filtra `page_context` activo a esas claves al emitir el
block (`source.page_context_keys`); el endpoint de pin captura el subset
filtrado en `source.page_context_snapshot`; el `ReplayService` aplica el
snapshot al `ToolContext` antes de invocar `handle()`. Detalle completo en
[`page-context.es.md`](page-context.es.md).

---

## 10. Envelope `meta.*` en blocks (v2.2.1)

Cada entrada de `ToolResult::blocks[]` admite, además de `type` + `data`,
una clave `meta` opcional con un bag `{string: mixed}` libre que el
orquestador propaga **verbatim** al frame `block` del SSE. El widget
bundle lee `meta` como `BlockPayload.meta` y lo expone a la pipeline de
hooks cliente↔cliente. Consumers v1.x que sólo conocen `{type, data}`
ignoran la clave sin error — la adición es aditiva.

```php
return ToolResult::success(
    data: ['widget_id' => $widget->id, 'dashboard_slug' => $dashboard->slug],
    blocks: [[
        'type' => 'card',
        'data' => [
            'title'       => '✅ Added',
            'description' => 'Pinned to the dashboard.',
        ],
        // Opcional, aditivo. Consumers que no conocen la clave la ignoran.
        'meta' => [
            'side_effects' => [
                'type'           => 'widget_added',
                'dashboard_slug' => $dashboard->slug,
                'widget_id'      => (int) $widget->id,
            ],
        ],
    ]],
);
```

### 10.1 Carril canónico: `meta.side_effects`

Las 5 tools del Personal Dashboard conversacional (v2.2) lo usan para que
el bundle del dashboard, cuando está montado en la misma página que el
chat, refresque su UI sin F5 después de cada mutación. Ver
[`dashboard.es.md`](dashboard.es.md).

Tipos emitidos hoy (`side_effects.type`):

- `widget_added`     → `{type, dashboard_slug, widget_id}`
- `widget_updated`   → `{type, dashboard_slug, widget_id, changes[]}`
- `widget_deleted`   → `{type, dashboard_slug, widget_id}`
- `dashboard_updated`→ `{type, dashboard_slug, new_slug?, new_name?, changes[]}`
- `dashboard_deleted`→ `{type, dashboard_slug, was_default, promoted_slug?}`

El widget bundle despacha `chatbot:dashboard-mutation` (CustomEvent en
`document`) con el `side_effects` íntegro como `detail` cuando llega un
frame `block` que lo contenga.

### 10.2 Cuándo añadir un carril nuevo en `meta`

Reserva el envelope para metadata **fuera de banda**: hooks UX que no
caben en `data` (renderizable) ni en `source` (replay). Si una tool propia
necesita que un bundle del host reaccione a su éxito (refrescar una
tabla, levantar un toast, invalidar un cache), puedes:

1. Estampar `meta.<tu_carril>` en el block de éxito.
2. Hacer que el host escuche un evento custom suyo desde un listener
   global de `chatbot:dashboard-mutation` (si reaprovechas el mismo
   evento) o bien dispatch'eando uno propio en el handler de blocks del
   widget bundle.

No usar `meta` para datos que el LLM o el renderer necesiten leer — eso
va en `data`. No usar `meta` para datos del replay — eso lo estampa el
orquestador en `source`, no el tool.
