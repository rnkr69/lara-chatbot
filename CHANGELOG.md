# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.3] - 2026-06-25

### Fixed
- **Widget header: minimize and close felt swapped.** The header had two
  buttons — minimize (—) and close (✕). Minimizing targeted the `minimized`
  state, whose only visible affordance was a `.launcher-mini` element that was
  never rendered in the DOM, so the whole widget vanished and looked closed;
  closing targeted the `closed` state, which shows the 💬 launcher bubble and so
  looked merely minimized. The minimize button is **removed**: the single close
  button (✕) collapses the panel back to the reopenable launcher bubble. The
  `minimized` state now also falls back to the launcher bubble (it remains
  reachable via the `window.Chatbot` API), so it can never leave a blank widget.
  Requires the published widget asset to be refreshed (`php artisan
  vendor:publish --tag=chatbot-assets --force` or re-publish however the host
  ships `public-build/chatbot-widget.js`).

## [0.4.2] - 2026-06-25

### Fixed
- **Duplicated block content.** When a backend tool returned typed `blocks`
  (table/card/kpi/chart/list), the widget painted the block via SSE **and** the
  LLM reproduced the same content as a markdown table in its text (which the
  widget also rendered) — the user saw two tables / two cards. Root cause: the
  tool result returned to the model (`PrismToolFactory::wrap()`) was
  `ToolResult::toArray()`, which includes the `blocks` key with every row, so
  the model "saw" the blocks and replayed them. The model payload now omits
  `blocks` by default. The contract is now explicit: **`data` is what the LLM
  sees and reasons over; `blocks` are presentation-only for the widget.**
- **Blocks lost on reload.** Blocks only travelled over the live SSE stream and
  were never persisted, so reloading the page or opening
  `/chatbot?conversation_id=XX` dropped every card/table — the conversation
  rehydrated with text only and looked half-finished. Emitted blocks are now
  appended to the assistant message `content[]` (the same canonical payload the
  widget already hydrates via `adaptStoredMessage`), so they re-render on
  reload. No client-side change and no DB migration required (`content` is
  already a JSON column). Note: conversations created before 0.4.2 have no
  persisted blocks and stay text-only on reload — only new turns replay.

### Added
- `chatbot.llm.send_blocks_to_model` (env `CHATBOT_LLM_SEND_BLOCKS_TO_MODEL`,
  default `false`) — when `true`, the tool result sent to the model includes
  `blocks` again (restores pre-0.4.2 behavior, e.g. if you want the LLM to
  reason over the block rows; prefer putting those rows in `data` instead).
- `ToolResult::toModelArray(bool $includeBlocks = false)` — model-bound
  serialization that omits `blocks` unless requested. `toArray()` is unchanged
  (still used by the `tool_result` SSE event and the `ToolInvoked` listeners).

### Changed
- **Behavior change (default):** tool `blocks` are no longer sent to the model.
  This is the fix for the duplicated content above. Hosts that relied on the LLM
  seeing block rows can re-enable it with `CHATBOT_LLM_SEND_BLOCKS_TO_MODEL=true`,
  but the recommended path is to expose those rows in the tool's `data`.

## [0.4.1] - 2026-06-13

### Added
- **Laravel 13 support.** Dependency constraints widened to
  `illuminate/* ^11.0|^12.0|^13.0`; the suite passes unchanged on Laravel 13
  (588 tests). CI now runs Laravel 12 (PHP 8.2/8.3/8.4) and Laravel 13
  (PHP 8.3/8.4 — Laravel 13 drops PHP 8.2).

### Changed
- **Codebase fully translated to English.** All code comments plus every
  user- and LLM-facing string — artisan CLI output, error/exception messages
  and the system-prompt few-shot examples — were translated from Spanish to
  English. The `resources/lang/es` translation resource and the `*.es.md`
  docs remain (the package stays bilingual at the docs/translation layer).
- Dev tooling allows Pest `^3.0|^4.0` and Testbench `^9.0|^10.0|^11.0` so each
  Laravel line resolves its compatible test stack (Pest 3 on L12, Pest 4 on L13).
- `composer test` now runs with `--no-coverage` (Pest 4 / PHPUnit 12 hard-fail
  when coverage reports are configured but no driver is present; use
  `composer test:coverage` for coverage runs).
- **Leaner Composer dist.** `export-ignore` rules in `.gitattributes` exclude
  development-only paths (tests, docs and their images, build scripts, source
  maps and JS/TS tooling configs) from the `composer require` tarball.

