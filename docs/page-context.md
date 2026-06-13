# Page Context API

*English · [Español](page-context.es.md)*

> The **page context** is the set of metadata that the host passes to the
> chatbot so the LLM knows which screen the user is currently viewing.

The package supports three channels for declaring context: the **declarative
meta tag**, the widget's **imperative API**, and the **opt-in Backpack
integration** for hosts that use that admin panel. Any change emits a global
`chatbot:context-changed` event that integrations can listen to.

---

## 1. Declarative meta tag

The simplest approach. In the page `<head>` (typically from the host's Blade
layout):

```html
<meta name="chatbot:context"
      content='{"route":"invoices.index","filters":{"status":"open"}}'>
```

The widget reads it on boot (`connectedCallback`) and, in SPA mode, also on
every detected navigation. The JSON must start with `{` (a top-level object);
anything else is silently ignored.

---

## 2. Imperative API (`window.Chatbot`)

For SPAs or dynamic screens that change context without a full reload:

```js
// Replaces or adds keys; one-level-deep merge.
window.Chatbot.setPageContext({
  route: 'invoices.show',
  invoice_id: 999,
});

// Clears the entire effective context.
window.Chatbot.clearPageContext();
```

`setPageContext()` performs a **one-level-deep merge**. Top-level keys from
the argument are added or overwritten, and keys absent from the argument are
preserved. Additionally, when both the previous and the incoming value at a
top-level key are **plain objects** (not arrays, not `null`), their sub-keys
are merged rather than the whole object being replaced. Arrays and primitives
still replace wholesale.

```js
window.Chatbot.setPageContext({ route: '/orders', tenant: 7 });
window.Chatbot.setPageContext({ tenant: 9, locale: 'es' });
// Effective state: { route: '/orders', tenant: 9, locale: 'es' }

// Nested plain objects merge one level deep:
window.Chatbot.setPageContext({ crud: { entity: 'Mission', filters: {} } });
window.Chatbot.setPageContext({ crud: { selected_ids: [1, 2] } });
// Effective state:
// { crud: { entity: 'Mission', filters: {}, selected_ids: [1, 2] } }
// (crud.entity and crud.filters survive — the crud object was NOT replaced)
```

---

## 3. SPA hook and `chatbot:context-changed` event

Any change to the effective context fires a `CustomEvent` on `window` with
the context in `event.detail`:

```js
window.addEventListener('chatbot:context-changed', (e) => {
  console.log('Page context is now:', e.detail);
});
```

The event is emitted in **two** situations:

1. Every call to `setPageContext()` or `clearPageContext()`.
2. In SPA mode, after each detected navigation (`inertia:navigate`,
   `livewire:navigated`, `popstate`): the widget re-reads the meta tag and,
   if its content changed, internally calls `setPageContext()` — which in
   turn emits the event.

The widget also aborts the active stream on each SPA navigation to avoid
half-rendered responses against a stale route.

> **MPA note**: in MPA mode each page load restarts the cycle. The meta tag
> is read at `connectedCallback` and the event is emitted once per load.

---

## 4. Backend sanitization

The `POST /chatbot/stream` controller applies two passes to the `page_context`
field of the request:

### 4.1 Type-by-type (`PageContextSanitizer`)

Only the following survive:

| PHP type | Survives? |
|---|:-:|
| `string` (including opaque HTML) | ✅ |
| `int` | ✅ |
| finite `float` | ✅ |
| `bool` | ✅ |
| `array` (associative or list) whose elements also survive | ✅ |
| `null` | ❌ discarded |
| `object` (including `Closure`) | ❌ discarded |
| `resource` | ❌ discarded |
| `NaN` / `±INF` | ❌ discarded |

Keys in an associative array are coerced to `string`; lists retain consecutive
integer keys (gaps are re-indexed).

The default maximum depth is 8 levels (configurable by the host by overriding
`PageContextSanitizer::sanitize($raw, $maxDepth)`). Deeper levels are pruned.

### 4.2 Binary truncation (fallback)

If after sanitizing the resulting JSON still exceeds
`chatbot.limits.page_context_kb` (default **16 KB**), it is discarded entirely
(replaced with `[]`) and logged with `Log::info`. A 422 is **not** returned —
the turn continues without context. The rationale: prefer degrading gracefully
over breaking the UX when the host accidentally sends an overly large context.

### 4.3 Injection into the system prompt

`SystemPromptBuilder` programmatically appends the `## Current page` section
with the sanitized JSON at the end of the base prompt, before the language
instruction. This section does NOT live in the publishable view
(`resources/views/system_prompt.blade.php`) because the host may override it —
and the package contract must survive that override.

```text
You are a helpful assistant integrated into a Laravel application…

## Current page
The user is currently looking at the following page (host-declared, sanitized):
```json
{
    "route": "invoices.show",
    "invoice_id": 999
}
```

Always respond in Spanish unless the user explicitly requests another language.
```

---

## 5. Backpack integration (opt-in)

