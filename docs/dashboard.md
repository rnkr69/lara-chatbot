# Personal Dashboard

*English · [Español](dashboard.es.md)*

> Canonical documentation for the Personal Dashboard. If you have questions
> about which tables it introduces, how to enable pinning on a tool, or how
> replay works — this is the place.
>
> Cross-refs: the block model is documented in
> [`block-renderers.md`](block-renderers.md); the authorization cascade in
> [`authorization.md`](authorization.md); the backend tools contract in
> [`backend-tools.md`](backend-tools.md); `page_context` in
> [`page-context.md`](page-context.md). This guide references those documents
> rather than duplicating what is already there.

---

## 1. What it is

Up to v1, the chatbot rendered **tables, charts and KPIs as ephemeral typed
blocks**: they live inside a chat message, scroll out of view, and to see them
again the user has to repeat the question. There is no way to "pin" a useful
result.

v2.0 introduces a **personal dashboard** where the user can:

1. **Pin** any relevant block that appears in the chat (📌).
2. **Place and resize** those elements on a drag-and-drop grid
   (12 columns, gridstack.js).
3. **Have multiple named dashboards** ("Operations", "Executive",
   "My Invoices"…).
4. **Return to the dashboard** and see **fresh, up-to-date data**
   automatically — not the snapshot from the day it was created.

The core technical challenge is **freshness**: v1 blocks carry no metadata
about which tool produced them, so they cannot be "replayed". v2.0 extends the
block contract (`BlockPayload` gains `id`, `source` and `pinnable`) and builds a
**replay engine** that respects the same authorization cascade as the chat
(`permission → scope → tenant → ownership`).

**Expected outcome**: user clicks 📌 on a chat table → it lands on the
dashboard → coming back the next day they see the same numbers but updated,
without having to ask again.

### What v2.0 does NOT introduce

- ❌ Dashboard sharing between users or tenants — possible in v2.1.
- ❌ Automatic polling or permanent live SSE refresh.
- ❌ Dashboard as an embeddable Web Component in host pages — possible in v2.1.
- ❌ Alerts / KPI thresholds.
- ❌ Dashboard export to PDF/image.

---

## 2. End-to-end flow

```mermaid
flowchart LR
    U[User] --> C[Chat widget]
    C -->|question| S[ChatService SSE]
    S --> T[BackendTool::handle]
    T --> S
    S -->|block id+source+pinnable| C
    U -.->|click 📌| C
    C -->|POST /chatbot/dashboards/.../widgets| API[ApiDashboardWidgetController]
    API --> DB[(chatbot_dashboard_widgets)]
    U -->|opens /chatbot/dashboard| D[DashboardController]
    D --> JS[chatbot-dashboard.js]
    JS -->|GET /chatbot/dashboards/{slug}| API
    API --> DB
    JS -->|POST .../refresh SSE| API
    API --> R[ReplayService]
    R --> T2[BackendTool::execute fresh]
    T2 --> R
    R -->|snapshot + status| API
    API -->|SSE widget_refreshed| JS
    JS -->|update DOM| U
```

**Frame anatomy**: when the LLM emits a block, the SSE orchestrator stamps
three extra fields before sending it to the client:

```jsonc
{
  "type": "table",
  "data": { "rows": [/* … */] },
  "id": "b-9f8e7d6c…",          // UUID generated server-side
  "source": {
    "tool": "list_my_invoices",
    "args": { "limit": 20 },
    "page_context_keys": ["tenant_id", "team_id"]
  },
  "pinnable": true                // only if tool->pinnable() = true
}
```

The **tool author does NOT touch `id` or `source`**. The orchestrator injects
them automatically when building each block. The tool only declares whether it
is pinnable (via PHP method, see §4).

---

## 3. Configuring `chatbot.dashboard.*`

The entire section lives under `config/chatbot.php`. After a
`composer update rnkr69/lara-chatbot && php artisan vendor:publish --tag=chatbot-config --force`
you will find:

```php
'dashboard' => [
    'enabled'                   => env('CHATBOT_DASHBOARD_ENABLED', true),
    'max_dashboards_per_user'   => 20,
    'max_widgets_per_dashboard' => 50,
    'snapshot_max_bytes'        => 256 * 1024,
    'replay' => [
        'driver'                         => env('CHATBOT_REPLAY_DRIVER', 'sync'),
        'concurrency'                    => 8,
        'timeout_seconds'                => 15,
        'rate_limit_per_user_per_minute' => 60,
    ],
    'chart_renderer'         => 'chartjs',
    'default_refresh_policy' => 'on_open',
    'layout'                 => env('CHATBOT_DASHBOARD_LAYOUT'),
    'section'                => 'content',
    'mount_widget'           => env('CHATBOT_DASHBOARD_MOUNT_WIDGET', true),
    'back_url'               => env('CHATBOT_DASHBOARD_BACK_URL'),
    'asset_path'             => 'vendor/chatbot/chatbot-dashboard.js',
],
```

