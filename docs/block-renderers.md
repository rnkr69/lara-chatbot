# Block renderers

*English · [Español](block-renderers.es.md)*

Typed blocks let the LLM emit structured content — tables, cards, action buttons,
arbitrary host-defined widgets — instead of plain markdown. The widget renders
each block through a three-step cascade so hosts can customise as little or as
much as they need.

---

## How a block ends up on screen

The backend has two paths to put a block in front of the user. Both end at the
same renderer cascade in the browser, so picking one is a matter of
ergonomics — not capability.

1. **`RenderBlockTool` (recommended).** The LLM invokes the built-in
   `render_block` frontend tool with `{type, data}`. The orchestrator
   (`ChatService`) emits an SSE `frontend_action` frame with `tool=render_block`.
   The widget intercepts this specific frame and pushes the block into the
   current assistant message — same path as if it had arrived as `event: block`.
2. **`event: block` SSE frame (custom backends).** A custom service can emit
   `SseEvent::block($type, $data)` directly. The widget handles `block` frames
   identically to the intercepted `render_block` action.

Either way, the renderer cascade is what produces the DOM:

```
window.Chatbot.registerBlockRenderer(type, fn)   ← JS renderer wins outright
        ↓ (if not registered)
<template data-chatbot-block-template="<type>">  ← declarative HTML clone
        ↓ (if no template found)
built-in renderer for the type                   ← shipped by this package
        ↓ (if no built-in matches)
[unsupported block: <type>]                      ← muted placeholder
```

A renderer that throws does **not** poison the thread — the cascade continues
with the next step and the error is logged to `console.error`.

---

## Built-in block types

| Type      | Purpose                                         | Required keys      | Optional keys |
|-----------|-------------------------------------------------|--------------------|---------------|
| `text`    | Markdown body (bold/italic/code/links subset).  | `content`          | —             |
| `card`    | Titled summary with key/value fields + actions. | `title`            | `subtitle`, `description`, `fields[]`, `actions[]` |
| `table`   | Tabular data, sortable visually by host CSS.    | `rows[]`           | `columns[]`, `caption`, `empty_text` |
| `list`    | Ordered or unordered items, optionally clickable.| `items[]`          | `title`, `ordered` |
| `actions` | Inline button row that triggers prompts/tools.  | `actions[]`        | —             |
| `chart`   | Chart.js (built-in on every surface since 0.4.4). | `type`/`kind` + `labels`/`categories` + `datasets`/`series`/`points`/`values` | `title`, `options` |

### `text`

```json
{ "type": "text", "data": { "content": "**Listo** — el pedido se actualizó." } }
```

### `card`

```json
{
  "type": "card",
  "data": {
    "title": "Order #142",
    "subtitle": "Pending shipment",
    "description": "Estimated delivery **next week**.",
    "fields": [
      { "label": "Customer", "value": "Acme Inc." },
      { "label": "Total",    "value": 1234.5 }
    ],
    "actions": [
      { "label": "Open", "prompt": "open order 142" }
    ]
  }
}
```

### `table`

`columns` is optional. When omitted, the widget infers headers from the keys of
the first row — handy for ad-hoc LLM responses.

```json
{
  "type": "table",
  "data": {
    "caption": "Recent orders",
    "columns": [
      { "key": "id",       "label": "ID" },
      { "key": "customer", "label": "Cliente" },
      { "key": "total",    "label": "Total" }
    ],
    "rows": [
      { "id": 1, "customer": "Acme",    "total": 99 },
      { "id": 2, "customer": "Globex",  "total": 250 }
    ]
  }
}
```

Empty `rows` renders the value of `empty_text` (default `No rows.`) above the
table headers.

### `list`

```json
{
  "type": "list",
  "data": {
    "title": "Next steps",
    "ordered": true,
    "items": [
      "Review the draft",
      { "text": "Open the dashboard", "prompt": "open dashboard" },
      { "text": "Run audit", "tool": "run_audit", "args": { "scope": "tenant" } }
    ]
  }
}
```

Items with a `prompt` or `tool` render as buttons; plain strings render as
inert text.

### `actions`

```json
{
  "type": "actions",
  "data": {
    "actions": [
      { "label": "Yes", "prompt": "confirm" },
      { "label": "No",  "prompt": "cancel" }
    ]
  }
}
```

Same item shape as inside `card.actions` and `list.items` — choose whichever
container reads best for the conversation.

### `chart`

