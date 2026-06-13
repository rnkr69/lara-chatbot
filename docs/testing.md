# Testing

*English · [Español](testing.es.md)*

This guide complements the **CI Pipeline** section of `docs/distribution.md` (PHP × Laravel matrix + 4 pipeline steps). It documents:

- How to run the suite locally (including coverage with PCOV/Xdebug).
- Structure of the package tests and where each category lives.
- Coverage gate (≥75% on `src/Authorization`, `src/Services`, `src/Tools`) and how to verify it per path.
- Conventions for adding tests when the host registers its own backend tools.
- Coverage intentionally deferred to the v1.1 backlog and why.

---

## Canonical commands

`composer.json` exposes three scripts:

```bash
composer test              # vendor/bin/pest (full suite)
composer test:coverage     # pest --coverage (requires PCOV or Xdebug)
composer test:coverage-min # pest --coverage --min=75 (global gate; fails if < 75%)
```

For frontend stacks:

```bash
npm test           # Vitest
npm run typecheck  # tsc --noEmit in strict mode
npm run build      # esbuild
npm run build:check # fails if gzip bundle exceeds 80 KB
npm run test:e2e   # Playwright (chromium-only); rebuilds the bundle via pretest:e2e
```

> The 80 KB gzip bundle gate is enforced by `scripts/check-bundle-size.mjs`. The bundle is currently ~28 KB gzip.

---

## Coverage

### Requirements

`pest --coverage` requires **PCOV** or **Xdebug** enabled in the PHP binary running the tests:

```bash
# Verify:
php -m | grep -E "pcov|xdebug"

# If neither appears, install one:
pecl install pcov     # lightweight option, recommended for CI
# or enable Xdebug via the `zend_extension` directive in php.ini
```

Without a coverage extension, `pest --coverage` aborts with an explicit error. The normal suite (`composer test`) continues to work.

### Generated reports

`phpunit.xml` declares three reports under `<coverage>`:

| Format | Path | Use |
|---|---|---|
| Clover XML | `build/coverage/clover.xml` | input for CI tools (Codecov, Coveralls). |
| HTML | `build/coverage/html/index.html` | manual exploration with per-file/line drill-down. |
| Plain text (summary only) | `build/coverage/coverage.txt` | human-readable snapshot in CI logs. |

`build/` is already in `.gitignore` (test artifact).

### Per-path gate

PHPUnit 11 and Pest 3 do NOT natively support per-path thresholds — `--min=N` applies to the aggregated total. The package strategy:

1. **Global gate**: `composer test:coverage-min` runs `pest --coverage --min=75`. Fails if aggregate coverage drops below 75%.
2. **Per-path verification**: after running `pest --coverage`, inspect `build/coverage/html/index.html` or filter the text report. Each of the three critical paths must be ≥75%.

```bash
# Shortcut: extract % per path from clover.xml
php -r '
  $xml = simplexml_load_file("build/coverage/clover.xml");
  foreach ($xml->project->package as $pkg) {
    $name = (string) $pkg["name"];
    $cov  = $pkg->metrics["coveredstatements"] / max(1, (int) $pkg->metrics["statements"]);
    printf("%-30s %5.1f%%\n", $name, $cov * 100);
  }
'
```

Once CI is wired, this check can be automated with a step that fails if any critical path drops below the threshold. The most portable approach today is an assertion in a dedicated test (not included in v1 — it would be noise locally without a coverage extension).

### Paths excluded from computation

`phpunit.xml` explicitly excludes:

- **`src/ChatbotServiceProvider.php`** — boot wiring with branches that depend on runtime detection of optional packages (Spatie, Backpack, Relay). Its content is validated via `ServiceProviderBootTest` (Authorizer/ScopeResolver resolution, no-bind of TenantResolver, config shape) and via integration tests (`McpToolBridgeTest`, `BackpackIntegrationTest`). Including it in the computation inflates the denominator with branches that only live in the host.
- **`src/Console/Commands/stubs/`** — `.stub` templates that the `chatbot:install` command copies to the host. Not executable code.