| Key | Default | Env var | What it controls |
|---|---|---|---|
| `enabled` | `true` | `CHATBOT_DASHBOARD_ENABLED` | If `false`, the `/chatbot/dashboard*` routes are not registered and `pinnable()` is ignored — typical use case to avoid the extra bundle on hosts that only want the v1 chat. |
| `max_dashboards_per_user` | `20` | — | Hard cap; the creation endpoint returns 422 when the user reaches it. |
| `max_widgets_per_dashboard` | `50` | — | Hard cap per dashboard; the pin endpoint returns 422 when reached (the widget modal maps that 422 to `error_dashboard_full`). |
| `snapshot_max_bytes` | `262144` (256 KB) | — | If `json_encode(snapshot.data)` exceeds this cap, only `data.head` (first 20 items if it was a list) is persisted + a `truncated: true` marker. Replay replaces with fresh data on open. |
| `replay.driver` | `'sync'` | `CHATBOT_REPLAY_DRIVER` | `Illuminate\Support\Facades\Concurrency` driver for bulk-replay. **`sync` (default)** runs replays sequentially in the same process — no serialization, no subprocess, viable on any host. The package picks this driver explicitly; it does **not** inherit the host's `concurrency.default` (which in Laravel 11+ is `process` → `proc_open()`, not viable on Windows/WAMP, shared hosting without `pcntl`, or containers without `proc_open`). A host with the appropriate infrastructure can raise it to `process`/`fork`. |
| `replay.concurrency` | `8` | — | Maximum parallel tools in `replayBulk()`, chunked. With the `sync` driver the cap only controls chunk size (no real parallelism); with `process`/`fork` it limits actual parallelism. |
| `replay.timeout_seconds` | `15` | — | Per-tool timeout during replay. Exceeded → `last_refresh_status='error'` + previous snapshot intact. |
| `replay.rate_limit_per_user_per_minute` | `60` | — | Token-bucket per user on `POST .../refresh` and `POST .../widgets/{id}/refresh`. **Does not apply to CRUD** (list/create/pin/delete) — the real cost is re-executing tools, not writing rows. |
| `chart_renderer` | `'chartjs'` | — | `'chartjs'` bundles Chart.js as the default `chart` block renderer in the dashboard bundle. `'none'` leaves the block without a renderer (the host registers its own via `window.Chatbot.registerBlockRenderer('chart', fn)` before the bundle). See §8. |
| `default_refresh_policy` | `'on_open'` | — | Initial policy when pinning: `on_open` re-executes when opening the dashboard, `manual` requires a ↻ click, `never` stays on the static snapshot. The user can change it per widget via PATCH. |
| `layout` | `null` | `CHATBOT_DASHBOARD_LAYOUT` | If a string AND the view exists, `chatbot::dashboard_layout` extends that layout (`@extends($layout) @section($section)`). If null or the view does not exist, `chatbot::dashboard` is served standalone. Same pattern as `chatbot.page.layout`. **Without a `layout` configured, the dashboard runs standalone — without the host navigation (see §5.2).** |
| `section` | `'content'` | `CHATBOT_DASHBOARD_SECTION` | Section where content is injected when extending the host layout. |
| `mount_widget` | `true` | `CHATBOT_DASHBOARD_MOUNT_WIDGET` | In `layout` mode, mounts the floating `<chatbot-widget>` on the dashboard page itself (via `@push('after_scripts')`) so the user can pin **from** the dashboard. Set to `false` if the host injects the widget itself via `extras_view`. See §5.2. |
| `back_url` | `null` | `CHATBOT_DASHBOARD_BACK_URL` | URL for the "← back to app" link that the **standalone** view renders at the top. `null` = no link. Ignored in `layout` mode (the host chrome provides navigation). |
| `extras_view` | `null` | `CHATBOT_DASHBOARD_EXTRAS_VIEW` | Name of a host Blade view (e.g. `'admin._chatbot_widget'`) that `dashboard_layout.blade.php` `@include`s inside the section, just below the dashboard root. The host view can render markup directly or use `@push('after_scripts')` (this time it lands). See §5.2. |
| `asset_path` | `'vendor/chatbot/chatbot-dashboard.js'` | — | Relative path to the dashboard JS bundle (published by `vendor:publish --tag=chatbot-assets`). |

---

## 4. Enabling `pinnable()` on a tool

By default **no tool is pinnable**. The opt-in is explicit and comes with strict
enforcement: `pinnable=true` is ignored if `confirmation() !== Auto`. Tools that
mutate data (`create_*`, `update_*`, `delete_*`) must keep returning `false`.

### 4.1 Basic recipe

Add the method at the end of your tool:

```php
/**
 * Allows blocks produced by this tool to appear with the 📌 button
 * in the chat and be pinned to the user's dashboard.
 *
 * Only valid if `confirmation() === Confirmation::Auto`. For tools that
 * mutate data or require explicit confirmation, leave the default (false).
 */
public function pinnable(): bool
{
    return true;
}
```

`BaseBackendTool::pinnable()` returns `false` by default — no override needed
in existing v1 tools; upgrading to v2.0 leaves them untouched.

### 4.2 Example: listing-style tool (pinnable table)

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Tools;

use App\Models\Invoice;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class ListMyInvoicesTool extends BaseBackendTool
{
    public function name(): string { return 'list_my_invoices'; }
    public function description(): string { return 'Lists the user\'s invoices.'; }
    public function permissions(): array { return ['invoices.view']; }
    public function defaultScope(): AccessScope { return AccessScope::Self; }
    public function pinnable(): bool { return true; }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $rows = $this->accessibleQuery(Invoice::query(), $ctx)
            ->limit((int) ($args['limit'] ?? 20))
            ->get(['id', 'number', 'amount', 'status', 'issued_at']);

        return ToolResult::success(
            data: ['items' => $rows->toArray()],
            blocks: [[
                'type' => 'table',
                'data' => [
                    'rows' => $rows->toArray(),
                    'columns' => [
                        ['key' => 'number',   'label' => 'No.'],
                        ['key' => 'amount',   'label' => 'Amount'],
                        ['key' => 'status',   'label' => 'Status'],
                        ['key' => 'issued_at','label' => 'Date'],
                    ],
                ],
            ]],
        );
    }
}
```

When executed, the orchestrator stamps `id`, `source` and `pinnable=true` on
the block. The widget shows the 📌 button on hover.

### 4.3 Example: stats-style tool (pinnable KPI)

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Tools;

use App\Models\Invoice;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class InvoiceStatsTool extends BaseBackendTool
{
    public function name(): string { return 'invoice_stats_this_month'; }
    public function description(): string { return 'Total invoiced amount this month.'; }
    public function permissions(): array { return ['invoices.view']; }
    public function defaultScope(): AccessScope { return AccessScope::Team; }
    public function pinnable(): bool { return true; }

    public function parametersSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $query = $this->accessibleQuery(Invoice::query(), $ctx)
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]);

        $current  = (float) $query->sum('amount');
        $previous = (float) Invoice::query()
            ->whereBetween('issued_at', [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ])
            ->sum('amount');

        $delta = $previous > 0 ? ($current - $previous) / $previous : 0.0;

        return ToolResult::success(
            data: ['current' => $current, 'previous' => $previous],
            blocks: [[
                'type' => 'kpi',
                'data' => [
                    'label'    => 'Invoiced this month',
                    'value'    => $current,
                    'format'   => 'currency',
                    'currency' => 'EUR',
                    'delta'    => $delta,
                    'caption'  => 'vs. previous month',
                ],
            ]],
        );
    }
}
```

### 4.4 Enforcement: confirmation === Auto

If the tool's `confirmation()` method returns `Confirmation::Confirm` or
`Confirmation::Manual`, the orchestrator **ignores `pinnable=true`** and marks
the block with `pinnable=false`. The reason is security: a tool that requires
human confirmation before executing in the chat must not be able to re-execute
silently from the dashboard.

To discover misconfigured tools, `php artisan chatbot:tools:list` emits a
warning for each tool where `pinnable()=true && confirmation()!==Auto`:

```
WARN  invoice_dunning is pinnable() but confirmation() != Auto — pinnable will be ignored.
```

### 4.5 Page context on pin

If the tool depends on `page_context` (because it is tied to a specific page —
a customer detail view, a market view), that context is captured automatically
at pin time. There is **no method to declare keys**: when a block is pinned, the
server snapshots **all the string keys** present in the tool's `page_context` at
that moment and records the captured key list in `source.page_context_keys` on
the widget.

The filtered subset is stored in `source.page_context_snapshot`; on replay, only
that captured subset is applied to the `ToolContext` before execution. After
filtering, a binary cap of `chatbot.limits.page_context_kb` (default 16 KB)
applies — if the resulting JSON exceeds it, the whole snapshot is discarded
(`Log::info`). The handler must therefore rely on the context the page exposes
at pin time; anything not present in `page_context` then is not captured and is
unavailable at replay.