Since **0.4.4**, Chart.js is the built-in `chart` renderer in **every** bundle —
the floating widget, the `/chatbot` page and the dashboard render charts
identically, out of the box. (Before 0.4.4 only the dashboard bundled Chart.js;
the widget showed a placeholder.) The trade-off is the widget bundle is larger
(~97 KB gzip, up from ~28 KB) because it now includes Chart.js; this is the
accepted cost of consistent charts across surfaces.

`chart.js/auto` registers all controllers, so any supported `type` works:
`line`, `bar`, `pie`, `doughnut`, `radar`, `polarArea`, `bubble`, `scatter`.

```json
{
  "type": "chart",
  "data": {
    "type": "bar",
    "labels": ["Paid", "Pending", "Overdue"],
    "datasets": [{ "label": "Invoices", "data": [12, 5, 3] }],
    "title": "Invoices by status"
  }
}
```

LLM-friendly aliases (normalized internally): `kind` → `type`, `categories` →
`labels`, and `series` / `points` / `values` → a single dataset's `data`.

The **placeholder** now appears only when the data can't be drawn (no usable
`type`, malformed datasets) — it shows the title, a short "chart data is
invalid" note and a collapsible `<details>` with the raw payload. It never says
"renderer not registered" anymore, because a renderer is always present.

**Override with another library.** A host that prefers ApexCharts/ECharts/etc.
can register its own `chart` renderer — it wins the cascade over the built-in:

```js
window.Chatbot = window.Chatbot ?? {};
window.Chatbot.registerBlockRenderer('chart', (data, host) => {
  const canvas = document.createElement('canvas');
  // …draw with your library…
  return canvas;
});
```

### `kpi`

Introduced in v2.0. Renders a single quantitative figure with optional
context (delta vs previous period, trend arrow, caption). Built-in renderer
lives in `resources/js/kpi.ts` and is registered in
`BUILTIN_BLOCK_RENDERERS` — **both** the widget and the dashboard bundle
pick it up through the same cascade. Zero extra registration.

```jsonc
{
  "type": "kpi",
  "data": {
    "label":    "Revenue this month",   // optional (aliases: title|name)
    "value":    1234567,                 // number | pre-formatted string
    "unit":     "USD",                   // optional — auto-detected as ISO currency
    "delta":    0.12,                    // optional — auto-derives trend
    "trend":    "up",                    // optional override: 'up'|'down'|'flat'
    "format":   "currency",              // 'number'|'currency'|'percent'
    "caption":  "vs. last month",        // optional small text
    "locale":   "en-US",                 // optional — defaults to html[lang] or 'en-US'
    "currency": "EUR"                    // optional — overrides unit when both present
  }
}
```

**Rendering rules:**

- `value` numeric without `format` → locale-aware grouping; compact notation
  when `abs(value) >= 100_000` (e.g. `1.23M`).
- `value` string — escape hatch for LLMs that pre-format (`"$1.2B"`). Only
  coerced to number if `format` is set.
- `format: 'percent'` expects a fraction (`0.42 → "42%"`). To render 42 as a
  number with `%` unit, use `format: 'number'` + `unit: '%'`.
- `delta` numeric → formatted with `signDisplay: 'exceptZero'` so positives
  auto-prefix `+`. Strings as-is.
- `trend` explicit wins over `trend` derived from `delta` sign.
- No valid `value` and no `label` → renders minimal `"—"` placeholder.

**PHP-side example** (a stats tool emitting a KPI block — see
[`backend-tools.md`](backend-tools.md) for the surrounding `pinnable()` recipe):

```php
return ToolResult::success(blocks: [[
    'type' => 'kpi',
    'data' => [
        'label'    => 'Active users',
        'value'    => 42_350,
        'delta'    => 1_200,
        'format'   => 'number',
        'caption'  => 'last 24h',
    ],
]]);
```

**i18n:** the placeholder string for "no value resolved" (default `'—'`)
is bridged from PHP via `chatbot::chatbot.dashboard.kpi.no_value` when the
host emits `data-i18n` on `<chatbot-widget>` or
`#chatbot-dashboard-root` — see [`dashboard.md`](dashboard.md).

---

## Customising blocks

### 1. Register a JS renderer (full control)

`registerBlockRenderer` wins over both the host template and the built-in. Use
this when you need event listeners, a charting library, or any logic the
declarative template can't express.