If the host uses [`backpack/crud`](https://backpackforlaravel.com), the package
exposes a Blade directive and a provider that populate the meta tag with data
from the current `CrudPanel`.

### 5.1 Activation

Nothing to install. If the class
`Backpack\CRUD\app\Library\CrudPanel\CrudPanel` exists at runtime,
`ChatbotServiceProvider` automatically registers:

- the singleton `Rnkr69\LaraChatbot\Integrations\Backpack\BackpackPageContextProvider`,
- the Blade directive `@chatbotBackpackContext`.

If Backpack is not installed, both are silent no-ops (the host can place the
directive in their layout without breaking non-admin pages).

### 5.2 Usage from Blade

```blade
{{-- In your admin layout (e.g. resources/views/admin/layout.blade.php) --}}
<head>
    @chatbotBackpackContext
    {{-- ...rest of head, including the widget script --}}
</head>
```

The directive renders, server-side, a `<meta name="chatbot:context">` with the
following shape:

```json
{
  "crud": {
    "entity": "App\\Models\\Invoice",
    "action": "list",
    "filters": { "status": "open" },
    "selected_ids": [11, 22, 33]
  }
}
```

Empty fields are omitted to keep the meta tag compact. If the panel is not
resolved (non-admin page, boot error, etc.) the directive emits an empty
string.

### 5.3 Recommended conventions

To allow host tools to react to Backpack context, it is recommended to annotate
grids and rows with `data-chatbot-*` attributes in the CRUD Blade views:

```blade
<table data-chatbot-target="crud-grid">
    @foreach($entries as $entry)
        <tr data-chatbot-context='{"id":{{ $entry->id }}}'>
            {{-- columns --}}
        </tr>
    @endforeach
</table>
```

The full guide with an end-to-end example (grid → bot offers bulk action → bot
fires FE tool on the selected rows) lives in
[`docs/integrations/backpack.md`](integrations/backpack.md).

---

## 6. Tests

| Suite | Coverage |
|---|---|
| Pest unit `tests/Unit/Services/PageContextSanitizerTest.php` | preserved/dropped types, recursion, depth, re-indexed lists |
| Pest feature `tests/Feature/Http/ChatControllerStreamTest.php` | sanitizer in the pipeline + binary truncation fallback |
| Pest feature `tests/Feature/Services/ChatServiceTest.php` | changing page_context between two turns changes the system prompt |
| Pest feature `tests/Feature/Integrations/BackpackProviderShapeTest.php` | provider with CrudPanel mock (entity/action/filters/selected_ids) |
| Pest feature `tests/Feature/Integrations/BackpackIntegrationTest.php` | `@chatbotBackpackContext` directive and graceful degradation without Backpack |
| Vitest `tests/js/page-context.test.ts` | meta tag reading and event dispatch |
| Vitest `tests/js/api.test.ts` | one-level-deep merge + `chatbot:context-changed` emission on set/clear |
| Vitest `tests/js/widget.test.ts` | initial seed from meta tag + re-read on `inertia:navigate` |

---

## 7. Page context in pin/replay (v2.0)

Starting with v2.0 ([Personal Dashboard](dashboard.md)), a tool can be
`pinnable` and therefore re-executed from `/chatbot/dashboard`. The replay
happens on a page that **has no `page_context` of its own** (the dashboard is
agnostic to the context of the page where the pin was made). Without
intervention, tools that depend on `page_context` would return generic or empty
results on refresh.

v2.0 resolves this in three steps:

### 7.1 No keys to declare — capture is automatic

There is **no tool method to declare context-sensitive keys**. When a block is
pinned, the server snapshots **all the string keys** present in the tool's
`page_context` at that moment. The auto-pin tool (`AddToDashboardTool`) derives
the list with `array_values(array_filter(array_keys($ctx->pageContext),
'is_string'))`; the captured list is recorded as `source.page_context_keys`, and
replay restricts the context to exactly that subset. A tool cannot opt into a
narrower set.

### 7.2 Stamping onto the block at pin time

The captured key list is stored in the block's `source`:

```jsonc
{
  "type": "kpi",
  "data": { /* ... */ },
  "source": {
    "tool": "sales_this_month",
    "args": {},
    "page_context_keys": ["tenant_id", "team_id"]
  },
  "pinnable": true
}
```

For the HTTP pin path (`POST /chatbot/dashboards/{slug}/widgets`), the JS client
sends `source.page_context_keys`; the server reads that list verbatim. For the
chat auto-pin path, the server computes it from the live context as described
above. Either way the value reflects the keys actually present at pin time, not
a tool-declared whitelist.

### 7.3 Capturing on pin, applying on replay

When a block is pinned, the server receives the full `page_context` and:

1. Applies `PageContextSanitizer` (drops closures/objects/etc.).
2. **Filters to the captured `source.page_context_keys`** (the string keys that
   were present at pin time).
3. Applies the binary cap `chatbot.limits.page_context_kb` (default 16 KB) — if
   it still exceeds the limit after filtering, it is discarded entirely with a
   `Log::info` (losing context is preferable to breaking the pin).
4. Persists the filtered subset in `source.page_context_snapshot` in
   `chatbot_dashboard_widgets`.

On replay, the `ReplayService` (see [`dashboard.md`](dashboard.md)) builds a
`ToolContext` with the saved snapshot, so the tool sees exactly the subset that
was present when the pin was made.

### 7.4 What happens when keys are missing or the snapshot expires

- **Keys absent from the snapshot at replay time**: the tool receives a partial
  `page_context`; if its `handle()` requires specific keys and graceful
  degradation is not possible, it should return
  `ToolResult::error('validation', …)` → the replay marks the widget with
  `last_refresh_status='error'` and retains the previous snapshot.
- **Tenant resolver no longer matches**: if the `TenantResolver` no longer
  accepts the snapshot `tenant_id` (because the user lost access or the entity
  was deleted), the authorization cascade returns unauthorized and the status
  is `unauthorized` — previous snapshot preserved.

The rationale for filtering to `source.page_context_keys` is to replay each
widget with exactly the context subset that was captured at pin time, rather
than the dashboard's (absent) ambient context. **If the tool's `page_context`
is empty when the block is pinned, its `page_context_snapshot` will be empty** —
equivalent to re-executing without context. Tools that depend on context will
only refresh correctly if the relevant keys are present in `page_context` at the
moment of pinning.