Full details in [`page-context.md`](page-context.md).

---

## 5. Frontend: bundle, blade and data-attributes

### 5.1 The `chatbot-dashboard.js` bundle

The dashboard lives in a **separate bundle** from the v1 widget:

| Bundle | CI gzip cap | Notes |
|---|---|---|
| `chatbot-widget.js` | 80 KB | Floating v1 widget + pin button + pin modal. |
| `chatbot-dashboard.js` | 150 KB | Grid + sidebar + WidgetCard + Chart.js default + KPI. |

Loaded only on `/chatbot/dashboard`. **Does not inflate the widget**.

Typical weights in v2.0 (gzip):
- widget: ~28 KB / 80 cap (~52 KB headroom)
- dashboard: ~110 KB / 150 cap (~40 KB headroom)

The CI cap is enforced in `scripts/build.mjs` — the build fails with a clear
error if you exceed it.

### 5.2 Route + blade

`DashboardController` (registered under the `chatbot.dashboard` route name)
serves `GET /chatbot/dashboard`. The view resolves dynamically:

- **Extends host layout** (`chatbot::dashboard_layout`): `@extends($layout)
  @section($section)`. Used when `chatbot.dashboard.layout` points to an
  existing view. **This is the recommended mode in production**: the dashboard
  inherits the host's header/sidebar/navigation. Same pattern as
  `chatbot::page_layout`.
- **Standalone** (`chatbot::dashboard`): full HTML from the package, **without
  the host navigation**. Used when `chatbot.dashboard.layout` is null or the
  view does not exist. This is a **last-resort fallback** — the user who lands
  there has no access to the rest of the app. To avoid stranding them on an
  island, set `chatbot.dashboard.back_url` and the view renders a "← back to
  app" link at the top.

> **v2.1.1 — the widget in `layout` mode.** In `layout` mode, if
> `chatbot.dashboard.mount_widget` is active (default), the
> `dashboard_layout.blade.php` view mounts the floating `<chatbot-widget>`
> itself via `@push('after_scripts')` — without that, the page whose purpose is
> to *collect* pinned blocks would be the only page where you cannot generate
> them. The `@stack('after_scripts')` is exposed by Backpack layouts (the
> documented target for `chatbot.dashboard.layout`); a host with a custom layout
> that does not expose it, or that prefers to inject the widget itself, sets
> `mount_widget` to `false`. **Standalone** mode never mounts the widget under
> any circumstances — `mount_widget` only applies in `layout` mode.
>
> **v2.1.3 — injecting the host widget in `layout` mode.** The widget mounted by
> `mount_widget` is the package's *bare* bundle: it does not load the host JS
> (custom renderers, frontend tools, page context `@chatbotBackpackContext`). A
> host that wants ITS full widget on the dashboard sets `mount_widget = false`
> and registers its own view in `chatbot.dashboard.extras_view` (e.g.
> `'admin._chatbot_widget'`). The view can render `<chatbot-widget>` + its
> scripts directly; or use `@push('after_scripts')` to place them at the end of
> the host layout. The controller validates `View::exists()` and, if the view
> does not exist, degrades gracefully with a log warning and no extras (the page
> keeps rendering). v2.1.3 also fixes the bug where the widget bundle detects the
> shim installed by the dashboard bundle beforehand and upgrades in place — you
> no longer need to load `chatbot-widget.js` from the `<head>` for
> `whenReady`/`registerTool` to work.
>
> _Note — the `@stack('chatbot_dashboard_extras')` stack that v2.1.2 attempted
> to document **has been removed** in v2.1.3. It lived inside the captured
> `@section…@endsection`, so a `@push` from the `$layout` view (the documented
> usage) never reached rendering. If your host pushed against that stack in
> v2.1.2, move that content to a Blade view and register it in
> `chatbot.dashboard.extras_view`._

The optional `?dashboard={slug}` param deep-links a specific dashboard. If the
slug does not exist or does not belong to the user, **it does NOT return 404**
— the page renders the empty state. Consistent policy with `PageController`.

### 5.3 Root `data-*` attributes

```html
<div
    id="chatbot-dashboard-root"
    data-dashboards-endpoint="{{ route('chatbot.dashboards.index') }}"
    data-theme="auto"
    data-chart-renderer="chartjs"
    data-use-bootstrap="0"
    data-debug="0"
    data-i18n="{...payload JSON-encoded...}"
    data-user-id="42"
    data-default-slug="my-panel"
></div>
```

| Attribute | Injected by | What it does |
|---|---|---|
| `data-dashboards-endpoint` | Controller | Base URL for the JSON CRUD. The bundle derives the rest from here. |
| `data-theme` | Controller (config) | `light` / `dark` / `auto` (with `prefers-color-scheme`). |
| `data-chart-renderer` | Controller (config) | `chartjs` or `none`. See §8. |
| `data-use-bootstrap` | Controller (config) | `1` / `0`. Resolved from `chatbot.backpack.use_bootstrap`. See §5.6. |
| `data-debug` | Controller (`app.debug`) | `1` / `0`. The controller keeps emitting it from `config('app.debug')` for forward-compat, but the bundle no longer reads it — v2.1.3 removed the 👁 "View source" button that depended on it (card header cleanup). |
| `data-i18n` | Controller (lang) | JSON with `__('chatbot::chatbot')` — the bundle drains the UI keys. See §5.5. |
| `data-user-id` | Controller (auth) | Mirror of the active user for cross-tab logout detection. |
| `data-default-slug` | Controller | Slug of the dashboard to open by default. Priority: `?dashboard=` → `is_default=true` → null. |

### 5.4 Active dashboard resolution

```
priority: localStorage chatbot:active-dashboard:v1
           → data-default-slug
           → first dashboard of the user (when the sidebar loads the list)
           → null (empty state)
```

`chatbot:active-dashboard:v1` is **per-origin localStorage** (mirroring
`chatbot:active-conversation:v1` from the v1 widget). It changes cross-tab —
if the user navigates between dashboards in one tab, another tab open on
`/chatbot/dashboard` reflects it without reloading.

### 5.5 PHP → JS i18n bridge

The bundle UI keys live in the published lang file:

```php
// resources/lang/{en,es}/chatbot.php → 'dashboard' => [...]
'dashboard' => [
    'sidebar' => ['new_cta' => '+ New dashboard', /* ... */],
    'card'    => ['refresh' => 'Refresh', /* ... */],
    'header'  => ['refresh_all' => 'Refresh all', /* ... */],
    'pin'     => ['cta' => 'Pin to dashboard', /* ... */],
    'chart'   => ['invalid_data' => 'Chart data is invalid…', /* ... */],
    'kpi'     => ['no_value' => '—'],
],
```

`DashboardController` emits the entire array as JSON-encoded `data-i18n`. The
bundle does `JSON.parse` on boot and applies each subtree to the corresponding
mounter (`sidebar`, `widget-card`, `pin-modal`, `kpi.ts`).