---

## Suite structure

```
tests/
├── Pest.php              — bootstrap (pest()->extend(TestCase::class))
├── TestCase.php          — Orchestra Testbench + ChatbotServiceProvider + PrismServiceProvider + SQLite in-memory
├── Stubs/
│   ├── Tools/            — test backend tools (Public, Permissioned, StrictArgs, TeamScoped, TenantScoped, ...)
│   ├── Backpack/         — fakes for BackpackPageContextProvider
│   └── Mcp/              — fakes for the MCP bridge
├── Unit/                 — no Laravel/HTTP, tests pure classes
│   ├── Authorization/    — GateAuthorizer, NullScopeResolver, AuthorizesToolAccess
│   ├── Events/           — ToolInvoked
│   ├── Llm/              — SystemPromptBuilder, LlmException
│   ├── Mcp/              — McpBackendTool
│   ├── Services/         — PageContextSanitizer
│   ├── Sse/              — SseEvent
│   └── Tools/            — ToolResult, ToolContext, ConfirmationLevel, JsonSchemaToRules, PrismToolFactory
└── Feature/              — full Testbench, migrations, HTTP, events
    ├── Console/          — artisan commands (Install, MakeTool, ...)
    ├── Http/             — controllers + middlewares (ChatController, ConversationController, ...)
    ├── Integrations/     — Backpack
    ├── Llm/              — LlmGateway with Prism::fake()
    ├── Mcp/              — McpToolBridge
    ├── Services/         — ChatService, PendingActionStore, FrontendActionMerge, ConfirmationDoD
    └── Tools/            — BaseBackendTool, ToolRegistry, frontend primitives (including DownloadFileTool)
```

Pest 3 conventions:

- `it('does X', ...)` for behaviour cases (preferred).
- `test('case', ...)` when the subject is data rather than behaviour (e.g. constant shape).
- `beforeEach` per file when shared setup > 3 LOC.
- PHP stubs in `tests/Stubs/` with namespace `Rnkr69\LaraChatbot\Tests\Stubs\…` (registered in `composer.json` autoload-dev).

---

## Cross-host coverage

| Gap | Main test | Coverage |
|---|---|---|
| `TenantResolver` | `tests/Feature/Tools/BaseBackendToolTest.php` + `tests/Feature/Tools/ToolRegistryTest.php` + `tests/Unit/Authorization/AuthorizesToolAccessTest.php` | binding present/absent, `tenantScope=true` requires binding, `whereIn(tenant_column)` applied correctly, `out_of_scope` output when list is empty. |
| `DownloadFileTool` | `tests/Feature/Tools/Frontend/DownloadFileToolTest.php` | URL signing, fail-secure by default, allowed_disks, ownership override (`assertCanDownload`), clamp `expires_in`, rejection of http/https. |
| `ToolInvoked` event | `tests/Unit/Events/ToolInvokedTest.php` + 3 dispatches in `tests/Feature/Services/ChatServiceTest.php` | readonly properties, dispatch on backend / frontend / denied cascade. |
| `system_prompt_addendum` + i18n | `tests/Unit/Llm/SystemPromptBuilderTest.php` (30 tests) | programmatic section, host override, fallback to `app()->getLocale()`, sanitisation of `## Current page`. |
| Bulk pattern | docs (`docs/backend-tools.md`) | described as a pattern, requires no contract change. Host-side tests. |
| Backpack | `tests/Feature/Integrations/BackpackIntegrationTest.php` + `BackpackProviderShapeTest.php` | opt-in via `class_exists`, Blade directive `@chatbotBackpackContext`, provider shape (entity/selected_ids). |

---

## Coverage deferred to backlog

Three tactical decisions:

### 1. `SpatieAuthorizer` + `ChatbotServiceProvider::verifyAuthorizationConfig`

