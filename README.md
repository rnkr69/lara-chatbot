# rnkr69/lara-chatbot

![status](https://img.shields.io/badge/status-v0.4.0--prerelease-orange)
![php](https://img.shields.io/badge/php-%5E8.2-blue)
![laravel](https://img.shields.io/badge/laravel-11%20%7C%2012-red)
![license](https://img.shields.io/badge/license-MIT-green)

A Laravel package (pre-stable `0.x` line) that adds an LLM-assisted chat to your
app. It invokes the host's **backend tools** under an authorization cascade
enforced by the package, runs **frontend tools** (navigation, forms, downloads,
modals) from a stack-agnostic Web Component (Blade, Livewire, Inertia + Vue/React),
and lets users **pin results to a personal dashboard** that re-executes under the
same permissions.

Built as a personal side project. It is functional and test-covered, but still
pre-`1.0`: the public API is being validated against a second host, so a MINOR
bump may include breaking changes while on the `0.x` line.

---

## Value proposition

| Without the package | With the package |
|---|---|
| **Risk of leaking data** to unauthorized users when a chatbot queries the backend. | **Main differentiator:** a `permission → scope → tenant → ownership` cascade enforced by the package before invoking any tool. Host tools never see data outside the user's scope. |
| Every project reinvents the bot, the tools, the widget and the auth wiring. | A common contract (`BackendTool`, `FrontendTool`, `Authorizer`, `ScopeResolver`, `TenantResolver`). The project only contributes its domain tools. |
| Coupling to a single LLM provider (Anthropic SDK, OpenAI, etc.). | Prism `^0.100` underneath: Anthropic, OpenAI, Groq, Gemini, Mistral, Ollama. Switching providers is a config change. Plan B documented in [`docs/prism-contingency.md`](docs/prism-contingency.md). |
| Widget coupled to a JS framework. | Vanilla Web Component (~28 KB gzip widget) in shadow DOM. No React/Vue at runtime. |
| Ad-hoc confirmations per feature. | Declarative `confirmation=auto/confirm/manual`, unified banner, audit row with TTL. *(Today applies to frontend tools; backend tools are `auto` only — Confirm/Manual for backend is in the backlog.)* |

---

## Project status

| | |
|---|---|
| **Version** | `0.4.0` (pre-stable; a MINOR bump may break on the `0.x` line) |
| **Hosts in production** | 0 |
| **Hosts integrated for internal validation** | 1 (a private test app, no real users) |
| **Test coverage** | Pest (PHP) + Vitest (JS); 487 vitest + ≥75% target on core PHP |
| **CI** | `.github/workflows/ci.yml` (lint + test matrix + JS build) |
| **Eval harness (LLM tool-calling quality)** | 8 YAML fixtures. Fake mode (verifies the orchestrator) runs in CI per PR. Live mode calls the real LLM and dumps a per-fixture trace to `tests/Evals/last-live-run.json`. Backlog: ≥20 fixtures + multi-model matrix + baseline tracking. See [`tests/Evals/README.md`](tests/Evals/README.md). |
| **Per-user cost telemetry** | Persists `tokens_in`/`tokens_out` per message, `MessagePersisted` event for external sinks, `chatbot:cost-report --since=YYYY-MM-DD [--format=table\|json\|csv]` command. See [`docs/telemetry.md`](docs/telemetry.md). |
| **Accessibility (WCAG)** | Not audited. Basic ARIA labels on widget buttons. WCAG 2.1 AA audit is in the `v0.5+` backlog. |
| **Road to `1.0`** | A second real host integrated + one release cycle with no breaking changes to the 7 items in the "Versioning policy" (see `CHANGELOG.md`). |

---

## Installation

This package is not published on Packagist (yet). Consume it directly from the
Git repository via a Composer VCS repository.

> Step-by-step detail in [`docs/getting-started.md`](docs/getting-started.md).
> In practice the first real integration (install + choose widget vs page +
> write a `ScopeResolver` + optional `TenantResolver` + wire page context +
> write your first tool with its permissions) is measured in hours, not minutes.
> The package wizard itself takes 5 minutes; the rest is host work.

### 1. Declare the repository in `composer.json` and require it

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/rnkr69/lara-chatbot.git"
        }
    ],
    "require": {
        "rnkr69/lara-chatbot": "^0.4"
    }
}
```

```bash
composer update rnkr69/lara-chatbot
php artisan chatbot:install      # interactive wizard (9 idempotent sub-steps)
php artisan migrate
php artisan chatbot:doctor       # health check (config + auth + DB + assets + LLM + tools)
```

### 2. Inject the widget into your layout

```blade
{{-- resources/views/layouts/app.blade.php, before </body> --}}
<chatbot-widget data-endpoint="{{ route('chatbot.stream') }}"></chatbot-widget>
<script src="{{ asset('vendor/chatbot/chatbot-widget.js') }}" defer></script>
```

### 3. Your first tool

```bash
php artisan chatbot:make:tool ListMyInvoices
```

```php
// app/Chatbot/Tools/ListMyInvoicesTool.php
public function name(): string { return 'list_my_invoices'; }
public function description(): string { return 'List the user invoices.'; }
public function permissions(): array { return ['invoices.view']; }
public function defaultScope(): AccessScope { return AccessScope::Self; }

public function handle(array $args, ToolContext $ctx): ToolResult
{
    $rows = $this->accessibleQuery(Invoice::query(), $ctx)->limit(20)->get();
    return ToolResult::success(['items' => $rows->toArray()]);
}
```

Reload, open the widget, ask "which invoices do I have?" — and the bot calls the
tool, applies permissions and scope, and answers with your data.

---

## How it works

```mermaid
flowchart LR
    U[User] --> W[Web Component<br/>chatbot-widget]
    W -- POST SSE --> C[/chatbot/stream/]
    C --> S[ChatService<br/>orchestrator]
    S --> A[Authorizer<br/>+ ScopeResolver<br/>+ TenantResolver]
    S --> P[Prism / LLM]
    P -- tool_call --> S
    S --> T[Host tool<br/>BackendTool / FrontendTool / MCP]
    T --> S
    S -- delta tokens --> W
    S -- frontend_action --> W
    W --> N[navigate / fill_form /<br/>highlight / download_file / ...]
```

Detail in [`docs/getting-started.md §4`](docs/getting-started.md#4-cómo-funciona).

---

## Documentation

| If you need to… | Read |
|---|---|
| **Start from scratch** | [`docs/getting-started.md`](docs/getting-started.md) |
| Understand the authorization cascade | [`docs/authorization.md`](docs/authorization.md) |
| Build backend tools (including bulk + MCP) | [`docs/backend-tools.md`](docs/backend-tools.md) |
| Build frontend tools | [`docs/FRONTEND_TOOLS.md`](docs/FRONTEND_TOOLS.md) |
| Render typed blocks (including `kpi` + `chart`) | [`docs/block-renderers.md`](docs/block-renderers.md) |
| Personal Dashboard (pin + replay) | [`docs/dashboard.md`](docs/dashboard.md) |
| Inject page context into the LLM | [`docs/page-context.md`](docs/page-context.md) |
| Ask the user for confirmation | [`docs/confirmation-flow.md`](docs/confirmation-flow.md) |
| Connect external MCP servers | [`docs/mcp.md`](docs/mcp.md) |
| Customize the widget | [`docs/WIDGET.md`](docs/WIDGET.md) |
| Backpack admin integration | [`docs/integrations/backpack.md`](docs/integrations/backpack.md) |
| Cost telemetry + `MessagePersisted` event | [`docs/telemetry.md`](docs/telemetry.md) |
| Deploy to production | [`docs/deployment.md`](docs/deployment.md) |
| Something is wrong | [`docs/troubleshooting.md`](docs/troubleshooting.md) |
| Distribute package versions | [`docs/distribution.md`](docs/distribution.md) |
| Run the test suite | [`docs/testing.md`](docs/testing.md) |
| Vision and principles of the package | [`docs/LARA_CHATBOT_PROJECT_DEFINITION.md`](docs/LARA_CHATBOT_PROJECT_DEFINITION.md) |
| Historical build plan (epics E01–E21 pre-0.4) | [`docs/LARA_CHATBOT_ROADMAP.md`](docs/LARA_CHATBOT_ROADMAP.md) |

---

## Requirements

- PHP **^8.2** (tested on 8.2 / 8.3 / 8.4).
- Laravel **^11.0** or **^12.0**.
- An LLM provider supported by [Prism](https://github.com/prism-php/prism):
  Anthropic, OpenAI, Groq, Gemini, Mistral, Ollama.
- MySQL ≥ 8.0, PostgreSQL ≥ 13 or SQLite.

---

## Capabilities

> Criterion: a capability is **stable** if it has been implemented and exercised
> end-to-end against the internal test host (Laravel 11 + Backpack 6.7 + Spatie
> Permission 6 + a LiteLLM proxy to Anthropic). The bar to promote to
> **production-ready** is different and requires a second real host integrated —
> see [Project status](#project-status). Any known scope or version limitation
> goes in its own entry as a caveat.

### Stable (implemented + exercised against the internal test host)

- **SSE streaming** — `POST /chatbot/stream` with incremental tokens; frames
  `tool_call`/`tool_result`/`frontend_action`/`block`/`done`. Conversation and
  history persistence.
- **Backend Tools** — host classes with the `permission → scope → tenant →
  ownership` cascade applied before every invocation. JSON Schema → Validator.
  Documented bulk pattern. *Current limitation: only `confirmation = Auto` is
  offered to the LLM; the Confirm/Manual flow for backend is in the backlog.*
- **Frontend Tools** — 9 built-in primitives (`navigate`, `highlight`,
  `fill_form`, `show_toast`, `download_file`, `open_modal`, `render_block`,
  `toggle_visibility`, `invoke_host_action`). Each primitive returns a
  structured `PrimitiveResult` — failures go back to the LLM instead of being
  silent no-ops. `DownloadFileTool` is fail-secure.
- **Typed blocks** — built-in renderers for `text`, `actions`, `card`,
  `table`, `list`, `chart`, `kpi`. `registerBlockRenderer` for custom +
  HTML slot (`<template data-chatbot-block-template>`).
- **Page Context API** — declarative meta tag (`chatbot:context`) +
  programmatic `window.Chatbot.setPageContext()` (deep merge at the first
  level). Sanitizer drops closures/resources/NaN/INF; truncated to
  `chatbot.limits.page_context_kb`.
- **Confirmations** — `auto`/`confirm`/`manual` for frontend tools, unified
  banner, audit row with TTL (10 min pending / 24 h executed), idempotent
  endpoint, `## Pending actions` section in the system prompt.
- **Widget** — Web Component (`<chatbot-widget>`) in shadow DOM, ~28 KB
  gzip. Theme resolution explicit → `<html data-bs-theme>` →
  `prefers-color-scheme` with runtime reactivity. Floating mode + page mode
  (`GET /chatbot`) with a conversation sidebar and deep-link via
  `?conversation_id=N`. State synced across tabs via localStorage.
- **Authorization** — three-dimensional cascade (permission via Spatie / Gate
  / custom, scope `self`/`team`/`all` via the host's `ScopeResolver`, ownership
  via `Policy::can()`). Boot-time guard if a tool with `tenantScope=true`
  has no `TenantResolver` bound.
- **LLM gateway** — Prism (`^0.100`) abstracts Anthropic / OpenAI / Groq /
  Gemini / Mistral / Ollama. Plan B documented in
  [`docs/prism-contingency.md`](docs/prism-contingency.md).
- **Personal Dashboard** — pin blocks from chat (📌), drag-and-drop grid
  with gridstack.js (12 col), `ReplayService` re-executes each widget's tool
  when the dashboard opens under the same authorization cascade. Multiple
  dashboards per user, `on_open`/`manual`/`never` refresh, `/chatbot/dashboard`
  route with a separate bundle (~110 KB gzip). Five conversational backend
  tools (`add_to_dashboard`, `edit_widget`, `delete_widget`, `edit_dashboard`,
  `delete_dashboard`) to create/edit the dashboard from natural language.
  Client-side refresh without F5 via the `chatbot:dashboard-mutation` event.
  See [`docs/dashboard.md`](docs/dashboard.md). *Surface caveat: separate
  ~110 KB gz dashboard bundle + 5 CRUD tools; the 5 conversational tools are
  the most recent features of the pre-0.4 cycle and deserve the most review
  in a second host.*
- **PHP → JS i18n bridge** — the blade emits `data-i18n` JSON-encoded from
  `__('chatbot::chatbot')` and the bundle drains each subtree
  (`dashboard.sidebar`, `dashboard.card`, etc.) to the corresponding mounter.
  Inline TS defaults as fallback. *Scope caveat: exercised within the
  dashboard bundle; not extended to other surfaces (chat widget, dedicated
  page) yet.*
- **Backpack integration** — opt-in `BackpackPageContextProvider` emits
  `crud.entity`/`crud.form`/`crud.filters` with pre-resolved FKs (cap 200);
  Backpack-themed dashboard `layout` mode; live sync of `crud.selected_ids`.
  *Version caveat: validated against Backpack 6.x; not tested against 5.x or 7.x.*
- **CLI** — `chatbot:install` (idempotent wizard), `chatbot:doctor` (health
  check), `chatbot:make:tool`, `chatbot:make:scope-resolver`,
  `chatbot:make:tenant-resolver`, `chatbot:tools:list`, `chatbot:tools:test`,
  `chatbot:test-connection`, `chatbot:cleanup-actions`,
  `chatbot:scan-forms` + `chatbot:integrate-form`,
  `chatbot:decision-rules:show`, `chatbot:cost-report`.
- **Build-time bundle cap** — widget 80 KB gzip / dashboard 150 KB gzip.
  `scripts/build.mjs` fails if exceeded + `scripts/check-bundle-tokens.mjs`
  verifies that critical tokens survive minification (REQUIRED per-bundle +
  SHARED cross-bundle).

### Not exercised yet (designed, no real usage)

- **MCP bridge** — external MCP servers integrated as catalogue tools under
  the prefix `mcp.<server>.<tool>` via `prism-php/relay`. The same
  authorization cascade would apply to remote tools. *No host uses it yet.
  The contract is implemented and has unit tests; the end-to-end exercise
  against a real MCP server will come with the first host that asks for the
  capability.*

---

## Versioning

`rnkr69/lara-chatbot` follows [Semantic Versioning](https://semver.org), with an
important nuance for the `0.x` line: **while pre-`1.0`, a MINOR bump may contain
breaking changes**. The API is being validated against a second real host before
committing to stability. For production on `0.x`, pin to a specific `0.4.N`
version and review `CHANGELOG.md` before upgrading.

After `1.0.0`, the 7 items listed in the "Versioning policy" section of
[`CHANGELOG.md`](CHANGELOG.md) will require a MAJOR bump to break (HTTP routes,
tool contracts, config keys, web component attributes, storage keys, SSE events,
migration shape).

---

## Support

- Issues: please open a [GitHub issue](https://github.com/rnkr69/lara-chatbot/issues).
- Releases: tagged in the repository. See [`docs/distribution.md`](docs/distribution.md).

---

## License

MIT — see [`LICENSE`](LICENSE).