**If a key is missing or the attribute is absent**, the bundle falls back to
the inline TS default (English). If the JSON fails to parse, the bundle emits
a `console.warn` with a truncated preview and continues with defaults.

To customise:

```bash
php artisan vendor:publish --tag=chatbot-lang
# edit resources/lang/{locale}/vendor/chatbot/chatbot.php
```

Hosts that embed the v1 widget outside `/chatbot` can also add the bridge
manually:

```blade
<chatbot-widget
    data-endpoint="{{ route('chatbot.stream') }}"
    data-i18n="{{ json_encode(__('chatbot::chatbot'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
></chatbot-widget>
```

If `data-i18n` is not added, the widget keeps working in English.

### 5.6 Theming: host-native Bootstrap vs own CSS

The package re-implements with its own CSS (`.cb-*`) visual primitives —
`card`, `table`, `list` — that a Backpack host already loads via Bootstrap 5.
v2.1 allows those block renderers to use the host's Bootstrap classes instead
of the package CSS, so the dashboard looks like the rest of the admin panel
rather than "an island" with its own aesthetic.

**How it works.** The renderers (`table`/`card`/`list`) always emit both sets
of classes — `cb-table table table-sm table-striped …`. What changes is which
CSS the bundle injects:

- **Own CSS** (default): the bundle injects `block-styles` + the dashboard
  polish. The `table`/`card`/`list-group` classes match nothing (no Bootstrap
  present) and the package's `.cb-*` CSS styles the block.
- **Host-native Bootstrap**: the bundle does **NOT** inject its block CSS. The
  `.cb-*` classes match nothing (their CSS is absent) and the host Bootstrap
  styles the block. No specificity fights — only one set of rules active at a
  time.

**Surface matrix:**

| Surface | Sees the host Bootstrap? | Strategy |
|---|---|---|
| Dashboard in `layout` mode | ✅ Yes — inherits the host `<head>` | Host-native Bootstrap (if `use_bootstrap` activates it). |
| Standalone dashboard | ❌ No — it is its own HTML page from the package | Own CSS (`block-styles`). |
| Floating `<chatbot-widget>` | ❌ No — lives in shadow DOM, isolated by design | Own encapsulated CSS, always. The host Bootstrap does not penetrate the shadow DOM. |

**Config — `chatbot.backpack.use_bootstrap`:**

| Value | Effect |
|---|---|
| `'auto'` (default) | `true` only if the dashboard is in `layout` mode **and** the Backpack package is installed. Standalone mode never has Bootstrap available, so `auto` always resolves to `false` there. |
| `true` | Forces host-native mode. Useful for hosts with a non-Backpack Bootstrap-based layout. |
| `false` | Forces own CSS. Useful for Backpack hosts with a custom theme that prefer the package look. |

Env var: `CHATBOT_BACKPACK_USE_BOOTSTRAP`.

**Recommendation**: on a Backpack host, use `layout` mode
(`chatbot.dashboard.layout`) — this is the "nice" path: the dashboard inherits
the `<head>`, blocks look host-native, and the dashboard shell (sidebar, header,
gridstack grid) keeps its own CSS because Bootstrap does not provide a dashboard
shell. The floating widget always keeps its own encapsulated CSS: it is shadow
DOM by design and the host Bootstrap cannot reach it — theming is done via the
`--cb-*` custom properties.

> **Note** — the dashboard mounts no modals or toasts of its own (it uses
> `window.confirm` for deletion), so there is nothing to delegate to
> `bootstrap.Modal`/`bootstrap.Toast`. The widget's pin-modal lives in the
> widget's shadow root and keeps its own implementation.

---

## 6. Refresh model

Each widget has an independent `refresh_policy`:

| Policy | When replayed | Default for |
|---|---|---|
| `on_open` | When opening `/chatbot/dashboard` (bulk SSE) + on ↻ click | Default on pin (`chatbot.dashboard.default_refresh_policy`). |
| `manual` | Only on ↻ click per widget or "↻ all" | Cases where replay is expensive or the user prefers control. |
| `never` | Never; the persisted snapshot is the only view | Historical data or point-in-time snapshots ("September's invoicing"). |

### 6.1 Bulk refresh (SSE)

When the dashboard is opened, the bundle fires a single request:

```
POST /chatbot/dashboards/{slug}/refresh
```

The server responds with an SSE stream, emitting one `widget_refreshed` frame
for each widget with `refresh_policy='on_open'` (`manual`/`never` are skipped):

```
event: widget_refreshed
data: {"widget_id":11,"status":"fresh","snapshot":{"data":{...},"captured_at":"..."},
       "error":null,"last_refreshed_at":"2026-05-13T10:01:00.000Z"}

event: widget_refreshed
data: {"widget_id":12,"status":"unauthorized","snapshot":null,"error":{...},
       "last_refreshed_at":null}

event: done
data: {"widget_count":2}
```

Parallelism: the server uses `Concurrency::run()` with cap
`chatbot.dashboard.replay.concurrency` (default 8). If a tool exceeds
`replay.timeout_seconds`, it returns `status='error'` and the previous snapshot
is preserved.

**Concurrency driver**: the host should publish `config/concurrency.php` and
choose a driver. `sync` (safe default) executes replays sequentially — works in
any environment (Windows/WAMP, shared hosting, containers without `pcntl`).
`process`/`fork` truly parallelize but require a working subprocess/`pcntl`.
Package tasks are serialization-friendly (they do not capture the
`ReplayService` graph), so any driver is safe. See [`deployment.md`](deployment.md).

### 6.2 Individual refresh

↻ button in each card's header:

```
POST /chatbot/dashboards/{slug}/widgets/{id}/refresh
```

Returns JSON without a stream — a single object under `data` with the **same
flat shape** as the bulk SSE frames (`WidgetRefreshedFrame`):

```json
{ "data": { "widget_id": 11, "status": "fresh",
            "snapshot": { "data": {…}, "captured_at": "…" },
            "error": null, "last_refreshed_at": "2026-05-13T10:01:00.000Z" } }
```

Both refresh endpoints (single JSON and bulk SSE) share a single contract.
Counts as 1 hit in the rate limiter
(`replay.rate_limit_per_user_per_minute`); bulk also counts as 1 hit.

---

## 7. Replay engine

`Rnkr69\LaraChatbot\Dashboard\ReplayService::replay()` is the heart of v2.0.
It takes a persisted widget and returns an immutable `RefreshResult`. Steps:

