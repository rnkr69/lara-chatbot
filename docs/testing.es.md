# Testing

*[English](testing.md) · Español*

Esta guía complementa la sección **Pipeline CI** de `docs/distribution.es.md` (matriz PHP × Laravel + 4 pasos del pipeline). Aquí se documenta:

- Cómo correr la suite localmente (incluyendo cobertura con PCOV/Xdebug).
- Estructura de los tests del paquete y dónde vive cada categoría.
- Coverage gate (≥75% en `src/Authorization`, `src/Services`, `src/Tools`) y cómo verificarlo por path.
- Convenciones para añadir tests cuando el host registra sus propias backend tools.
- Cobertura conscientemente diferida al backlog v1.1 y por qué.

---

## Comandos canónicos

`composer.json` expone tres scripts:

```bash
composer test              # vendor/bin/pest (toda la suite)
composer test:coverage     # pest --coverage (requiere PCOV o Xdebug)
composer test:coverage-min # pest --coverage --min=75 (gate global; falla si < 75%)
```

Para los stacks frontend:

```bash
npm test           # Vitest
npm run typecheck  # tsc --noEmit en modo strict
npm run build      # esbuild
npm run build:check # falla si bundle gzip excede 80 KB
npm run test:e2e   # Playwright (chromium-only); rebuilda el bundle vía pretest:e2e
```

> El gate de bundle gzip a 80 KB está cubierto por `scripts/check-bundle-size.mjs`. El bundle está en ~13.75 KB gzip.

---

## Cobertura

### Requisitos

`pest --coverage` requiere **PCOV** o **Xdebug** habilitado en el binario de PHP que ejecuta los tests:

```bash
# Verificar:
php -m | grep -E "pcov|xdebug"

# Si ninguno aparece, instala uno:
pecl install pcov     # opción ligera, recomendada para CI
# o habilita Xdebug en la directiva `zend_extension` de php.ini
```

Sin extension de cobertura, `pest --coverage` aborta con un error explícito. La suite normal (`composer test`) sigue funcionando.

### Reportes generados

`phpunit.xml` declara tres reportes en `<coverage>`:

| Formato | Ruta | Uso |
|---|---|---|
| Clover XML | `build/coverage/clover.xml` | input para herramientas de CI (Codecov, Coveralls). |
| HTML | `build/coverage/html/index.html` | exploración manual con drill-down por archivo/línea. |
| Texto plano (sólo summary) | `build/coverage/coverage.txt` | snapshot legible en logs de CI. |

`build/` ya está en `.gitignore` (artefacto de tests).

### Gate por path

PHPUnit 11 y Pest 3 NO soportan thresholds por path nativamente — `--min=N` se aplica al total agregado. La estrategia del paquete:

1. **Gate global**: `composer test:coverage-min` ejecuta `pest --coverage --min=75`. Falla si la cobertura agregada cae bajo 75%.
2. **Verificación por path**: tras correr `pest --coverage`, inspeccionar `build/coverage/html/index.html` o filtrar el reporte de texto. Cada uno de los tres paths críticos debe estar ≥75%.

```bash
# Atajo: extraer % por path del clover.xml
php -r '
  $xml = simplexml_load_file("build/coverage/clover.xml");
  foreach ($xml->project->package as $pkg) {
    $name = (string) $pkg["name"];
    $cov  = $pkg->metrics["coveredstatements"] / max(1, (int) $pkg->metrics["statements"]);
    printf("%-30s %5.1f%%\n", $name, $cov * 100);
  }
'
```

Cuando el CI esté wired, este chequeo puede automatizarse con un step que falla si alguno de los paths críticos cae bajo el umbral. La forma más portable hoy es una asserción en un test dedicado (no incluido en v1 — sería ruido en local sin extension de cobertura).

### Paths excluidos del cómputo

`phpunit.xml` excluye explícitamente:

- **`src/ChatbotServiceProvider.php`** — boot wiring con ramas que dependen de detección de paquetes opcionales en runtime (Spatie, Backpack, Relay). Su contenido se valida vía `ServiceProviderBootTest` (resolución de Authorizer/ScopeResolver, no-bind de TenantResolver, config shape) y vía los tests de integración (`McpToolBridgeTest`, `BackpackIntegrationTest`). Incluirlo en el cómputo infla el denominador con ramas que sólo viven en el host.
- **`src/Console/Commands/stubs/`** — plantillas `.stub` que el comando `chatbot:install` copia al host. No son código ejecutable.

