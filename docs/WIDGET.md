# `<chatbot-widget>` — Web Component

*English · [Español](WIDGET.es.md)*

Bundle distributed by the package that mounts the chatbot on any Laravel page
(or static HTML page) with no runtime dependencies.

- **Entry**: `public-build/chatbot-widget.js` (ES module).
- **Size**: ~28 KB gzip (raw ~101 KB) (cap: 80 KB).
- **Compatibility**: browsers with ES2020 + Custom Elements v1 support
  (Chrome ≥80, Firefox ≥75, Safari ≥13.1, Edge ≥80).

## Quick setup

1. Build the bundle (only needed when working with source; the host does not
   need Node if it consumes the package via Composer and publishes the assets):

   ```bash
   npm install
   npm run build
   ```

2. Publish the asset on the host:

   ```bash
   php artisan vendor:publish --tag=chatbot-assets
   ```

3. Include the script in your Blade layout:

   ```blade
   <chatbot-widget
       data-endpoint="{{ route('chatbot.stream') }}"
       data-position="right"
   ></chatbot-widget>

   @auth
       <meta name="csrf-token" content="{{ csrf_token() }}">
   @endauth
   <script type="module" src="{{ asset('vendor/chatbot/chatbot-widget.js') }}"></script>
   ```

## Custom element attributes

| Attribute | Values | Default | Description |
|---|---|---|---|
| `data-endpoint` | string (URL) | _required_ | POST endpoint (`/chatbot/stream`). |
| `data-conversation-id` | string\|number | `null` | Conversation id to resume. If empty, the backend creates a new one. |
| `data-conversations-endpoint` | string (URL) | derived from `data-endpoint` | Base URL for the conversation list/CRUD (`chatbot.conversations.index`). Required to rehydrate history when the widget is remounted after an MPA navigation. If empty and `data-endpoint` ends in `/stream`, it is derived by replacing `/stream` with `/conversations` (package canonical pattern). Declare it explicitly if your routes do not follow that pattern. |
| `data-position` | `left` \| `right` | `right` | Launcher fab side. |
| `data-default-open` | `true` \| `false` | `false` | Whether the panel opens on load. |
| `data-theme` | `auto` \| `light` \| `dark` | `auto` | Widget color mode. `light`/`dark` force the mode ignoring context. `auto` resolves in this order: (1) `<html data-bs-theme>` on the host if present — canonical integration with Bootstrap 5 / Tabler / Backpack-Tabler / AdminLTE / Filament; (2) OS `prefers-color-scheme`. In `auto`, the widget observes runtime changes to `<html data-bs-theme>` (and the OS media query) and updates without a reload. |

The internal attribute `data-state` (managed by the component) reflects the
4-state machine: `closed` · `minimized` · `open` · `fullscreen`. The shadow DOM
CSS exposes the selectors `:host([data-state="..."])` so hosts with published CSS
can fine-tune their layout.

`data-theme-effective` (also managed by the component, values `light`
| `dark`) reflects the resolved color mode after applying the `data-theme`
cascade. The shadow DOM CSS exposes selectors
`:host([data-theme-effective="dark"])` / `…="light"` that override the defaults
and the `@media (prefers-color-scheme: dark)` block. Do not set this attribute
manually — the widget projects it and keeps it in sync with the host toggle.

## Global API `window.Chatbot`

The bundle installs an idempotent global API. If the script is included twice
(a common mistake with host bundles), the second load preserves the first and no
registrations are lost.

```js
window.Chatbot.open();                 // opens the panel
window.Chatbot.close();                // closes it
window.Chatbot.toggle();               // toggles open/closed

window.Chatbot.setPageContext({        // context sent to the backend
    route: 'admin/users/show',         // on every POST to /chatbot/stream
    user_id: 42,
});
window.Chatbot.clearPageContext();

window.Chatbot.setUser('eyJhbGciOi…'); // bearer token (Sanctum/JWT). null clears.

// Replace or extend a FE primitive. If the host registers `navigate`,
// the bundle delegates to the host handler instead of using the default primitive.
window.Chatbot.registerTool('navigate', (args, ctx) => {
    Inertia.visit(args.url);
});

// Custom block renderer.
window.Chatbot.registerBlockRenderer('table', (data, host) => {
    const el = document.createElement('table');
    // … build DOM from data
    return el;
});

// Pluggable navigation adapter. The `navigate` primitive checks
// the registered navigator before falling back to window.location.assign.
// registerTool('navigate') always wins over registerNavigator.
window.Chatbot.registerNavigator((url, opts) => {
    Inertia.visit(url, opts);
});
```