```
1. Load tool → ToolRegistry::find($widget->source['tool'])
   → if null: status='source_missing', return.
2. Verify pinnable() === true → if not: status='error', return.
3. Apply cascade identical to chat:
   - Authorizer::can($user, $tool->permissions())
   - ScopeResolver::resolve($user, $tool->defaultScope())
   - TenantResolver::resolve($user, $tool, $pageContextSnapshot) (if bound)
   - Tool::handle() applies ownership filter on query
4. Build ToolContext:
   - actionId = new UUID per replay
   - confirmation = 'auto' (strict)
   - pageContext = saved page_context_snapshot
5. Execute → ToolResult
6. Map ToolResult → WidgetRefreshStatus (selection by descriptor):
   - ok + block {block_type, ordinal} exists → snapshot ← new data; status='fresh'
   - ok but tool no longer emits that block → status='stale'; preserve previous snapshot
   - error unauthorized → status='unauthorized'; preserve snapshot
   - error runtime/validation → status='error'; preserve snapshot
7. Persist last_refreshed_at, last_refresh_status, last_refresh_error.
```

`replayBulk(Dashboard, User)` parallelizes with `Concurrency::run()`, chunked
to the configured cap.

> **v2.1.2 — block selection in multi-block tools.** A `pinnable()` tool can
> emit multiple blocks in a single `ToolResult` — the canonical dashboard case
> is `fleet_kpis` returning three `kpi` blocks + a `chart`. The widget does not
> pin to a UUID (the block `id` regenerates on each tool invocation and would
> never match in a subsequent replay); instead it pins to a **descriptor
> `{block_type, ordinal}`**: the orchestrator stamps each `block` frame with a
> 0-based `block_ordinal` — its position *among blocks of its own type* in that
> `ToolResult` — which travels through the pin payload to `source.block_ordinal`.
> `ReplayService::mapResult()` re-selects the N-th block of `widget.block_type`
> by that descriptor. If the tool changed its output and that block no longer
> exists, the widget goes to `stale` with a clear message — a different block is
> **never** persisted as a substitute (that was the bug: always grabbing
> `blocks[0]` silently corrupted data or left the `chart` frozen in perpetual
> `stale`). Widgets pinned before 2.1.2 have no `block_ordinal` and fall back to
> ordinal 0 (the first block of their type) — no data migration needed.

### 7.1 `WidgetRefreshStatus` table

| Status | Meaning | Snapshot shown |
|---|---|---|
| `fresh` | Replay OK, data updated. | New snapshot. |
| `stale` | Tool ran but returned a different block (shape or type changed). | Previous snapshot + ⚠ Stale badge. |
| `error` | Runtime exception, timeout, validation. `last_refresh_error.message` has the detail. | Previous snapshot + Error badge. |
| `unauthorized` | The user lost a permission between pin and refresh. | Previous snapshot + Unauthorized badge. **Never unauthorized fresh data.** |
| `source_missing` | The tool is no longer registered (host removed or renamed it). | Previous snapshot + Source missing badge. |

### 7.2 Authorization cascade

Identical to the chat. The reason is strict: if at pin time the user could run
`list_my_invoices` with `AccessScope::Team` but the next day lost that
permission, replay must respect the loss. Full cascade detail in
[`authorization.md`](authorization.md).

### 7.3 Failure modes that **preserve the previous snapshot**

Every category except `fresh` and `stale` (with same block type) preserves the
persisted snapshot. The reason: you prefer to see old data correctly labelled as
old rather than losing the widget's content. The badge on the card is the signal
to the user.

---

## 8. `chart_renderer` override

The dashboard bundle embeds `chart.js/auto` (~60 KB gzip) as the default `chart`
block renderer. Three modes:

### 8.1 Default (`chart_renderer = 'chartjs'`)

The bundle pre-registers `renderChartBlockChartjs` in the `chart` block cascade.
The host does nothing. Supports types
`line`/`bar`/`pie`/`doughnut`/`polarArea`/`radar` with LLM-friendly aliases
(`series`/`points`/`values` → `datasets[0].data`; `categories` → `labels`).

Expected shape detail in [`block-renderers.md`](block-renderers.md).

### 8.2 Custom renderer (`chart_renderer = 'chartjs'` + override)

If the host wants to use another library (D3, ECharts…), it can register it
**before** the dashboard bundle starts:

```html
<script>
  // Host's own bundle, loaded before the dashboard
  window.Chatbot = window.Chatbot ?? {};
  window.Chatbot.registerBlockRenderer('chart', function(data, host) {
    // ... your implementation
    return /* HTMLElement */;
  });
</script>
<script src="{{ asset('vendor/chatbot/chatbot-dashboard.js') }}" defer></script>
```

When the bundle starts, it detects that `chart` is already registered and does
NOT clobber the override.

### 8.3 No renderer (`chart_renderer = 'none'`)

Useful when the host has its own system and does NOT want to pay the 60 KB
Chart.js cost. The bundle registers nothing for `chart`; if the host registers
nothing either, `chart` blocks show the built-in placeholder
("Chart renderer not registered…").

---

## 9. Operations

### 9.1 Observable statuses

`last_refresh_status` values travel in both the JSON detail and each bulk SSE
frame:

```json
{
  "id": 11,
  "block_type": "table",
  "last_refresh_status": "unauthorized",
  "last_refresh_error": {
    "category": "auth",
    "message": "Missing permission: invoices.view",
    "captured_at": "2026-05-13T10:00:00.000Z"
  }
}
```

The frontend maps each one to a badge + tooltip. The backend never exposes
server-internal messages to the user; details go to `last_refresh_error` in the
`chatbot_dashboard_widgets` table for auditing.

### 9.2 Rate limiting

```php
'rate_limit_per_user_per_minute' => 60,
```

Token-bucket per user, applied **only** to:

- `POST /chatbot/dashboards/{slug}/widgets/{id}/refresh`
- `POST /chatbot/dashboards/{slug}/refresh` (1 hit, not n)

When exhausted, returns 429 with `Retry-After`. Bulk SSE is internally
protected by `replay.concurrency`, so a misconfigured widget cannot accidentally
flood the server.

CRUD (list/create/pin/delete/PATCH layout) is NOT rate-limited — the real cost
is re-executing tools, not writing rows.

### 9.3 Cleanup command

`chatbot:dashboards:prune` performs housekeeping of obsolete rows that accumulate
after months of use. **Without flags the command exits with an error** — you must
always explicitly declare what to prune. Four opt-in modes (combinable in a
single invocation):

| Flag | What it deletes | Threshold | CLI override |
|---|---|---|---|
| `--source-missing` | Widgets with `last_refresh_status='source_missing'` whose `last_refreshed_at < NOW() - N days` (tool disappeared from the registry and is still absent). | `chatbot.dashboard.prune.source_missing_days` (default `30`) | `--source-missing-days=N` |
| `--stale` | Widgets whose `last_refreshed_at` is older than N days OR `null`, excluding those already counted as `source_missing` (pin orphans with no subsequent replay). | `chatbot.dashboard.prune.stale_days` (default `90`) | `--stale-days=N` |
| `--empty-dashboards` | Dashboards created more than N days ago with no active widgets (`whereDoesntHave('widgets')` on the normal SoftDelete scope). | `chatbot.dashboard.prune.empty_dashboard_days` (default `180`) | `--empty-dashboard-days=N` |
| `--purge-soft-deleted` | Hard-delete (`forceDelete()`) of widgets and dashboards whose `deleted_at < NOW() - N days`. Combinable with the others; rows just soft-deleted in the same run are NOT purged (their `deleted_at` is seconds old). | `chatbot.dashboard.prune.purge_soft_deleted_days` (default `30`) | `--purge-older-than-days=N` |