---

## Estructura de la suite

```
tests/
├── Pest.php              — bootstrap (pest()->extend(TestCase::class))
├── TestCase.php          — Orchestra Testbench + ChatbotServiceProvider + PrismServiceProvider + SQLite in-memory
├── Stubs/
│   ├── Tools/            — backend tools de prueba (Public, Permissioned, StrictArgs, TeamScoped, TenantScoped, ...)
│   ├── Backpack/         — fakes para BackpackPageContextProvider
│   └── Mcp/              — fakes para el bridge MCP
├── Unit/                 — sin Laravel/HTTP, prueba clases puras
│   ├── Authorization/    — GateAuthorizer, NullScopeResolver, AuthorizesToolAccess
│   ├── Events/           — ToolInvoked
│   ├── Llm/              — SystemPromptBuilder, LlmException
│   ├── Mcp/              — McpBackendTool
│   ├── Services/         — PageContextSanitizer
│   ├── Sse/              — SseEvent
│   └── Tools/            — ToolResult, ToolContext, ConfirmationLevel, JsonSchemaToRules, PrismToolFactory
└── Feature/              — Testbench completo, migraciones, HTTP, eventos
    ├── Console/          — comandos artisan (Install, MakeTool, ...)
    ├── Http/             — controllers + middlewares (ChatController, ConversationController, ...)
    ├── Integrations/     — Backpack
    ├── Llm/              — LlmGateway con Prism::fake()
    ├── Mcp/              — McpToolBridge
    ├── Services/         — ChatService, PendingActionStore, FrontendActionMerge, ConfirmationDoD
    └── Tools/            — BaseBackendTool, ToolRegistry, primitivas frontend (incluida DownloadFileTool)
```

Convenciones de Pest 3:

- `it('does X', ...)` para casos de comportamiento (preferido).
- `test('case', ...)` cuando el subject no es comportamiento sino dato (ej. shape de constantes).
- `beforeEach` por archivo cuando el setup compartido > 3 LOC.
- Stubs PHP en `tests/Stubs/` con namespace `Rnkr69\LaraChatbot\Tests\Stubs\…` (registrado en `composer.json` autoload-dev).

---

## Cobertura cross-host

| Gap | Test principal | Coberturas |
|---|---|---|
| `TenantResolver` | `tests/Feature/Tools/BaseBackendToolTest.php` + `tests/Feature/Tools/ToolRegistryTest.php` + `tests/Unit/Authorization/AuthorizesToolAccessTest.php` | binding presente/ausente, `tenantScope=true` exige binding, `whereIn(tenant_column)` aplicado correctamente, salida `out_of_scope` cuando lista vacía. |
| `DownloadFileTool` | `tests/Feature/Tools/Frontend/DownloadFileToolTest.php` | URL signing, fail-secure por defecto, allowed_disks, ownership override (`assertCanDownload`), clamp `expires_in`, rejection de http/https. |
| Evento `ToolInvoked` | `tests/Unit/Events/ToolInvokedTest.php` + 3 dispatch en `tests/Feature/Services/ChatServiceTest.php` | propiedades readonly, dispatch en backend / frontend / cascada negada. |
| `system_prompt_addendum` + i18n | `tests/Unit/Llm/SystemPromptBuilderTest.php` (16 tests) | sección programática, override del host, fallback a `app()->getLocale()`, sanitización de `## Current page`. |
| Patrón bulk | docs (`docs/backend-tools.es.md`) | descrito como patrón, no requiere cambio de contrato. Tests host-side. |
| Backpack | `tests/Feature/Integrations/BackpackIntegrationTest.php` + `BackpackProviderShapeTest.php` | opt-in via `class_exists`, directive Blade `@chatbotBackpackContext`, shape del provider (entity/selected_ids). |

---

## Cobertura diferida al backlog

Tres decisiones tácticas:

### 1. `SpatieAuthorizer` + `ChatbotServiceProvider::verifyAuthorizationConfig`