### Notes
- **Laravel 11** is still allowed (`^11.0`) but is no longer tested in CI: it
  reached security-EOL (~Mar 2026) and Composer's advisory blocking prevents a
  clean install. See `docs/getting-started.md` for the install caveat.

## [0.4.0] - 2026-05-16

First externally referenced release of `rnkr69/lara-chatbot`.

The 22 release tags cut between 2026-05-09 and 2026-05-16 (originally
labelled v1.0.0 through v2.2.3) were iterations against a single
internal test host (`example-app`) — no external host had ever
consumed the package from a tag during that window. Rather than expose
that journal as if it were stable history, the entire range is
consolidated here as `0.4.0`. The 0.x line signals that the package is
in pre-stable validation: contracts may still change between minor
versions while a second real-host integration confirms what is and is
not load-bearing. The path to 1.0.0 is "second host integrated +
contracts unchanged for one full release cycle".

Source comments and docs occasionally reference internal markers from that
period (e.g. `v2.1.2 (#27)` or an epic id like `E08`); they are historical
breadcrumbs from the pre-0.4 build and have no effect on the current package.

### Capabilities included

- **Streaming chat (SSE)**: `POST /chatbot/stream` with incremental
  text deltas; frames for `tool_call`, `tool_result`,
  `frontend_action`, `block`, `done`. Conversation + message
  persistence; configurable history window.
- **Backend tools**: PHP classes the host registers via auto-discovery
  or manual binding. Every invocation passes the
  `permission → scope → tenant → ownership` cascade before reaching
  the tool's `handle()` — this is the package's primary differentiator
  versus any SaaS chatbot or one-off integration. JSON-Schema arg
  validation. `pinnable()` opt-in for dashboard widgets. `ToolInvoked`
  event for audit/telemetry.
- **Frontend tools**: 9 built-in primitives (`navigate`, `highlight`,
  `fill_form`, `show_toast`, `download_file`, `open_modal`,
  `render_block`, `toggle_visibility`, `invoke_host_action`) with PHP
  shim for validation/authorization + JS handler in the bundle.
  Confirmation levels `auto` / `confirm` / `manual`; unified banner UI;
  audit row with TTLs. `PrimitiveResult` returned by every primitive
  so failures surface back to the LLM instead of silently no-op'ing.
- **MCP bridge** *(experimental)*: external MCP servers exposed as
  tools under `mcp.<server>.<tool>` via `prism-php/relay`. Same
  authorization cascade applies.
- **Typed blocks**: built-in renderers for `text`, `actions`, `card`,
  `table`, `list`, `chart`, `kpi`. `registerBlockRenderer` for host
  overrides. `<template data-chatbot-block-template>` slot pattern for
  pure-HTML host customisation. Bootstrap-host-native rendering when
  the host opts in (`chatbot.backpack.use_bootstrap`).
- **Personal Dashboard** *(experimental — single-host validated)*:
  pinning blocks from chat (📌); gridstack.js drag-and-drop (12-col);
  multiple named dashboards per user; `ReplayService` re-executes each
  widget's source tool on open under the same authorization cascade.
  Refresh policies `on_open` / `manual` / `never`. Cross-tab + cross-
  client refresh signal (`chatbot:dashboard-mutation`) keeps dashboard
  state in sync when the chat mutates it. Five conversational backend
  tools (`add_to_dashboard`, `edit_widget`, `delete_widget`,
  `edit_dashboard`, `delete_dashboard`) for in-chat dashboard editing.
  `chatbot:dashboards:prune` Artisan command for housekeeping.
- **Page Context API**: declarative meta tag (`chatbot:context`) +
  programmatic `window.Chatbot.setPageContext()` (deep merge at first
  level). Sanitizer drops closures, resources, NaN/INF; truncated at
  `chatbot.limits.page_context_kb`. `page_context_snapshot` captured
  at pin time so dashboard replay sees the same context as the
  original chat turn.
- **Widget**: Web Component (`<chatbot-widget>`) in shadow DOM, vanilla
  TypeScript, ~28 KB gzip. Floating + page modes; cross-tab state via
  localStorage; theme cascade (`data-theme` attribute follows explicit
  → `<html data-bs-theme>` → `prefers-color-scheme`) with runtime
  reactivity. Page widget at `GET /chatbot` with sidebar of
  conversations and optional `?conversation_id=N` deep link.
