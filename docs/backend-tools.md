# Backend Tools — Implementation Guide

*English · [Español](backend-tools.es.md)*

> **Version note**: `v2.x` tags in this document reference internal milestones
> from the pre-0.4 period, not public releases. The functionality described is
> available in release `0.4.0`.

> This guide covers the `BackendTool` contract. For frontend tools (which
> extend `BackendTool`) see [`docs/FRONTEND_TOOLS.md`](FRONTEND_TOOLS.md).
>
> Complements [`docs/authorization.md`](authorization.md) (permission → scope →
> tenant → ownership cascade) and [`docs/getting-started.md`](getting-started.md) (installation).

---

## 1. Anatomy of a tool

Every backend tool implements the interface
`Rnkr69\LaraChatbot\Tools\Contracts\BackendTool`. The standard approach is to extend
`BaseBackendTool`, which handles argument validation and applies the authorization
cascade before invoking `handle()`.

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
        return 'List the current user invoices with optional filters.';
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

Scaffold with:

```bash
php artisan chatbot:make:tool ListMyInvoices            # read tool
php artisan chatbot:make:tool MarkOrderAsPaid --type=write
```

If `chatbot.tools.auto_discover=true` (default), the tool is registered at
boot by scanning `app/Chatbot/Tools`. To register manually:

```php
// AppServiceProvider::boot()
app(\Rnkr69\LaraChatbot\Tools\ToolRegistry::class)
    ->register(\App\Chatbot\Tools\ListMyInvoicesTool::class);
```

---

## 2. `BackendTool` contract

| Method           | Description                                                                                                         |
|------------------|---------------------------------------------------------------------------------------------------------------------|
| `name()`         | Unique identifier (snake_case). The LLM invokes by name.                                                             |
| `description()`  | Single LLM-facing sentence ("what it does").                                                                         |
| `parameters()`   | Minimal JSON Schema (`type`/`properties`/`required`/`enum`). Validated before `handle()`.                            |
| `permissions()`  | AND list of permissions. Empty = public tool.                                                                        |
| `defaultScope()` | `AccessScope` applied to `accessibleQuery()` and `accessibleUserIds()`.                                              |
| `confirmation()` | `Auto`/`Confirm`/`Manual`. Backend tools v1 only support `Auto`.                                                     |
| `tenantScope()`  | `bool`. If `true`, `ToolRegistry` requires a registered `TenantResolver` at boot. Default `false`.                   |
| `handle()`       | Tool logic. Receives validated args + `ToolContext`. Returns `ToolResult`.                                           |

### `ToolResult`

Three factory methods cover all states:

```php
ToolResult::success(['items' => $rows]);                  // ok
ToolResult::error('not_owner', 'Pedido no accesible.');   // error
ToolResult::awaitingUser($pendingActionId, '¿Confirmar?'); // FE tools only
```

Recommended error categories (the list is open-ended, but the LLM understands
these identifiers):

- `validation` — args failed validation. Emitted automatically by `BaseBackendTool`;
  do not emit it yourself.
- `unauthorized` — the user lacks permissions (`Authorizer::check` returned `false`).
- `out_of_scope` — the scope does not authorize access to the requested resource.
- `not_owner` — the resource exists but the user does not own it.
- `runtime` — unexpected error during execution (DB down, external API off, ...).
  The message travels to the LLM; do not include internals.

---

## 3. Authorization cascade

`BaseBackendTool::execute()` applies the steps in this order:

1. **Arg validation** — `parameters()` is mapped to Laravel Validator
   via `JsonSchemaToRules`. On failure: `ToolResult::error('validation', ...)`
   without invoking `handle()`.
2. **Permission check** — `Authorizer::check($user, permissions())`. On
   failure: `ToolResult::error('unauthorized', ...)`.
3. **Tenant scope** (optional, cross-host gap) — only if `tenantScope()`
   is `true`. If `TenantResolver` returns `[]` → `error('out_of_scope', ...)`.
   If `null` (bypass) or a non-empty list, continues.
4. **`handle()`** — receives valid args.

**Point-in-time ownership** is verified by each `handle()` in its query (the
`accessibleQuery()` helper applies `whereIn` by scope automatically). For
tools that take a `target_id` and mutate, the pattern is:

```php
$order = $this->accessibleQuery(Order::query(), $ctx)
    ->where('id', $args['target_id'])
    ->first();

if (! $order) {
    return ToolResult::error('not_owner', 'Pedido no encontrado o no accesible.');
}
```