**Deferred to v1.1.** The package does not declare `spatie/laravel-permission` in `require-dev` (does not add weight to the CI matrix). Both paths only execute when Spatie is loaded in the host:

- `SpatieAuthorizer::__construct` throws `RuntimeException` if Spatie is absent.
- `verifyAuthorizationConfig` moves that check forward to SP boot.

**Strategy for v1.1**: add Spatie to `require-dev` when decided for CI, write 3-4 tests validating the "Spatie present → check with `$user->can()` iterates AND" path + the "Spatie absent + resolver=spatie → clear RuntimeException" path. The iteration algorithm (identical to `GateAuthorizer`) is already covered via `tests/Unit/Authorization/GateAuthorizerTest.php`, so the real cost is validating the wiring, not the algorithm.

### 2. `LlmGateway` → `LlmException` translation

**Deferred to v1.1.** The 7 tests in `LlmGatewayTest` cover chat / tool call / stream / overrides / fallback / system prompt builder / tools forwarding against `Prism::fake()` in the happy path. Provider-error-to-`LlmException` translation is not directly asserted; the error branch exists in code but E2E validation requires either a `Prism::fake` with a programmed error response or a deeper mock of `PendingRequest`. The `chatbot:test-connection` command is the first practical line of defence and is covered by `TestConnectionCommandTest`.

### 3. Backpack: advanced examples

**Already in v1.1 backlog.** The base integration is covered; bulk examples in grids and KPI drill-downs live in `docs/integrations/backpack.md`.

---

## Adding tests when integrating host tools

When a host registers its own backend tools in `app/Chatbot/Tools/`, replicate the same structure:

```
tests/Unit/Chatbot/Tools/                  # isolated tests per tool
└── ListMyInvoicesToolTest.php

tests/Feature/Chatbot/                     # integration tests (HTTP, events)
└── InvoiceFlowTest.php
```

Recommended patterns:

- **Invalid args** → `ToolResult::error('validation', ...)` without invoking `handle`. Covered by `BaseBackendTool::execute`.
- **Permission denied** → `Gate::define('foo', fn () => false)` and assertion on `errorCategory === 'unauthorized'`.
- **Scope** → register a `FixedScopeResolver` (stub in this package, copyable to the host) and verify that `accessibleQuery()` applies `whereIn(user_id)` correctly with `->toSql()`.
- **TenantScope** → same pattern with `FixedTenantResolver`. Remember that the package only registers the binding if `chatbot.authorization.tenant_resolver` is populated (do not manually instantiate a `NullTenantResolver`).
- **`Event::fake([ToolInvoked::class])`** + `Event::assertDispatched` to audit telemetry.
- **`Prism::fake([...])`** to avoid contacting the real LLM in tests.

> The `php artisan chatbot:make:tool MyTool --type=read` command generates the tool skeleton. Consider also generating a minimal test at `tests/Unit/Chatbot/Tools/MyToolTest.php`.

---

## When a test fails in CI

1. **`Pest::fake()` complains** — add the event/queue/storage to the `pest.php` `uses(TestCase::class, ...)` section or use the concrete helper (`Event::fake()` per test).
2. **`SQLSTATE[HY000]: General error: 1 no such table`** — add `$this->artisan('migrate')->run()` in `beforeEach` or use the `RefreshDatabase` trait (not applicable here because Testbench uses SQLite in-memory; package migrations are loaded by the SP on boot, but for dynamic tables `migrate` must be run explicitly).
3. **Bundle gzip > 80 KB** — `npm run build:check` reports the delta. Review recent imports and consider moving large utilities to an opt-in module (loaded via `registerBlockRenderer`/`registerTool`).
4. **Playwright timeout** — `pretest:e2e` rebuilds the bundle; if it fails it is typically because the fixture server (`scripts/e2e-server.mjs`) did not start. Verify the port is not already in use.