```html
<script>
  window.Chatbot.registerBlockRenderer('order_card', (data, host) => {
    const root = document.createElement('article');
    root.className = 'order-card';
    root.innerHTML = `
      <h3>Order #${data.id}</h3>
      <p>${data.summary}</p>
    `;
    root.querySelector('h3').addEventListener('click', () => host.send(`open order ${data.id}`));
    return root;
  });
</script>
```

Renderers receive `(data, host, meta?)`:

- `data` — the block payload from the SSE frame.
- `host` — `{ send(prompt: string): void }`. **Not** the DOM container — your
  renderer must **return** the `HTMLElement` and the widget appends it.
  `host.send(prompt)` enqueues a follow-up user message exactly as if the user
  typed it; keep it clear in your UI when a click triggers a prompt — users
  find silent submissions disorienting.
- `meta` (optional, since v1.1) — runtime metadata. The relevant field is
  `meta.customError`, set when a previously registered host renderer for the
  same `type` threw and the cascade fell back to the built-in. Use it to
  surface a useful diagnostic — that's exactly what the built-in `chart`
  fallback does (it reports the throw via the placeholder instead of silently
  re-drawing).

> **Common mistake:** assuming `host` is the DOM node and calling
> `host.appendChild(...)`. It is not. Return the element you built; the widget
> wraps it for you.

### 2. Declare a template (no JS)

A declarative alternative for the common case "I want my own markup but no
behavior." Add a `<template data-chatbot-block-template="<type>">` anywhere in
the page; the widget clones it for every matching block and walks every
`[data-bind="path"]` descendant, populating `textContent` from `data` via a
small dot-path lookup (`user.email`, `tags.0`, …).

```html
<template data-chatbot-block-template="order_card">
  <article class="my-order-card">
    <h3 data-bind="title"></h3>
    <p data-bind="description"></p>
    <dl>
      <dt>Customer</dt><dd data-bind="customer"></dd>
      <dt>Total</dt><dd data-bind="total"></dd>
    </dl>
  </article>
</template>
```

The widget adds `block` and `block-<type>` classes to the cloned root if the
template did not already include them, so global widget CSS keeps applying.

Templates live in the host's light DOM (not the widget's shadow root). The
widget re-queries the document every render, so you can add or replace
templates dynamically (Inertia / Livewire SPA navigations work fine).

### 3. Override a built-in

There is no "extend the built-in" hook in v1; calling
`registerBlockRenderer('table', fn)` replaces the built-in `table` renderer
outright. If you want the default styling but custom behavior on a few rows,
copy the source of the built-in (`resources/js/blocks.ts → renderTableBlock`)
into your renderer and patch from there.

---

## End-to-end example: backend → widget

```php
// app/Chatbot/Tools/ListMyOrdersTool.php
class ListMyOrdersTool extends BaseBackendTool
{
    public function name(): string { return 'list_my_orders'; }

    protected function handle(array $args, ToolContext $ctx): ToolResult
    {
        $rows = Order::forUser($ctx->user)->latest()->limit(10)->get(['id', 'customer', 'total'])->toArray();

        // Returning a `table` block in `blocks` lets the orchestrator emit it
        // alongside the assistant text. Alternatively, the LLM can call
        // `render_block` itself with the same shape.
        return ToolResult::success(
            data: ['count' => count($rows)],
            blocks: [[
                'type' => 'table',
                'data' => [
                    'caption' => 'Tus últimos pedidos',
                    'columns' => [
                        ['key' => 'id', 'label' => 'ID'],
                        ['key' => 'customer', 'label' => 'Cliente'],
                        ['key' => 'total', 'label' => 'Total'],
                    ],
                    'rows' => $rows,
                ],
            ]],
        );
    }
}
```

The widget renders the table without any host code — the built-in `table`
renderer handles inferred columns, empty states, and array-vs-object rows.

---

## Cascade reference

| Step | What runs                                                        | When                                                            |
|------|------------------------------------------------------------------|-----------------------------------------------------------------|
| 1    | `window.Chatbot.registerBlockRenderer(type, fn)`                 | The host called `registerBlockRenderer` for this `type`.        |
| 2    | `<template data-chatbot-block-template="<type>">` cloned + bound | A matching template element exists in the document.            |
| 3    | Built-in renderer (`text` / `card` / `table` / `list` / `actions` / `chart`) | The type matches one of the built-ins.                          |
| 4    | `[unsupported block: <type>]` placeholder                        | None of the above matched.                                      |

Each step is best-effort: if step 1 throws, step 2 is tried; if step 2 throws,
step 3 runs.