**Dry-run by default.** Without `--force` the command lists candidates in tables
but deletes nothing. With `--force` it executes. Output always ends with three
summary lines: `Mode: EXECUTED|DRY-RUN`, `Soft-deleted: N (would: M)`,
`Hard-deleted: N (would: M)`.

```bash
# Inspect what would be deleted (no execution):
php artisan chatbot:dashboards:prune --source-missing --stale

# Run full housekeeping:
php artisan chatbot:dashboards:prune \
    --source-missing --stale --empty-dashboards --force

# Hard-delete rows soft-deleted more than 14 days ago (aggressive recovery):
php artisan chatbot:dashboards:prune \
    --purge-soft-deleted --purge-older-than-days=14 --force
```

**Soft-delete vs hard-delete**: the first three modes fill `deleted_at` (strict
parity with `DELETE /chatbot/dashboards/{slug}` from the API endpoint);
`--purge-soft-deleted` is the only path to a real `forceDelete`. **Not direct
parity with `chatbot:cleanup-actions`** — that marks `status='expired'` on
`chatbot_pending_actions` without touching `deleted_at`; prune deletes obsolete
dashboard rows.

**Scheduler recipe** (`app/Console/Kernel.php` on the host):

```php
$schedule->command('chatbot:dashboards:prune', [
    '--source-missing', '--stale', '--empty-dashboards', '--force',
])->weekly();

// Optionally, monthly hard-delete of already soft-deleted rows:
$schedule->command('chatbot:dashboards:prune', [
    '--purge-soft-deleted', '--force',
])->monthly();
```

### 9.4 Bundle CI cap

`scripts/build.mjs` measures each bundle post-build and exits with
`process.exit(1)` if it exceeds:

```
Bundle public-build/chatbot-widget.js   :  27.74 KB gzip /  80 KB cap ✔
Bundle public-build/chatbot-dashboard.js: 110.22 KB gzip / 150 KB cap ✔
```

If a PR adds heavy dependencies, the build fails **before merging** — you don't
discover the regression in production. The widget cap protects TTFB on any host
page (the bundle is served on all pages with `<chatbot-widget>`); the dashboard
cap protects `/chatbot/dashboard` (loaded only there, where TTFB is more
tolerable but not infinite). See [`distribution.md`](distribution.md) for the
generic YAML snippet for the host CI pipeline.

---

## 10. Migrating from v1.1.x

### 10.1 Host steps

```bash
composer update rnkr69/lara-chatbot:^0.4
php artisan migrate
php artisan vendor:publish --tag=chatbot-assets --force
# optional: re-publish config to customise dashboard.*
php artisan vendor:publish --tag=chatbot-config
```

### 10.2 What changes and what does NOT

| Aspect | v1.1.x | v2.0 | Host action |
|---|---|---|---|
| `BlockPayload` shape | `{type, data}` | + optional `{id?, source?, pinnable?}` | None. Existing renderers ignore extra fields. |
| `BackendTool::pinnable()` | Does not exist | Default `false` in `BaseBackendTool` | None. v1 tools keep working inertly. |
| Tables | `chatbot_*` v1 | + `chatbot_dashboards`, `chatbot_dashboard_widgets` | `php artisan migrate`. |
| Routes | `/chatbot/*` | + `/chatbot/dashboard*` (only if `chatbot.dashboard.enabled=true`) | None. |
| SSE events | Base frames | + extra fields on `block` and `tool_result` | None; v1 widgets ignore them. |
| Widget bundle | 18 KB gzip | ~28 KB gzip (with pin button + modal + setKpiLabels) | Re-publish assets: `--tag=chatbot-assets --force`. |
| i18n | Inline TS defaults | Optional `data-i18n` + inline defaults as fallback | If you want to translate the bundle, add `data-i18n` to `<chatbot-widget>` (see §5.5). |

### 10.3 Enabling the dashboard on an existing host

1. Run the steps in §10.1.
2. Decide whether to expose the dashboard route: default `enabled=true`, but
   you can set it to `false` if your host does not yet use this feature.
3. For each tool that the LLM emits pinnable results for (tables, KPIs,
   charts), add `public function pinnable(): bool { return true; }`.
   Only on tools with `confirmation() === Auto`.
4. Inject the dashboard bundle **only on `/chatbot/dashboard`** (not required
   on every host page). The v1 widget works without the dashboard bundle.
5. If you add a link to `/chatbot/dashboard` in the host nav, use the key
   `__('chatbot::chatbot.dashboard.menu_label')` ("My pinned dashboard") as
   the text — it differs from `dashboard_title` (the HTML page `<title>`)
   precisely so it does not clash with a "Dashboard" item the host may already
   have in its admin menu.
6. If the host wants the i18n bridge, add `data-i18n` to `<chatbot-widget>`
   in its layouts (§5.5).

### 10.4 Hosts upgrading from v1.1.x without activating the dashboard

If the host wants to stay on v1 features:

```env
CHATBOT_DASHBOARD_ENABLED=false
```

- The `/chatbot/dashboard*` routes are not registered.
- `pinnable()` is silently ignored.
- The dashboard bundle is not served.
- The v1 widget keeps working identically.

The bump to v2.0.0 is still justified by the README "Versioning policy" points
(`BlockPayload` changes shape additively, the `Tool` contract gains a new
method, new migrations) — these are formal changes that require a MAJOR bump
even if they are additive.

---

## 11. Security checklist

Defensive checklist for hosts in production. Each item is a property the
package guarantees; the host must verify in its integration that nothing on its
side breaks it. Coverage of each item lives in
`tests/Feature/Http/DashboardSecurityTest.php` or in the corresponding section
tests.