**Diferido a v1.1.** El paquete no declara `spatie/laravel-permission` en `require-dev` (no añade peso a la matriz CI). Ambos paths sólo se ejecutan cuando Spatie está cargado en el host:

- `SpatieAuthorizer::__construct` lanza `RuntimeException` si Spatie ausente.
- `verifyAuthorizationConfig` adelanta esa comprobación al boot del SP.

**Estrategia para v1.1**: añadir Spatie a `require-dev` cuando se decida activarlo en CI, escribir 3-4 tests que validen el path "Spatie presente → check con `$user->can()` itera AND" + el path "Spatie ausente + resolver=spatie → RuntimeException claro". El algoritmo de iteración (idéntico a `GateAuthorizer`) ya está cubierto vía `tests/Unit/Authorization/GateAuthorizerTest.php`, así que el coste real es validar el wiring, no el algoritmo.

### 2. `LlmGateway` → `LlmException` translation

**Diferido a v1.1.** Los 7 tests de `LlmGatewayTest` cubren chat / tool call / stream / overrides / fallback / system prompt builder / tools forwarding contra `Prism::fake()` en happy path. La traducción de errores del provider a `LlmException` no está aserda directamente; la ramificación de error existe en el código pero su validación E2E requiere o bien un Prism::fake con response de error programada, o bien un mock más profundo del `PendingRequest`. El comando `chatbot:test-connection` es la primera línea de defensa práctica y está cubierto por `TestConnectionCommandTest`.

### 3. Backpack: ejemplos avanzados

**Ya en backlog v1.1.** La integración base está cubierta; los ejemplos bulk en grids y drill-down de KPIs viven en `docs/integrations/backpack.es.md`.

---

## Añadir tests al integrar tools del host

Cuando un host registra sus propias backend tools en `app/Chatbot/Tools/`, conviene replicar la misma estructura:

```
tests/Unit/Chatbot/Tools/                  # tests aislados de cada tool
└── ListMyInvoicesToolTest.php

tests/Feature/Chatbot/                     # tests de integración (HTTP, eventos)
└── InvoiceFlowTest.php
```

Patrones recomendados:

- **Args inválidos** → `ToolResult::error('validation', ...)` sin invocar `handle`. Cubierto por `BaseBackendTool::execute`.
- **Permission denied** → `Gate::define('foo', fn () => false)` y assertion sobre `errorCategory === 'unauthorized'`.
- **Scope** → registrar un `FixedScopeResolver` (stub en este paquete, copiable al host) y verificar que `accessibleQuery()` aplica `whereIn(user_id)` correctamente con `->toSql()`.
- **TenantScope** → mismo patrón con `FixedTenantResolver`. Recordar que el paquete sólo registra el binding si `chatbot.authorization.tenant_resolver` está poblado (no instanciar a mano un `NullTenantResolver`).
- **`Event::fake([ToolInvoked::class])`** + `Event::assertDispatched` para auditar telemetría.
- **`Prism::fake([...])`** para evitar contactar el LLM real en tests.

> El comando `php artisan chatbot:make:tool MyTool --type=read` genera el esqueleto de la tool. Considera generar a su vez un test mínimo en `tests/Unit/Chatbot/Tools/MyToolTest.php`.

---

## Cuando un test falla en CI

1. **`Pest::fake()` se queja** — añadir el evento/cola/storage a la sección `pest.php` `uses(TestCase::class, ...)` o usar el helper concreto (`Event::fake()` por test).
2. **`SQLSTATE[HY000]: General error: 1 no such table`** — añadir `$this->artisan('migrate')->run()` en el `beforeEach` o usar el trait `RefreshDatabase` (no aplicable aquí porque Testbench usa SQLite in-memory; las migraciones del paquete se cargan por el SP en boot, pero con tablas dinámicas hay que correr `migrate` explícito).
3. **Bundle gzip > 80 KB** — `npm run build:check` reporta el delta. Revisar imports recientes y considerar mover utilidades grandes a un módulo opt-in (cargado vía `registerBlockRenderer`/`registerTool`).
4. **Playwright timeout** — el `pretest:e2e` rebuilda el bundle; si falla es típicamente porque el servidor de fixtures (`scripts/e2e-server.mjs`) no levantó. Verificar que el puerto no está ocupado.