- **Dashboard bundle** *(experimental)*: separate `chatbot-dashboard.js`
  (~110 KB gzip) for `/chatbot/dashboard`. Chart.js bundled by default
  (overridable). PHP→JS i18n bridge (`data-i18n` attribute drained at
  boot). Standalone vs `@extends($layout)` modes.
- **Authorization**: cascade of three concerns — `Authorizer` (Spatie /
  Gate / custom) for permission, `ScopeResolver` (host-implemented) for
  `self` / `team` / `all` row scoping, `TenantResolver` for multi-
  tenant boundary, `Policy::can()` for per-record ownership. Tools
  declare `permissions()`, `defaultScope()`, `tenantScope()`,
  `policyOwnership()`; `ToolRegistry::forUser()` filters the catalogue
  before it reaches the LLM. Boot-time guard raises if a tool with
  `tenantScope=true` is registered without a `TenantResolver` bound.
- **LLM gateway**: Prism (`^0.100`) underneath; multi-provider
  (Anthropic, OpenAI, Groq, Gemini, Mistral, Ollama). `LlmGateway`
  abstracts the call — see [`docs/prism-contingency.md`](docs/prism-contingency.md)
  for the swap plan if Prism becomes unmaintained.
- **CLI**: `chatbot:install` (interactive wizard, 9 idempotent steps),
  `chatbot:doctor` (config + auth + DB + assets + LLM + tools health
  check), `chatbot:make:tool`, `chatbot:make:scope-resolver`,
  `chatbot:make:tenant-resolver`, `chatbot:tools:list` (warns on
  `pinnable=true` + `confirmation!=Auto`), `chatbot:tools:test`,
  `chatbot:test-connection`, `chatbot:cleanup-actions`,
  `chatbot:scan-forms` + `chatbot:integrate-form`,
  `chatbot:decision-rules:show`, `chatbot:dashboards:prune`.
- **Bundles with size cap enforced in build**: `scripts/build.mjs`
  invokes `scripts/check-bundle-size.mjs` (caps 80 KB widget /
  150 KB dashboard, gzipped) and `scripts/check-bundle-tokens.mjs`
  (required string-literal tokens per bundle survive minification).
- **Integration with Backpack admin** *(experimental)*: opt-in
  `BackpackPageContextProvider` emits `crud.entity`/`crud.form`/
  `crud.filters` with FK options pre-resolved (cap 200); Backpack-
  themed dashboard `layout` mode; bulk-selection live sync to
  `page_context.crud.selected_ids`.

### Known limitations

- **Validated against one internal test host** only. The `*experimental*`
  markers above flag capabilities that have not yet been exercised in a
  second real host.
- **Backend tools support only `confirmation = Auto`** in this release.
  The orchestrator filters non-Auto backend tools from the LLM
  catalogue. The Confirm/Manual flow for backend tools (banner UI
  parity with frontend tools) is backlogged.
- **Cost / token telemetry**: per-message `tokens_in`/`tokens_out`
  columns are persisted but the aggregation command + event are not
  shipped yet (see `chatbot:cost-report` in backlog).
- **Accessibility (WCAG)**: not audited. ARIA labels exist on the
  widget's primary buttons; full audit (WCAG 2.1 AA) is in backlog.
- **Evals**: no automated quality harness for the LLM's tool-selection
  / args correctness yet. Manual smoke testing only.
- **CI**: not yet wired (`docs/distribution.md` documents the
  recommended matrix; `.github/workflows/ci.yml` is in flight).

### Versioning policy

This package follows Semantic Versioning. While we are on `0.x`, MINOR
bumps may include breaking changes — the API is still being shaped
against a second real-host integration. Once we cut `1.0.0`, breaking
changes to any of the following will require a MAJOR bump:

- HTTP routes, request/response payloads under `/chatbot/*`.
- `BackendTool` / `FrontendTool` / `BaseBackendTool` / `BaseFrontendTool` public surface.
- Config keys under `chatbot.*` (removal or rename of an existing key).
- Migration shape (new columns may be MINOR if nullable).
- `<chatbot-widget>` attributes/events and `window.Chatbot` API.
- Storage keys `chatbot:state:v1`, `chatbot:active-conversation:v1`.
- SSE event names and frame schema.

Adding new tools, new block renderer types, new artisan commands, or
new opt-in integrations is MINOR. Internal refactors with no public-
surface change are PATCH.

[Unreleased]: https://github.com/rnkr69/lara-chatbot/compare/v0.4.1...HEAD
[0.4.1]: https://github.com/rnkr69/lara-chatbot/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/rnkr69/lara-chatbot/releases/tag/v0.4.0