---

## 4. Tenant scope (cross-host gap)

If your host has an additional isolation dimension (corporation, event,
space...) that must be combined with the standard cascade:

1. Implement `TenantResolver` (see [`docs/authorization.md`](authorization.md) §4).
2. In your tool, return `tenantScope(): true`.
3. If the table has a tenant column, pass it to `accessibleQuery()`:

```php
$rows = $this->accessibleQuery($query, $ctx, tenantColumn: 'corporation_id')->get();
```

The `ToolRegistry` **fails fast at boot** if a tool with `tenantScope=true` is
registered and there is no `TenantResolver` binding:
`MissingTenantResolverException`.

---

## 5. Bulk Actions pattern

> **Pattern origin**: mass-action use cases (batch approvals, bulk email sends).
> Real `confirmation=confirm` support for backend tools is in the v2 backlog;
> in v1 bulk tools use **frontend tools** for confirmation, which then fire the
> backend tool once confirmed.

### 5.1 When to apply

A tool falls into the bulk pattern when it accepts `target_ids[]` (more than one
record) and the operation has significant side effects (writes, sends emails,
invoices, etc.).

### 5.2 Bulk tool skeleton

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
        $ids = array_slice((array) $args['target_ids'], 0, 100); // hard cap

        $orders = $this->accessibleQuery(\App\Models\Order::query(), $ctx)
            ->whereIn('id', $ids)
            ->lockForUpdate() // prevent race conditions
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

### 5.3 Checklist for bulk tools

- [ ] **Idempotency**: each item can be reprocessed without duplicating effects
      (check current state before mutating).
- [ ] **Pessimistic lock** (`lockForUpdate`) or **CAS** (`where('status', $expected)`)
      to avoid races if two LLM turns fire the same tool.
- [ ] **Hard cap** on array size (`array_slice` or `array_chunk`)
      to protect against malicious prompts.
- [ ] **Explicit partial-success** in `ToolResult`: `succeeded[]` +
      `failed[]` with a per-item reason. Never abort the batch on a single
      failure without leaving a trace of processed items.
- [ ] **Rate limit / quota**: if the operation calls an external API,
      consider per-tool throttling (future `chatbot.limits.rate_limit_per_tool`).
- [ ] **Audit log**: the `Chatbot\Events\ToolInvoked` event carries the
      args and result; a bulk-aware Listener can record partial success per item.
- [ ] **Confirmation**: in v1, **do not use `ConfirmationLevel::Confirm` on the
      backend tool**. Instead, define a **frontend tool** with
      `confirmation=confirm` that receives `target_ids[]` and, once confirmed
      by the user (`chatbot_pending_actions`), invokes the real backend tool.
      Meanwhile, the LLM can use `actions` blocks with a "Confirm approval of N
      orders" button that fires the frontend tool.

### 5.4 How the LLM decides to invoke bulk

The base system prompt already advises the model to "group similar actions into
a single tool call when possible". Reinforce this rule in your addendum
(`chatbot.system_prompt.addendum_view`) if your host has bulk tools:

```blade
@if(in_array('approve_orders_bulk', $tools ?? []))
- Para aprobar más de un pedido a la vez, usa `approve_orders_bulk` con la
  lista de `target_ids`. No emitas `approve_order` en bucle.
@endif
```

---

## 6. Write tools — canonical pattern `create_*` / `update_*` / `delete_*`

> Reads are well covered by §1–§4. This section documents the standard pattern
> for tools that mutate data (create resources, update fields, delete). It is the
> second most common scenario after reads, and without a documented pattern each
> host re-invents validation, confirm flow, and error handling.

### 6.1 End-to-end example — `CreateMissionTool`

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

| Scenario | `confirmation()` | Rationale |
|---|---|---|
| Non-destructive `create_*` (lands in draft, editable) | `Auto` | Backend tools in v1 only support `Auto`. The LLM must build an explicit summary ("I am going to create this mission with this data. Shall I proceed?") and wait for a natural-language ack from the user before calling. |
| `update_*` single field | `Auto` | Same. Reversible and granular. |
| `delete_*` / `cancel_*` / irreversible actions | Indirect via frontend tool | Create a `FrontendTool` with `confirmation=confirm` that shows the banner; after Accept, the JS handler calls the host endpoint that executes the real backend tool. Same pattern as `ConfirmApproveOrdersBulkTool` in [`integrations/backpack.md`](integrations/backpack.md). |
| Bulk approve/cancel | Frontend wrapper | Same. |