## Robust SSE reading

- POST with `fetch` + `ReadableStream` (not `EventSource`, which does not support POST).
- Frame parser `event: <name>\ndata: <json>\n\n` with CRLF/CR normalization.
- Closed event catalogue (same as `Rnkr69\LaraChatbot\Sse\SseEvent`):
  `text` · `block` · `tool_call` · `tool_result` · `frontend_action` · `error` · `done`.
- Exponential reconnect (1s → 2s → 4s → 8s → 16s, cap 30s, 25% jitter) up to
  4 retries. `429 Too Many Requests` does not retry and is reported as an error.
- `X-CSRF-TOKEN` is read from the `<meta name="csrf-token">` tag automatically.
- `setUser(token)` adds `Authorization: Bearer <token>` to every request.
- Cancellation: the component cancels the active stream when disconnected from
  the DOM (`disconnectedCallback`).

## Markdown subset

The built-in renderer covers the minimum for conversational text:

- `**bold**`, `*italic*`
- inline code `` `x` ``
- links `[text](url)` — only `http(s)`, `mailto:`, `tel:`, and relative paths;
  `javascript:` and `data:` are printed as literal text
- paragraph breaks on blank lines; single line breaks translate to `<br>`

All input is XSS-escaped first (`<`, `>`, `&`, `"`, `'`). Hosts that need more
(lists, headings, code blocks, tables) should register a
`registerBlockRenderer('text', …)` of their own.

## Typed blocks

Package built-in catalogue:

- `text` — markdown subset (bold/italic/code/links).
- `actions` — buttons with `label` + (`prompt` or `tool` + `args`).
- `card` — title + subtitle + markdown description + field list + inline actions.
- `table` — `rows[]` with optional `columns[]` (auto-infers headers from first row).
- `list` — ordered or unordered `items[]`; each item can be text, prompt, or tool.
- `chart` — placeholder; the host registers its renderer (Chart.js / ApexCharts / own SVG).

The widget renders a block by applying the **renderer cascade** in this order:

1. **`window.Chatbot.registerBlockRenderer(type, fn)`** — the host JS renderer wins.
2. **`<template data-chatbot-block-template="<type>">`** — clones the template
   and fills each `[data-bind="path"]` with a lodash.get-style lookup on `data`.
3. **Built-in** from the package (the six types above).
4. **Placeholder** `[unsupported block: <type>]` if nothing matches.

If a host renderer throws, the widget logs `console.error` and falls through to
the next cascade step — a broken block does not break the thread.

The backend has two ways to emit a block:

- **`RenderBlockTool`** (canonical): the LLM invokes it as a frontend tool with
  `{type, data}`. `ChatService` translates it to a `frontend_action` with
  `tool=render_block`; the widget intercepts that signal and converts it into a
  block in the assistant message. No changes to the SSE contract.
- **`SseEvent::block($type, $data)`** (custom services): any consumer of the
  orchestrator can emit the `event: block` frame directly; the widget treats it
  the same way.

Full docs with examples: [`docs/block-renderers.md`](./block-renderers.md).

## Frontend actions

The widget processes `frontend_action` events by applying this cascade:

1. If `confirmation !== 'auto'`, queues the action in an in-memory list
   (`getPendingActions()` exposes it) and shows an informational toast.
2. If a handler is registered via `window.Chatbot.registerTool(name, fn)`, it
   delegates there. Allows the host to **override** core primitives (typical:
   `navigate` → SPA adapter).
3. Otherwise, executes the corresponding internal primitive:
   - `navigate` → `window.location.assign(url)` (same-origin only; cross-origin
     is silently refused; the host can register its own handler for remote
     navigation).
   - `toggle_visibility` → flips `display:none` (accepts `visible: bool` to force).
   - `show_toast` → toast in the shadow DOM.
   - `download_file` → `<a href download>` with `download_url` and `expires_at`
     merged by `DownloadFileTool`. Only `http(s)` URLs; others are refused.
4. If nothing matches, log warn `[chatbot] no handler registered for frontend tool "x"`.

The `fill_form`, `open_modal`, `render_block`, and `invoke_host_action` primitives
fall to step 1 when their `confirmation` is not `auto`. Hosts that want them to
run in `auto` mode must subclass server-side — they will already have a handler
registered, or will fall through to the log warn.

## SPA/MPA — detection, persistence, and navigation

The widget supports both Multi-Page Apps (classic Blade) and Single-Page Apps
(Inertia / Livewire SPA / pushState). The mode is detected once on the first
`connectedCallback` of the custom element and cached for the lifetime of the bundle.