| # | Property | Package guarantee | Recommended host verification |
|---|---|---|---|
| 1 | **CSRF on POST/PATCH/DELETE** | The `/chatbot/dashboards*` routes inherit the `web` middleware configured in `chatbot.route.middleware` (default `['web', 'auth']`) — `VerifyCsrfToken` is included by Laravel's `web` chain. | If the host mounts the package under a different group (e.g. `api`), ensure CSRF is injected (`Sanctum`/`web`) or use another means to validate origin. |
| 2 | **XSS in `dashboard.name` / `widget.title`** | The package persists and returns strings **raw** (no server-side escaping). The client (`sidebar.ts:181`, `widget-card.ts:287`) uses `textContent`, not `innerHTML` — escaping is the DOM API's responsibility. | If the host rewrites the dashboard bundle or registers its own renderers, do NOT use `innerHTML`/`insertAdjacentHTML` with user strings. For text/card the package uses `renderMarkdown` which HTML-escapes input + validates hrefs (`markdown.ts`). |
| 3 | **XSS in persisted snapshots** | The built-in cascade renderers (`renderTableBlock`, `renderCardBlock`, `renderListBlock`, `renderKpiBlock`, `renderChartBlockChartjs`) use `textContent` for user data; Chart.js renders on `<canvas>` (not HTML). The `text` placeholder and `card.description` go through `renderMarkdown` (HTML-escape + safeHref). | If the host registers `window.Chatbot.registerBlockRenderer(...)`, verify that no path injects `innerHTML` with block data without sanitizing it first. |
| 4 | **Authorization 404-not-403** | All endpoints apply `Dashboard::forUser($user)` before `findOrFail`; widgets belonging to other users return 404 even if the ID is valid. | Confirm that `$user` does not escape the package's `Authorizer` (chat and dashboard share the cascade). |
| 5 | **`page_context_keys` filtering** | On pin, the server snapshots all string keys present in the tool's `page_context` at that moment and records them in `source.page_context_keys`; replay restricts to exactly that captured subset (`source.page_context_snapshot`). After filtering a binary cap `chatbot.limits.page_context_kb` (default 16 KB, full discard + `Log::info`) applies. | The captured set is whatever the page exposes at pin time — there is no tool method to declare it. Keys absent from `page_context` when the block is pinned are not captured and are unavailable at replay. |
| 6 | **`source.args` re-validation on replay** | Replay re-executes `$tool->execute($args, $ctx)` each time; the tool's JSON Schema validates args on every invocation. If the client pins with valid args but the tool fails on refresh (schema changed, runtime error, edge case), the endpoint returns 200 with `last_refresh_status='error'` + `last_refresh_error` and preserves the previous snapshot — **never 500**. | If a tool changes its JSON Schema, widgets pinned before the change automatically degrade to `status=error` — the UI already suggests "re-pin from chat". |
| 7 | **Server-side caps (not opt-out)** | `max_dashboards_per_user` (default 20) and `max_widgets_per_dashboard` (default 50) are enforced in the controllers — an abusive client cannot create infinite rows. | If your host serves many users, consider lowering the defaults via config. |
| 8 | **Replay rate limit** | `RateLimiter` per user on `refresh` + `refreshAll`; bulk SSE counts as 1 hit (not N per widget). **CRUD does NOT enter the throttle** — limit it from your proxy layer if needed. | — |

If you find a property the package should guarantee that is not on this list,
open an issue: §11 lives here precisely to be an observable contract, not a
disclaimer.

---

## 12. Conversational dashboard editing (v2.2)

v2.2 closes the "create + edit from chat" loop by adding five backend tools
that the LLM invokes directly instead of asking the user to use the manual modal
or the card menu. Combined with an auto-inject of page_context on
`/chatbot/dashboard`, the LLM can:

- "Add my KPIs to the panel" → generates + pins the widget in a single action.
- "Move the chart to the right and make it bigger" → moves + resizes.
- "Rename this dashboard to Operations Q1" → rename + slug regen.
- "Remove the missions widget" → soft-delete of the widget.
- "Delete the old panel" → soft-delete of the dashboard + auto-promote-next-default.

### 12.1 New tools

| Tool | `confirmation` | Use case |
|---|---|---|
| `add_to_dashboard` | `Auto` | Resolves `source_tool`, executes it and pins the selected block to the user's dashboard. |
| `edit_widget`      | `Auto` | Move/resize/rename + `refresh_policy` change. Optional args combinable in a single invocation. |
| `delete_widget`    | `Auto`* | Soft-delete of the widget. |
| `edit_dashboard`   | `Auto` | Rename (slug regen) + `is_default` (auto-demote via model hook). |
| `delete_dashboard` | `Auto`* | Soft-delete + auto-promote-next-default. Refuses if it is the user's only dashboard. |

\* **Note on confirmation**: the original plan proposed
`confirmation = Confirm` for `delete_widget`/`delete_dashboard`, which would
emit the orchestrator banner before applying. In v2.2.0 we keep
`confirmation = Auto` because the Confirm flow for backend tools (catalog
filter in `ChatService` + BE-specific SSE banner + `POST /actions/{id}/confirm`
endpoint for BE) is pending the v2.x backlog. The safety net in v2.2 is:

1. **Recoverable soft-delete** at DB level (the row persists; it is purged
   when the `chatbot:dashboards:prune --purge-soft-deleted` cron runs within
   its configurable grace window, default 30 days).
2. **Language in each delete tool's `description()`**: instructs the LLM
   "Before invoking, CONFIRM verbally with the user". The v2.2 system prompt
   (§12.3) reinforces the rule.
3. **`would_create_orphan_default` guard** in `delete_dashboard`: if it is the
   user's only dashboard, it returns an error instead of deleting.

Conservative hosts that want to prohibit deletion from chat set
`chatbot.tools.delete_widget.enabled = false` and/or
`chatbot.tools.delete_dashboard.enabled = false` — the system prompt also stops
mentioning the tool to the LLM.

### 12.2 Activation and opt-out

The 5 tools are automatically registered on boot via
`chatbot.tools.backend_primitives` (parallel to the existing
`frontend_primitives`). Each tool exposes an individual flag:

```php
// config/chatbot.php
'tools' => [
    ...
    'add_to_dashboard' => ['enabled' => true],
    'edit_widget'      => ['enabled' => true],
    'delete_widget'    => ['enabled' => true],
    'edit_dashboard'   => ['enabled' => true],
    'delete_dashboard' => ['enabled' => true],
],
```

Removing a line from `backend_primitives` or setting `enabled => false` omits
the tool from the `ToolRegistry` AND removes it from the system prompt hints
(§12.3).

### 12.3 System prompt hints

When `chatbot.system_prompt.decision_strategy = true` (default), the
`SystemPromptBuilder` appends a **Personal Dashboard — conversational tools
(v2.2)** section with bullets mapping intent → tool:

```
### Personal Dashboard — conversational tools (v2.2)

When the user is on `/chatbot/dashboard` …, match these intents …:

- "add X to my dashboard" / "pin Y" → `add_to_dashboard`.
- "move / resize / rename a widget" → `edit_widget`.
- "remove / delete / unpin a widget" → `delete_widget`. **Confirm verbally**.
- "rename my dashboard" / "set as default" → `edit_dashboard`.
- "delete my dashboard" → `delete_dashboard`. **Confirm verbally**.

The `page_context.dashboard` (auto-injected on the dashboard page) carries the
current slug, widgets, titles and ids. …
```

Bullets for disabled tools (`enabled = false`) are NOT emitted — the LLM does
not propose them to the user. Hosts with a custom `decision_strategy` view
(`decision_strategy: 'host::custom_strategy'`) do NOT receive the hints
automatically; they must replicate them in their view if they want them.

### 12.4 Auto-inject of `page_context.dashboard`