### 6.3 Reusable validation rules

To avoid duplicating rules between the controller's `FormRequest` and the tool,
extract the rules to a static class:

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

Both `StoreMissionRequest::rules()` and the tool consume `MissionRules::store()`.
When you change a rule, both locations see it. No duplication.

### 6.4 Required vs Optional in JSON Schema

Mark as `required` ONLY what is strictly necessary for the row to be a valid
`draft`. Everything else (hazmat, notes, insurance) **optional** in the schema —
that allows the LLM to:

- Create with the minimum info the user provided.
- Offer natural follow-up ("do you want to add hazmat / crew_size / notes?")
  via `update_mission` in subsequent turns.

If you mark everything `required`, the LLM will ask 20 questions in a row before
it can execute the tool. Poor UX.

### 6.5 Conversational loop with `error.validation`

> When you return `ToolResult::error('validation', '<message>')`, the LLM
> sees `<message>` and **should re-ask the user for the offending field
> without aborting the turn**. Example: if `departure_at must be after now`,
> the LLM responds "The departure_at you gave (X) is in the past. What future
> date?" and invokes the tool again once it receives new input.

For the LLM to understand this without thinking, repeat it in the `description()`
of each write tool — the package system prompt already includes general guidance,
but the tool description is the most locally visible signal when the LLM selects it.

### 6.6 Scaffold with `chatbot:make:tool --type=write`

The `chatbot:make:tool` command has supported `--type=write` since v1.0 and
generates a write tool skeleton with TODOs for the policy gate, validation,
and mutation. Use it:

```
php artisan chatbot:make:tool CreateMission --type=write
```

The stub already embeds the patterns from §6.2 / §6.4 / §6.5. You only need to
fill in `description()`, `parameters()`, `permissions()`, and `handle()`.

---

## 7. Events

After each invocation (including bulk, MCP, and authorization rejections),
the `ChatService` orchestrator fires
`Rnkr69\LaraChatbot\Events\ToolInvoked` with the following shape:

```php
new ToolInvoked(
    user:         $ctx->user,            // Authenticatable
    tool:         $tool,                 // BackendTool (includes FrontendTool and McpBackendTool)
    args:         $args,                 // array<string, mixed> as received from the LLM
    result:       $toolResult,           // final ToolResult (post-cascade)
    durationMs:   $elapsed,              // float — wall-clock of the invocation
    conversation: $ctx->conversation,    // ?Conversation
);
```

The event fires once per tool call, **including** the `error('unauthorized', ...)`,
`error('out_of_scope', ...)`, and `error('validation', ...)` cases — the listener
distinguishes them via `$event->result->isOk()` vs `isError()`.

Your host wires up a Listener in its `EventServiceProvider` (or
`AppServiceProvider::boot()`) to add traceability, PII redaction, metrics,
bulk partial-success recording, etc., without touching the package:

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

For bulk tools (§5), the listener can read `$event->result->data` to extract
partial-success counts and report them to its audit pipeline. Event cardinality
is 1 per tool call (not per target within the bulk) — the tool aggregates the result.

---

## 8. MCP bridge

External MCP servers are exposed as "remote" `BackendTool` instances with the
prefix `mcp.<server>.<tool>`. The contractual interface is the same; the host
cannot distinguish a local tool from a remote one except by name.
Full detail in [`docs/mcp.md`](mcp.md).

---

## 9. Pinnable tools (v2.0)

From v2.0 onwards (Personal Dashboard, see [`dashboard.md`](dashboard.md)),
a tool can **opt-in** to having the blocks it produces pinned to the user's
dashboard and re-executed periodically from there. The `BackendTool` contract
gains a method:

```php
public function pinnable(): bool;
```

`BaseBackendTool::pinnable()` returns `false` by default — all existing v1 tools
continue without emitting the 📌 button. Opt-in is explicit:

```php
public function pinnable(): bool
{
    return true;
}
```

> **Important** — `pinnable()` only applies if the tool returns **at least one
> block**. The 📌 button is emitted by the SSE orchestrator from `ToolResult::blocks`,
> NOT from the plain `data`. A tool that does `ToolResult::success($data)` without
> the second argument `blocks: [...]` will declare `pinnable() === true` but will
> never generate the 📌 button — the flag stays inert and nothing surfaces until
> E2E. See §9.2 for the two canonical recipes (table / KPI) that DO emit blocks.
> `php artisan chatbot:tools:test <name>` explicitly warns when a pinnable tool
> returns 0 blocks.