**Detection heuristic** (in order):

1. `<meta name="chatbot:mode" content="spa">` or `="mpa"` — wins (ground truth
   over heuristics; useful when automatic detection fails).
2. `window.Inertia` defined → SPA.
3. `window.Livewire` defined → SPA.
4. Default → MPA.

**Persistence (`sessionStorage`)**

Canonical key `chatbot:state:v1`. Shape:

```json
{ "conversationId": 42, "isOpen": true, "draft": "half-typed message" }
```

Saved with a 250ms debounce after each change (textarea input, widget state
transition, `data-conversation-id` mutation). Rehydrated when the custom element
mounts — `data-default-open` is only applied if there is NO persisted state. In
MPA mode each page load reconstructs the widget; in SPA mode the widget is not
unmounted between routes, so persistence only matters on hard reloads of the shell.

`sessionStorage` errors (private mode, quota full, sandbox) are silenced —
persistence is best-effort and never breaks the UX.

**Navigation adapters**

The internal `navigate` primitive applies this cascade when the LLM emits a
`frontend_action { tool: "navigate" }`:

1. `registerTool('navigate', fn)` registered by the host → always wins.
2. `registerNavigator(fn)` registered by the host → replaces the default
   primitive without touching other tools.
3. Automatic detection at call time:
   - `window.Inertia.visit(url, opts)` if Inertia is present.
   - `window.Livewire.navigate(url, opts)` if Livewire is present.
   - `window.location.assign(url)` (MPA) otherwise.

Cross-origin URLs are silently refused (defense against a misprompted LLM).
To navigate to another domain, the host registers its own handler via
`registerTool('navigate', …)`.

**Stream cancellation on SPA navigation**

In SPA mode the widget listens for `inertia:navigate`, `livewire:navigated`, and
`popstate` and aborts the active `streamPost` if there is one. Rationale: a
half-rendered response against a route that is no longer active would produce
inconsistent UI. The conversation is NOT lost — `conversationId` persists and the
next turn resumes against the backend.

## Page context

Every POST to `/chatbot/stream` includes:

- `message`: user string
- `conversation_id`: if it exists (resume conversation)
- `page_context`: the effective context (initial meta tag + shallow merge of
  each `setPageContext({...})`)

The widget starts by reading `<meta name="chatbot:context">` (if present) and,
in SPA mode, re-reads the meta tag after each `inertia:navigate`/`livewire:navigated`/
`popstate`. Any change (programmatic or via nav) emits the `chatbot:context-changed`
event on `window` with the effective context in `event.detail`.

```js
window.addEventListener('chatbot:context-changed', (e) => {
  console.log('chatbot context now:', e.detail);
});
```

The backend sanitizes the JSON type by type (only strings/numbers/booleans/arrays
survive; closures, objects, resources, and nulls are discarded) and, if after
sanitization it still exceeds `chatbot.limits.page_context_kb`, it is silently
discarded entirely (silent degradation, not 422).

Full recipe, detailed sanitization, SPA hook, and Backpack opt-in guide:
see [`docs/page-context.md`](page-context.md).

## Offline smoke test

A fixture at `tests/js/fixtures/smoke.html` loads the bundle and stubs `fetch`
to emit a canned stream. Useful for validating behavior without a running backend:

```bash
npm run build
python3 -m http.server --directory tests/js/fixtures 4173
# open http://localhost:4173/smoke.html
```

## Tests

Vitest + jsdom. The suite covers the state machine, SSE parser (frames /
reconnect / abort / CSRF / bearer), markdown subset, global API registries,
FE primitives (auto + queued), and block renderers.

```bash
npm test           # vitest, single run
npm run test:watch # vitest watch mode
npm run typecheck  # tsc --noEmit strict
npm run test:e2e   # Playwright (2 MPA + SPA scenarios)
npm run build:check # bundle size guard (cap 80 KB gzip; current: ~28 KB)
```

E2E tests run against static fixtures served by
`scripts/e2e-server.mjs` with mocked `fetch` — no Laravel, no Vite, no DB.
Same pattern as the smoke fixture but executed in real Chromium.

## Known limitations

- `fill_form`, `open_modal`, `render_block`, `invoke_host_action` are queued
  in `confirm`/`manual` mode.
- No complete built-in renderers for `card`/`table`/`list`/`chart`.
- Markdown subset: no headings, lists, or code blocks. Override via
  `registerBlockRenderer('text', …)` if needed.