On `/chatbot/dashboard` (both standalone and layout mode), the
`DashboardController` computes the active dashboard context and serializes it in
`data-dashboard-context` on `#chatbot-dashboard-root`:

```json
{
  "slug": "qa-210",
  "name": "QA 2.1.0",
  "is_default": true,
  "widgets": [
    {"id": 8, "title": "Average fare", "block_type": "kpi",
     "position": {"x": 0, "y": 0, "w": 5, "h": 4},
     "refresh_policy": "on_open", "last_refresh_status": "fresh"}
  ]
}
```

The `chatbot-dashboard.js` bundle reads it on boot and calls
`window.Chatbot.setPageContext({dashboard: {...}})`. Because the widget bundle
usually loads AFTER the dashboard bundle (`@push('after_scripts')` vs
`@section('content')`), the dashboard waits for the widget bundle's
`chatbot:ready` event before emitting the page_context — the dashboard shim has
`setPageContext` as a no-op by design.

**Binary cap**: if the dashboard context JSON exceeds
`chatbot.limits.page_context_kb` (default 16 KB), `DashboardController` truncs
the widget list to `{id, title}` and adds `widgets_truncated: true` to the
payload — enough for the LLM to match titles and emit `widget_id`s without
inflating the system prompt.

**Standalone mode without widget**: if the host only mounts the dashboard bundle
(no `<chatbot-widget>`), the `chatbot:ready` event never fires and the
page_context goes unemitted. This is expected behavior: there is no LLM to show
it to.

**page_context refresh coverage** (refined in v2.2.1):

| Trigger | Is `page_context.dashboard` re-emitted? | Mechanism |
|---|---|---|
| Initial boot (`/chatbot/dashboard` loaded) | Yes | `emitDashboardContext()` drains the `data-dashboard-context` attribute rendered by the blade. |
| Switch between dashboards from the sidebar (same tab) | Yes (v2.2.1) | `loadDashboard()` calls `emitActivePageContext()` with the freshly fetched `DashboardDetail`. |
| Conversational mutation (chat invokes one of the 5 v2.2 tools) | Yes (v2.2.1) | The widget bundle dispatches `chatbot:dashboard-mutation`; the dashboard bundle listener calls `loadDashboard()` / `emitActivePageContext()` as appropriate. See §12.6. |
| Direct UI mutation (gridstack drag/resize, inline rename in sidebar, "Remove" click on card) | **No** | Pending limitation. Backlog v2.3: subscribe to the bundle's internal events to re-emit page_context without a full reload. |

While the last row remains open, the flow "rename the widget by dragging inline
in the sidebar then ask the LLM to move it by its new title" may fail — the LLM
sees the old title. The `widget_id` remains stable, so "move it to the right"
(resolved by id) keeps working even with a desynced title.

### 12.5 Internal services (refactor)

PR-A and PR-B extract three dashboard services, shared between the existing HTTP
controllers and the new tools:

- **`Rnkr69\LaraChatbot\Dashboard\PinService`** — the pin logic (cap, pinnable
  enforcement, snapshot truncation, page_context filtering, source signature,
  persist + touch). Previously inline in `ApiDashboardWidgetController::store`.
  Throws `PinException` with categories `cap_reached` / `not_pinnable`.
- **`Rnkr69\LaraChatbot\Dashboard\WidgetCrudService`** — selective `update()`
  (position/title/refresh_policy) and soft `delete()`. Previously inline in
  `ApiDashboardWidgetController::update/destroy`.
- **`Rnkr69\LaraChatbot\Dashboard\DashboardCrudService`** — `update()` (rename
  + slug regen + is_default), `delete()` (soft + auto-promote-next-default) and
  `deriveUniqueSlug()`. Previously inline in `ApiDashboardController`.

Plus the `WidgetPositionNormalizer::normalize($raw, $blockType)` helper that
replaces the `preparePosition` logic that was duplicated between `store` and
`update`.

**No HTTP contract change**: controller responses (incl. error shapes) are
identical to v2.1.x; the `ApiDashboardControllerTest` and
`ApiDashboardWidgetControllerTest` suites pass without modification.

### 12.6 Cross-client signal `chatbot:dashboard-mutation` (v2.2.1)

The 5 conversational tools mutate the server but the dashboard already mounted
on the same page does not know by itself — it needs a client↔client bridge.
v2.2.1 solves this with a generic `meta.side_effects` layer on the SSE `block`
frames:

1. **Backend (tool author)** — each of the 5 tools stamps a `meta.side_effects`
   descriptor on the `card` block it already returned on success:

   ```php
   return ToolResult::success(
       data: [...],
       blocks: [[
           'type' => 'card',
           'data' => ['title' => '✅ Added', 'description' => '...'],
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

2. **Orchestrator (`ChatService`)** — propagates `meta` verbatim to the SSE
   stream's `block` frame. The payload gains a `meta` key when the tool stamped
   one (omitted when not, for back-compat with v1/v2.0/v2.1 consumers).

3. **Widget bundle** — when a `block` frame arrives with `meta.side_effects`
   containing a `type` string, it dispatches a `CustomEvent` on `document`:

   ```js
   document.dispatchEvent(new CustomEvent('chatbot:dashboard-mutation', {
     detail: { type: 'widget_added', dashboard_slug: 'ops', widget_id: 42 },
   }));
   ```

4. **Dashboard bundle** — listener registered in `startDashboardApp` that
   switches on `detail.type`:

   | `detail.type`        | Action |
   |---|---|
   | `widget_added` / `widget_deleted` | Reload the active dashboard if `dashboard_slug` matches + `sidebar.refresh()` (updates widget count). |
   | `widget_updated`     | Reload the active dashboard if it matches. |
   | `dashboard_updated`  | `sidebar.refresh()` + (if `new_slug`) `history.replaceState({dashboard: new_slug})` + update `<h1>` with `new_name` + `emitActivePageContext()`. |
   | `dashboard_deleted`  | `sidebar.refresh()` + if it was the active dashboard, switch to `promoted_slug` or the first available (or empty state). |

**Other consumers of the rail**: the `meta` envelope is generic — future
client↔client hooks in the package (cross-tab notifications, events to other
host bundles) can stamp other keys under `meta` without touching the SSE
protocol. Today the only canonical key is `meta.side_effects`. Consumers that
do not know a key ignore it without error.

---

## Quick references

- Replay engine code: `src/Dashboard/ReplayService.php`
- Eloquent models: `src/Models/Dashboard.php`, `src/Models/DashboardWidget.php`
- Frontend bundle: `resources/js/dashboard/`
- Pest backend tests: `tests/Feature/Dashboard/`, `tests/Unit/Dashboard/`
- Vitest frontend tests: `tests/js/dashboard/`
- Playwright E2E tests: `tests/e2e/dashboard.spec.ts`, `pin-from-chat.spec.ts`
- Config: `config/chatbot.php` → `'dashboard'`
- Lang: `resources/lang/{en,es}/chatbot.php` → `'dashboard'`