### 9.1 Enforcement: `confirmation === Auto`

`pinnable=true` is **only honoured** if `confirmation() === Confirmation::Auto`.
A tool with `Confirmation::Confirm` or `Confirmation::Manual` must not be
silently re-executed from the dashboard (that would bypass the explicit UX guard
in chat). The orchestrator marks the block with `pinnable=false` even if the
method returns `true` when this precondition fails.

To catch misconfigured tools, `php artisan chatbot:tools:list` emits an explicit
warning:

```
WARN  invoice_dunning is pinnable() but confirmation() != Auto — pinnable will be ignored.
```

### 9.2 Examples: pinnable table vs pinnable KPI

**Listing-style** (table with rows the user wants refreshed each day):

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

**Stats-style** (KPI the user wants to monitor):

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

### 9.3 What NOT to declare pinnable

- `create_*` / `update_*` / `delete_*` tools (mutations). Even if the pin call
  succeeded, re-executing it on refresh would duplicate the mutation. These tools
  should stay with `confirmation = Confirm` or `Manual` — and as a result
  `pinnable()` is ignored even if you opt in.
- Tools that send emails, generate PDFs, call external APIs with per-request
  costs, etc. Same reason.
- Tools whose result depends heavily on a `page_context` you do not declare in
  `pageContextKeys()`. The replay from `/chatbot/dashboard` has no page context
  of its own; see §9.4.

### 9.4 Page context at pin/replay

If your tool depends on `page_context` keys to produce correct results, declare
which keys it needs:

```php
public function pageContextKeys(): array
{
    return ['tenant_id', 'team_id'];
}
```

The orchestrator filters the active `page_context` to those keys when emitting
the block (`source.page_context_keys`); the pin endpoint captures the filtered
subset in `source.page_context_snapshot`; the `ReplayService` applies the
snapshot to the `ToolContext` before invoking `handle()`. Full detail in
[`page-context.md`](page-context.md).

---

## 10. `meta.*` envelope in blocks (v2.2.1)

Each entry in `ToolResult::blocks[]` accepts, in addition to `type` + `data`,
an optional `meta` key with a free `{string: mixed}` bag that the orchestrator
propagates **verbatim** to the SSE `block` frame. The widget bundle reads `meta`
as `BlockPayload.meta` and exposes it to the client↔client hook pipeline.
v1.x consumers that only know `{type, data}` ignore the key without error — the
addition is additive.

```php
return ToolResult::success(
    data: ['widget_id' => $widget->id, 'dashboard_slug' => $dashboard->slug],
    blocks: [[
        'type' => 'card',
        'data' => [
            'title'       => '✅ Added',
            'description' => 'Pinned to the dashboard.',
        ],
        // Optional, additive. Consumers that do not know the key ignore it.
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

### 10.1 Canonical lane: `meta.side_effects`

The 5 conversational Personal Dashboard tools (v2.2) use this so that the
dashboard bundle, when mounted on the same page as the chat, refreshes its UI
without an F5 after each mutation. See [`dashboard.md`](dashboard.md).

Types emitted today (`side_effects.type`):

- `widget_added`     → `{type, dashboard_slug, widget_id}`
- `widget_updated`   → `{type, dashboard_slug, widget_id, changes[]}`
- `widget_deleted`   → `{type, dashboard_slug, widget_id}`
- `dashboard_updated`→ `{type, dashboard_slug, new_slug?, new_name?, changes[]}`
- `dashboard_deleted`→ `{type, dashboard_slug, was_default, promoted_slug?}`

The widget bundle dispatches `chatbot:dashboard-mutation` (CustomEvent on
`document`) with the full `side_effects` as `detail` when a `block` frame
containing it arrives.

### 10.2 When to add a new lane in `meta`

Reserve the envelope for **out-of-band** metadata: UX hooks that do not fit in
`data` (renderable) or `source` (replay). If a host tool needs a host bundle to
react to its success (refresh a table, raise a toast, invalidate a cache), you can:

1. Stamp `meta.<your_lane>` on the success block.
2. Have the host listen for its own custom event from a global
   `chatbot:dashboard-mutation` listener (if reusing the same event) or by
   dispatching its own in the widget bundle's block handler.

Do not use `meta` for data the LLM or renderer need to read — that goes in
`data`. Do not use `meta` for replay data — the orchestrator stamps that in
`source`, not the tool.
